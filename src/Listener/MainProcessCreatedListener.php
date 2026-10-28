<?php

declare(strict_types=1);

namespace SuperKernel\Process\Listener;

use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\Process\Contract\ProcessManagerInterface;
use SuperKernel\Process\Manager\ProcessManager;
use SuperKernel\Swoole\Event\MainProcessCreated;

use const PHP_INT_MIN;

/** Mounts an independent Pool parent beneath MainProcess after process registration. */
#[Listener(priority: PHP_INT_MIN)]
final readonly class MainProcessCreatedListener implements ListenerInterface
{
    public function __construct(private ProcessManagerInterface $processes)
    {
    }

    public function listen(): array
    {
        return [
            MainProcessCreated::class,
        ];
    }

    /** @psalm-param MainProcessCreated $event */
    public function process(object $event): void
    {
        $processes = $this->processes->all();
        if ([] === $processes) {
            return;
        }
        $ipcType = $this->processes->getIPCType();
        $msgQueueKey = $this->processes->getMsgQueueKey();
        $event->mainProcess->addProcess(static function () use ($processes, $ipcType, $msgQueueKey): void {
            $manager = new ProcessManager(ipcType: $ipcType, msgQueueKey: $msgQueueKey);
            foreach ($processes as $name => $process) {
                $manager->add($process, name: (string) $name);
            }
            $manager->start();
        });
    }
}
