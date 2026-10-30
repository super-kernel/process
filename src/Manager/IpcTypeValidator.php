<?php

declare(strict_types=1);

namespace SuperKernel\Process\Manager;

use InvalidArgumentException;

use function in_array;

use const SWOOLE_IPC_MSGQUEUE;
use const SWOOLE_IPC_NONE;
use const SWOOLE_IPC_UNIXSOCK;

/**
 * Accepts only the Pool IPC modes that start without a pre-start listen() boundary.
 *
 * @internal
 */
final class IpcTypeValidator
{
    /**
     * @throws InvalidArgumentException When the mode is not SWOOLE_IPC_NONE, SWOOLE_IPC_UNIXSOCK or SWOOLE_IPC_MSGQUEUE
     */
    public static function assertSupported(int $ipcType): void
    {
        if (!in_array($ipcType, [
            SWOOLE_IPC_NONE,
            SWOOLE_IPC_UNIXSOCK,
            SWOOLE_IPC_MSGQUEUE,
        ], true)) {
            throw new InvalidArgumentException(
                'Worker IPC must be disabled or use Unix domain sockets or a System V message queue.'
                . ' Network sockets require listen().',
            );
        }
    }
}
