<?php

declare(strict_types=1);

namespace SuperKernel\Process\Contract;

use Swoole\Process\Pool;
use Throwable;

/** The entry point, registration eligibility and coroutine preference of a Pool worker. */
interface ProcessInterface
{
    /** Read in the parent during registration; false excludes this task from the manager. */
    public bool $enabled { get; }

    /**
     * Requests Coroutine\run when the registration or annotation coroutine setting is omitted or null.
     * Explicit true/false settings override this preference without mutating the task.
     * The Pool itself remains synchronous.
     */
    public bool $enableCoroutine { get; }

    /**
     * Runs in a worker of the manager's single Pool, with its stable zero-based worker ID.
     * Initialize business resources here, after the fork. Creating another native Pool is prohibited.
     * Returning or throwing while the Pool is running causes worker replacement, including an empty callback.
     * Pool::shutdown() requests shutdown of every worker, not just this task.
     *
     * @throws Throwable on task failure; the native Pool replaces the exited worker
     */
    public function execute(Pool $pool, int $workerId): void;
}
