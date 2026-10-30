<?php

declare(strict_types=1);

namespace SuperKernel\Process\Manager;

use InvalidArgumentException;
use LogicException;
use SuperKernel\Process\Contract\ProcessInterface;
use SuperKernel\Process\Definition\ProcessDefinition;
use Swoole\Process\Pool;

use function array_fill_keys;
use function count;

/**
 * Validates one worker batch against a registry and names the entries of its effective task.
 *
 * @internal
 */
final class BatchRegistration
{
    /**
     * @param array<array-key, ProcessInterface>         $processes       current registry keyed by worker name
     * @param ProcessInterface|callable(Pool, int): void $process
     * @param bool|null                                  $enableCoroutine null inherits; callables default to false
     *
     * @return array<array-key, ProcessInterface> entries to append in worker-ID order, keyed by name; empty for
     *                                            zero workers or a disabled task
     *
     * @throws InvalidArgumentException When workerNum is outside 0-1024
     * @throws LogicException           When a name is empty or already registered, or capacity would exceed 1024
     */
    public static function entries(
        array                     $processes,
        int                       $workerNum,
        ProcessInterface|callable $process,
        ?bool                     $enableCoroutine,
        ?string                   $name,
    ): array
    {
        if (0 > $workerNum || 1024 < $workerNum) {
            throw new InvalidArgumentException('Worker count must be between 0 and 1024.');
        }
        if (0 === $workerNum || ($process instanceof ProcessInterface && !$process->enabled)) {
            return [];
        }
        $offset = count($processes);
        if (1024 < $offset + $workerNum) {
            throw new LogicException('At most 1024 workers may be registered.');
        }
        if ('' === $name) {
            throw new LogicException('Process names must not be empty.');
        }
        $names = [];
        for ($index = 0; $index < $workerNum; ++$index) {
            $workerName = null === $name ? (string) ($offset + $index)
                : $name . (1 === $workerNum ? '' : ':' . $index);
            if (isset($processes[$workerName])) {
                throw new LogicException('Duplicate process name: ' . $workerName . '.');
            }
            $names[] = $workerName;
        }
        if (!$process instanceof ProcessInterface) {
            $process = new ProcessDefinition($process(...), $enableCoroutine ?? false);
        } elseif (null !== $enableCoroutine && $enableCoroutine !== $process->enableCoroutine) {
            $process = new ProcessDefinition($process->execute(...), $enableCoroutine);
        }
        return array_fill_keys($names, $process);
    }
}
