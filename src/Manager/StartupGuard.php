<?php

declare(strict_types=1);

namespace SuperKernel\Process\Manager;

use LogicException;

use function getmypid;

/**
 * Restricts manager configuration and startup to its owning process before the first start attempt.
 *
 * @internal
 */
final class StartupGuard
{
    /**
     * @param int  $owner   PID of the creating process, refreshed after the parent startup hook
     * @param bool $started Whether the manager's first start attempt has begun
     *
     * @throws LogicException When the current process is not the owner or startup has already been attempted
     */
    public static function assertMutable(int $owner, bool $started): void
    {
        if ($owner !== getmypid()) {
            throw new LogicException('Only the creating process may configure or start this manager.');
        }
        if ($started) {
            throw new LogicException('The manager may only start once; registrations and settings are now closed.');
        }
    }
}
