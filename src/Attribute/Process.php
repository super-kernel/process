<?php

declare(strict_types=1);

namespace SuperKernel\Process\Attribute;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Process
{
    /**
     * @param string|null $name            null uses the attributed class's fully qualified name
     * @param bool|null   $enableCoroutine null inherits the task's coroutine preference; booleans override it
     *
     * @throws InvalidArgumentException if the declared process count is outside 0-1024
     */
    public function __construct(
        public ?string $name = null,
        public int     $workNums = 1,
        public ?bool   $enableCoroutine = null,
    )
    {
        if (0 > $workNums || 1024 < $workNums) {
            throw new InvalidArgumentException('Process count must be between 0 and 1024.');
        }
    }
}
