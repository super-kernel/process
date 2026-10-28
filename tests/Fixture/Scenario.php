<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Fixture;

use Psr\Log\NullLogger;
use RuntimeException;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Process\Listener\MainProcessCreatedListener;
use SuperKernel\Process\Manager\ProcessManager;
use SuperKernel\Swoole\Event\MainProcessCreated;
use SuperKernel\Swoole\MainProcess;
use Swoole\Atomic;
use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Process\Pool;
use Throwable;

use function array_keys;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function hrtime;
use function in_array;
use function is_file;
use function json_encode;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_waitpid;
use function posix_getppid;
use function posix_kill;
use function str_contains;
use function str_starts_with;
use function usleep;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const SIGINT;
use const SIGTERM;
use const SWOOLE_IPC_MSGQUEUE;
use const SWOOLE_IPC_UNIXSOCK;
use const WNOHANG;

final class Scenario
{
    private static string $directory;

    /**
     * The atomic counter must be allocated before forking so replacement workers share its state.
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     *
     * @noinspection PhpVariableIsUsedOnlyInClosureInspection
     */
    public static function run(string $scenario, string $directory): void
    {
        self::$directory = $directory;
        self::record('launcher');
        $manager = new ProcessManager();
        $ready = new Atomic(0);
        $generation = new Atomic(0);
        if (str_starts_with($scenario, 'restart-')) {
            $manager->add(
                static function (Pool $pool, int $workerId) use ($generation, $scenario): void {
                    $attempt = $generation->add();
                    self::awaitMarker('sibling-ready');
                    self::record('attempt', [
                        'workerId'   => $workerId,
                        'generation' => $attempt,
                        'coroutine'  => 0 <= Coroutine::getCid(),
                    ]);
                    if (1 === $attempt) {
                        if (str_contains($scenario, 'throw')) {
                            throw new RuntimeException(
                                'Intentional worker failure; Pool must replace only this worker.',
                            );
                        }
                        return;
                    }
                    $pool->shutdown();
                },
                str_contains($scenario, 'coroutine'),
                'restarted',
            );
            $manager->add(static function (Pool $pool, int $workerId): void {
                self::cooperate($pool, $workerId, static function (): void {
                    file_put_contents(self::$directory . '/sibling-ready', 'ready');
                });
            }, name: 'sibling');
        } elseif ('nested-pool' === $scenario) {
            $manager->add(static function (Pool $pool, int $workerId): void {
                try {
                    new Pool(1);
                    throw new RuntimeException('Nested Pool was unexpectedly accepted.');
                } catch (Throwable $error) {
                    self::record('nested-rejected', [
                        'message'  => $error->getMessage(),
                        'workerId' => $workerId,
                    ]);
                }
                $pool->shutdown();
            });
        } elseif (in_array($scenario, [
            'ipc-unixsock',
            'ipc-msgqueue',
        ], true)) {
            $manager->setIPCType('ipc-unixsock' === $scenario ? SWOOLE_IPC_UNIXSOCK : SWOOLE_IPC_MSGQUEUE);
            $manager->setMsgQueueKey(0);
            $manager->addBatch(2, static function (Pool $pool, int $workerId) use ($scenario): void {
                $process = $pool->getProcess();
                if (0 === $workerId) {
                    if ('ipc-unixsock' === $scenario) {
                        $pool->getProcess(1)->write('pool-message');
                    } else {
                        $process->push('pool-message');
                    }
                    self::awaitMarker('message-received');
                } else {
                    $message = 'ipc-unixsock' === $scenario ? $process->read() : $process->pop();
                    self::record('message', [
                        'value' => $message,
                    ]);
                    file_put_contents(self::$directory . '/message-received', 'received');
                    $pool->shutdown();
                }
            }, name: 'ipc');
        } elseif ('empty' !== $scenario) {
            $callback = static function (Pool $pool, int $workerId) use ($ready, $scenario, $manager): void {
                self::cooperate($pool, $workerId, static function () use ($pool, $ready, $scenario, $manager): void {
                    if (2 === $ready->add()) {
                        if ('supervised-stop' === $scenario) {
                            file_put_contents(self::$directory . '/workers-ready', 'ready');
                        } elseif ('external-stop' === $scenario) {
                            posix_kill($pool->master_pid, SIGTERM);
                        } elseif ('manager-stop' === $scenario) {
                            $manager->stop();
                        } else {
                            $pool->shutdown();
                        }
                    }
                });
            };
            if ('mixed' === $scenario) {
                $manager->add($callback, false, 'synchronous')->add($callback, true, 'coroutine');
            } else {
                $manager->addBatch(2, $callback, name: 'batch');
            }
        }
        if ('supervised-stop' === $scenario) {
            $config = new SwooleConfig(pidFile: $directory . '/main.pid');
            $logger = new class() extends NullLogger implements StdoutLoggerInterface {};
            $main = new MainProcess($logger, $config);
            new MainProcessCreatedListener($manager)->process(new MainProcessCreated($main));
            $main->add(static function (): void {
                self::awaitMarker('workers-ready');
                self::record('supervised', [
                    'published' => (int) file_get_contents(self::$directory . '/main.pid'),
                ]);
                posix_kill(getmypid(), SIGINT);
            });
            $main->start();
        } else {
            $manager->start();
        }
        self::record('returned', [
            'names' => array_keys($manager->all()),
        ]);
        self::record('reaped', [
            'remainingChild' => pcntl_waitpid(-1, $status, WNOHANG),
        ]);
    }

    /**
     * @param callable(): void $ready
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private static function cooperate(Pool $pool, int $workerId, callable $ready): void
    {
        $stop = false;
        $handler = static function () use (&$stop): void {
            $stop = true;
        };
        $coroutine = 0 <= Coroutine::getCid();
        if ($coroutine) {
            Process::signal(SIGTERM, $handler);
        } else {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, $handler);
        }
        self::record('started', [
            'workerId'  => $workerId,
            'masterPid' => $pool->master_pid,
            'coroutine' => $coroutine,
        ]);
        $ready();
        $deadline = hrtime(true) + 3_000_000_000;
        while (!$stop && hrtime(true) < $deadline) {
            self::pause();
        }
        // Delay the last worker so native Pool's one-worker wait alone cannot satisfy this test.
        if (1 === $workerId) {
            if ($coroutine) {
                Coroutine::sleep(0.05);
            } else {
                usleep(50000);
            }
        }
        if ($coroutine) {
            Process::signal(SIGTERM);
        }
        self::record($stop ? 'stopped' : 'expired', [
            'workerId' => $workerId,
            'mainPid'  => is_file(self::$directory . '/main.pid')
                ? (int) file_get_contents(self::$directory . '/main.pid') : null,
        ]);
    }

    private static function awaitMarker(string $name): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        while (!is_file(self::$directory . '/' . $name)) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for a fixture worker.');
            }
            self::pause();
        }
    }

    private static function pause(): void
    {
        if (0 <= Coroutine::getCid()) {
            Coroutine::sleep(0.001);
        } else {
            usleep(1000);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private static function record(string $event, array $data = []): void
    {
        file_put_contents(self::$directory . '/events.jsonl', json_encode([
            'event'  => $event,
            'pid'    => getmypid(),
            'parent' => posix_getppid(),
            'time'   => hrtime(true),
            ...$data,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
}
