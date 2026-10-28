<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Fixture;

use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessInterface;
use Swoole\Coroutine;
use Swoole\Process\Pool;

use function file_put_contents;
use function getmypid;
use function json_encode;
use function posix_getppid;

use const JSON_THROW_ON_ERROR;

#[Process]
final class ConfiguredProcess implements ProcessInterface
{
    public bool $enabled { get => true; }

    public function __construct(private readonly string $log, public readonly bool $enableCoroutine)
    {
    }

    public function execute(Pool $pool, int $workerId): void
    {
        if (0 <= Coroutine::getCid()) {
            Coroutine::sleep(0.001);
        }
        file_put_contents($this->log, json_encode([
            'pid'       => getmypid(),
            'parent'    => posix_getppid(),
            'masterPid' => $pool->master_pid,
            'workerId'  => $workerId,
            'coroutine' => 0 <= Coroutine::getCid(),
            'pipe'      => $pool->getProcess()->pipe,
        ], JSON_THROW_ON_ERROR));
        $pool->shutdown();
    }
}
