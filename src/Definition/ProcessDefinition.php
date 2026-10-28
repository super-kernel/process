<?php

declare(strict_types=1);

namespace SuperKernel\Process\Definition;

use Closure;
use SuperKernel\Process\Contract\ProcessInterface;
use Swoole\Process\Pool;

/** Adapts a Pool worker callback or a task's coroutine override. */
final readonly class ProcessDefinition implements ProcessInterface
{
    /** @param Closure(Pool, int): void $callback */
    public function __construct(
        private Closure $callback,
        public bool     $enableCoroutine = false,
        public bool     $enabled = true,
    )
    {
    }

    public function execute(Pool $pool, int $workerId): void
    {
        ($this->callback)($pool, $workerId);
    }
}
