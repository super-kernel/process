<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Fixture;

use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessInterface;
use Swoole\Process\Pool;

#[Process('jobs', workNums: 2)]
final class AttributedHandler implements ProcessInterface
{
    public bool $enabled { get => true; }

    public bool $enableCoroutine { get => false; }

    public function execute(Pool $pool, int $workerId): void
    {
    }
}
