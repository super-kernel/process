<?php

declare(strict_types=1);

namespace SuperKernel\Process\Contract;

use Closure;
use InvalidArgumentException;
use LogicException;
use SuperKernel\Process\Exception\ProcessException;
use SuperKernel\Process\Exception\ProcessNotFoundException;
use Swoole\Process\Pool;
use Throwable;

/** Registers named workers and starts one Pool, following Swoole\Process\Manager's lifecycle. */
interface ProcessManagerInterface
{
    /**
     * Registers one worker without executing it; returns this manager for chaining.
     * Unnamed workers use their zero-based registration index as their name.
     * Callable tasks and explicit coroutine overrides are adapted to ProcessInterface.
     * Tasks with enabled=false are skipped. Names are nonempty strings and may be fully qualified class names.
     *
     * @param ProcessInterface|callable(Pool, int): void $process
     * @param bool|null                                  $enableCoroutine null inherits; callables default to false
     *
     * @return self this manager for chaining
     *
     * @throws LogicException if a name is empty/duplicated, registration is closed/full or the caller is forked
     */
    public function add(
        ProcessInterface|callable $process,
        ?bool                     $enableCoroutine = null,
        ?string                   $name = null,
    ): self;

    /**
     * Registers workerNum entries atomically. Zero is a no-op; a named batch uses name:0, name:1, etc.
     * A one-worker batch keeps name unchanged. Every entry maps to one worker in the same Pool.
     * Tasks with enabled=false add no entries and consume no worker IDs or capacity.
     *
     * @param ProcessInterface|callable(Pool, int): void $process
     * @param bool|null                                  $enableCoroutine null inherits; callables default to false
     *
     * @return self this manager after all entries have been registered
     *
     * @throws InvalidArgumentException if workerNum is outside 0-1024
     * @throws LogicException           for empty/duplicate names, a full/closed registry or a forked caller
     */
    public function addBatch(
        int                       $workerNum,
        ProcessInterface|callable $process,
        ?bool                     $enableCoroutine = null,
        ?string                   $name = null,
    ): self;

    /** @return array<array-key, ProcessInterface> registered tasks in worker-ID order, keyed by name */
    public function all(): array;

    /** @return bool whether the name is registered; this is not a worker-liveness check */
    public function has(string $name): bool;

    /**
     * @return ProcessInterface the same registered task on repeated lookup, including after worker replacement
     *
     * @throws ProcessNotFoundException if has($name) is false
     */
    public function get(string $name): ProcessInterface;

    /**
     * Configures IPC for the entire Pool; returns this manager.
     *
     * @return self this manager for chaining
     *
     * @throws InvalidArgumentException if the mode is not NONE, UNIXSOCK or MSGQUEUE
     * @throws LogicException           if startup has begun or the caller is a forked copy
     */
    public function setIPCType(int $ipcType): self;

    public function getIPCType(): int;

    /**
     * Sets the Pool's System V message-queue key; zero requests a private queue. Returns this manager.
     *
     * @return self this manager for chaining
     *
     * @throws LogicException if startup has begun or the caller is a forked copy
     */
    public function setMsgQueueKey(int $msgQueueKey): self;

    public function getMsgQueueKey(): int;

    /**
     * Starts one synchronous Pool and blocks until shutdown and final-worker reaping complete.
     * Pool replaces workers that return or fail while it is running; there is no finite-task or retry budget.
     * Each task may enter Coroutine\run independently. Tasks must not create nested Process\Pool instances.
     * Empty/canceled registries do not allocate a Pool or invoke hooks. Startup is allowed once, by the owner.
     *
     * @param Closure(): void|null $beforeStart Parent boundary before native start (e.g. daemonization)
     * @param Closure(): void|null $afterFork   Every worker generation, before execution (e.g. closing PID handles)
     *
     * @throws LogicException   on repeated startup or startup from a forked copy
     * @throws ProcessException on invalid runtime, native startup failure or incomplete cleanup
     * @throws Throwable        if preparation, native construction or a parent boundary fails
     */
    public function start(?Closure $beforeStart = null, ?Closure $afterFork = null): void;

    /**
     * Requests shutdown of the entire Pool, including when called from a worker.
     * Before start(), cancels startup; after completion, does nothing. Native SIGTERM also shuts down the Pool.
     *
     * @throws ProcessException if the active Pool rejects the shutdown request
     */
    public function stop(): void;
}
