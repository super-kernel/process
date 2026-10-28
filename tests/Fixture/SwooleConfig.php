<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Fixture;

use SuperKernel\Swoole\Config\SwooleConfigInterface;

final readonly class SwooleConfig implements SwooleConfigInterface
{
    public function __construct(private bool $daemon = false, private ?string $pidFile = null)
    {
    }

    public function daemon(): bool
    {
        return $this->daemon;
    }

    public function pidFile(): ?string
    {
        return $this->pidFile;
    }

    public function displayErrors(): bool
    {
        return true;
    }

    public function socketBufferSize(): int
    {
        return 8388608;
    }

    public function enablePreemptiveScheduler(): bool
    {
        return false;
    }
}
