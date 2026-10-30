<?php

declare(strict_types=1);

namespace SuperKernel\Process\Manager;

use Closure;
use SuperKernel\Process\Contract\ProcessInterface;
use SuperKernel\Process\Contract\ProcessManagerInterface;
use SuperKernel\Process\Exception\ProcessException;
use SuperKernel\Process\Exception\ProcessNotFoundException;
use Swoole\Constant;
use Swoole\Coroutine;
use Swoole\Exception as SwooleException;
use Swoole\Process\Pool;

use function count;
use function getmypid;
use function pcntl_get_last_error;
use function pcntl_waitpid;
use function Swoole\Coroutine\run;

use const PCNTL_ECHILD;
use const PCNTL_EINTR;
use const PHP_SAPI;
use const SWOOLE_IPC_NONE;

/** Maps registered tasks to one native Pool; Pool owns worker replacement and shutdown. */
final class ProcessManager implements ProcessManagerInterface
{
    /** @var array<array-key, ProcessInterface> */
    private array $processes = [];
    private ?Pool $pool = null;
    private int $owner;
    private int $ipcType;
    private int $msgQueueKey;
    private bool $started = false;
    private bool $running = false;
    private bool $stopping = false;

    public function __construct(int $ipcType = SWOOLE_IPC_NONE, int $msgQueueKey = 0)
    {
        $this->owner = getmypid();
        StartupGuard::assertMutable($this->owner, $this->started);
        IpcTypeValidator::assertSupported($ipcType);
        $this->ipcType = $ipcType;
        $this->msgQueueKey = $msgQueueKey;
    }

    public function add(
        ProcessInterface|callable $process,
        ?bool                     $enableCoroutine = null,
        ?string                   $name = null,
    ): self
    {
        StartupGuard::assertMutable($this->owner, $this->started);
        $this->processes += BatchRegistration::entries($this->processes, 1, $process, $enableCoroutine, $name);
        return $this;
    }

    public function addBatch(
        int                       $workerNum,
        ProcessInterface|callable $process,
        ?bool                     $enableCoroutine = null,
        ?string                   $name = null,
    ): self
    {
        StartupGuard::assertMutable($this->owner, $this->started);
        $this->processes += BatchRegistration::entries($this->processes, $workerNum, $process, $enableCoroutine, $name);
        return $this;
    }

    public function all(): array
    {
        return $this->processes;
    }

    public function has(string $name): bool
    {
        return isset($this->processes[$name]);
    }

    public function get(string $name): ProcessInterface
    {
        return $this->processes[$name]
            ?? throw new ProcessNotFoundException('Process not found: "' . $name . '".');
    }

    public function setIPCType(int $ipcType): self
    {
        StartupGuard::assertMutable($this->owner, $this->started);
        IpcTypeValidator::assertSupported($ipcType);
        $this->ipcType = $ipcType;
        return $this;
    }

    public function getIPCType(): int
    {
        return $this->ipcType;
    }

    public function setMsgQueueKey(int $msgQueueKey): self
    {
        StartupGuard::assertMutable($this->owner, $this->started);
        $this->msgQueueKey = $msgQueueKey;
        return $this;
    }

    public function getMsgQueueKey(): int
    {
        return $this->msgQueueKey;
    }

    public function start(?Closure $beforeStart = null, ?Closure $afterFork = null): void
    {
        StartupGuard::assertMutable($this->owner, $this->started);
        $this->started = true;
        if ([] === $this->processes || $this->stopping) {
            return;
        }
        $stats = Coroutine::stats();
        if ('cli' !== PHP_SAPI || 0 <= Coroutine::getCid() || 0 < $stats['event_num']
            || 0 < $stats['coroutine_num'] || 0 < $stats['aio_worker_num']) {
            throw new ProcessException('Start the Pool in a clean CLI before reactors or asynchronous work.');
        }
        /** @var list<array{Closure(Pool, int): void, bool}> $startFuncMap */
        $startFuncMap = [];
        foreach ($this->processes as $process) {
            $startFuncMap[] = [
                $process->execute(...),
                $process->enableCoroutine,
            ];
        }
        $this->pool = new Pool(count($startFuncMap), $this->ipcType, $this->msgQueueKey, false);
        try {
            $this->pool->on(Constant::EVENT_WORKER_START, static function (Pool $pool, int $workerId) use (
                $startFuncMap,
                $afterFork,
            ): void {
                if (null !== $afterFork) {
                    $afterFork();
                }
                [
                    $callback,
                    $enableCoroutine,
                ] = $startFuncMap[$workerId];
                if ($enableCoroutine) {
                    run($callback, $pool, $workerId);
                } else {
                    $callback($pool, $workerId);
                }
            });
            if (null !== $beforeStart) {
                $beforeStart();
                $this->owner = getmypid();
            }
            // The parent hook may cancel startup after preparation, including across a daemon boundary.
            /** @noinspection PhpConditionAlreadyCheckedInspection The hook may call stop() and set $this->stopping. */
            if ($this->stopping) {
                return;
            }
            $this->running = true;
            if (false === $this->pool->start()) {
                throw new ProcessException('Unable to start the process Pool.');
            }
            // Pool owns this process's child wait loop. Swoole 6.2.3 may leave final workers awaiting reaping.
            while (true) {
                $pid = pcntl_waitpid(-1, $status);
                if (0 < $pid || (-1 === $pid && PCNTL_EINTR === pcntl_get_last_error())) {
                    continue;
                }
                if (-1 === $pid && PCNTL_ECHILD === pcntl_get_last_error()) {
                    return;
                }
                throw new ProcessException('Unable to reap the remaining Pool workers.');
            }
        } finally {
            $this->running = false;
            $this->pool = null;
        }
    }

    public function stop(): void
    {
        $this->stopping = true;
        if ($this->running && null !== $this->pool) {
            try {
                if (!$this->pool->shutdown()) {
                    throw new ProcessException('Unable to request Pool shutdown.');
                }
            } catch (SwooleException $error) {
                throw new ProcessException('Unable to request Pool shutdown.', 0, $error);
            }
        }
    }
}
