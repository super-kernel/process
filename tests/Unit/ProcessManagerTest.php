<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SuperKernel\Process\Contract\ProcessManagerInterface;
use SuperKernel\Process\Definition\ProcessDefinition;
use SuperKernel\Process\Exception\ProcessNotFoundException;
use SuperKernel\Process\Manager\ProcessManager;
use Swoole\Process\Manager;
use Swoole\Process\Pool;
use Throwable;

use function str_repeat;

use const SWOOLE_IPC_MSGQUEUE;
use const SWOOLE_IPC_NONE;
use const SWOOLE_IPC_SOCKET;
use const SWOOLE_IPC_UNIXSOCK;

final class ProcessManagerTest extends TestCase
{
    public function testContractCoversTheNativeManagersPublicOperations(): void
    {
        $contract = new ReflectionClass(ProcessManagerInterface::class);
        foreach (new ReflectionClass(Manager::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor()) {
                self::assertTrue($contract->hasMethod($method->getName()), $method->getName());
            }
        }
    }

    /** @throws ReflectionException When fixture setup or inspection fails */
    public function testConstructorConfiguresOnlyPoolIpc(): void
    {
        $constructor = new ReflectionClass(ProcessManager::class)->getConstructor();
        self::assertCount(2, $constructor->getParameters());
        self::assertSame('ipcType', $constructor->getParameters()[0]->getName());
        self::assertSame(SWOOLE_IPC_NONE, $constructor->getParameters()[0]->getDefaultValue());
        self::assertSame('msgQueueKey', $constructor->getParameters()[1]->getName());
        self::assertSame(0, $constructor->getParameters()[1]->getDefaultValue());
        $manager = new ProcessManager(SWOOLE_IPC_UNIXSOCK, 123);
        self::assertSame(SWOOLE_IPC_UNIXSOCK, $manager->getIPCType());
        self::assertSame(123, $manager->getMsgQueueKey());
    }

    public function testNamedQueriesRetainTaskIdentityAndRegistrationOrder(): void
    {
        $first = new ProcessDefinition(static function (Pool $pool, int $workerId): void {
        });
        $second = new ProcessDefinition(static function (Pool $pool, int $workerId): void {
        });
        $manager = new ProcessManager();
        self::assertSame($manager, $manager->add($first, name: '0')->add($second, name: '00'));
        self::assertSame([
            '0'  => $first,
            '00' => $second,
        ], $manager->all());
        self::assertTrue($manager->has('0'));
        self::assertSame($first, $manager->get('0'));
        self::assertSame($second, $manager->get('00'));
        self::assertFalse($manager->has('missing'));
        $this->expectException(ProcessNotFoundException::class);
        $this->expectExceptionMessageIs('Process not found: "missing".');
        $manager->get('missing');
    }

    public function testBatchNamesCallableDefaultsAndCoroutineOverrides(): void
    {
        $manager = new ProcessManager();
        $callback = static function (Pool $pool, int $workerId): void {
        };
        $manager->add($callback)->addBatch(2, $callback, true, 'jobs');
        self::assertFalse($manager->get('0')->enableCoroutine);
        self::assertTrue($manager->get('jobs:0')->enableCoroutine);
        self::assertSame($manager->get('jobs:0'), $manager->get('jobs:1'));
        $task = new ProcessDefinition($callback, true);
        $manager->add($task, name: 'unchanged')->add($task, false, 'overridden');
        self::assertSame($task, $manager->get('unchanged'));
        self::assertFalse($manager->get('overridden')->enableCoroutine);
        self::assertTrue($task->enableCoroutine);
        self::assertNotSame($task, $manager->get('overridden'));
    }

    public function testBatchCollisionDoesNotPartiallyRegisterOrReplaceTasks(): void
    {
        $task = new ProcessDefinition(static function (Pool $pool, int $workerId): void {
        });
        $manager = new ProcessManager();
        $manager->add($task, name: 'jobs:1');
        $this->expectException(LogicException::class);
        try {
            $manager->addBatch(2, $task, name: 'jobs');
        } finally {
            self::assertSame([
                'jobs:1' => $task,
            ], $manager->all());
        }
    }

    public function testInheritedTasksRetainTheCapacityLimitWithoutPartialRegistration(): void
    {
        $task = new ProcessDefinition(static function (Pool $pool, int $workerId): void {
        }, true);
        $manager = new ProcessManager();
        $manager->addBatch(1024, $task, name: 'jobs');
        self::assertCount(1024, $manager->all());
        self::assertSame($task, $manager->get('jobs:1023'));
        $this->expectException(LogicException::class);
        try {
            $manager->add($task, name: 'overflow');
        } finally {
            self::assertCount(1024, $manager->all());
            self::assertFalse($manager->has('overflow'));
        }
    }

    public function testDisabledTasksDoNotReserveNamesOrWorkerIds(): void
    {
        $callback = static function (Pool $pool, int $workerId): void {
        };
        $disabled = new ProcessDefinition($callback, enabled: false);
        $manager = new ProcessManager();
        self::assertSame($manager, $manager->add($disabled, true, 'active'));
        self::assertSame($manager, $manager->addBatch(2, $disabled));
        $manager->add($callback, name: 'active')->add($callback);

        self::assertFalse($manager->has('0'));
        self::assertTrue($manager->has('1'));
        self::assertCount(2, $manager->all());
        self::assertTrue($manager->get('active')->enabled);
        self::assertTrue($manager->get('1')->enabled);
    }

    public function testFullClassNamesAreRetainedWithoutTruncation(): void
    {
        $name = str_repeat('Namespace\\', 12) . 'ProcessTask';
        $task = new ProcessDefinition(static function (Pool $pool, int $workerId): void {
        });
        $manager = new ProcessManager();
        $manager->addBatch(2, $task, name: $name);

        self::assertSame([
            $name . ':0' => $task,
            $name . ':1' => $task,
        ], $manager->all());
        self::assertSame($task, $manager->get($name . ':1'));
    }

    public function testEmptyExplicitNamesAreRejectedForBatches(): void
    {
        $manager = new ProcessManager();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Process names must not be empty.');
        $manager->addBatch(2, static function (Pool $pool, int $workerId): void {
        }, name: '');
    }

    public function testZeroBatchIsEmptyAndNegativeCountsAreRejected(): void
    {
        $manager = new ProcessManager();
        $callback = static function (Pool $pool, int $workerId): void {
        };
        self::assertSame($manager, $manager->addBatch(0, $callback));
        self::assertSame([], $manager->all());
        $this->expectException(InvalidArgumentException::class);
        $manager->addBatch(-1, $callback);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPoolSettingsAreFluentAndCloseOnStartup(): void
    {
        $manager = new ProcessManager();
        self::assertSame(SWOOLE_IPC_NONE, $manager->getIPCType());
        self::assertSame(0, $manager->getMsgQueueKey());
        self::assertSame($manager, $manager->setIPCType(SWOOLE_IPC_MSGQUEUE)->setMsgQueueKey(1234));
        self::assertSame(SWOOLE_IPC_MSGQUEUE, $manager->getIPCType());
        self::assertSame(1234, $manager->getMsgQueueKey());
        $manager->setIPCType(SWOOLE_IPC_UNIXSOCK);
        $manager->start();
        $this->expectException(LogicException::class);
        $manager->setMsgQueueKey(0);
    }

    public function testSocketIpcCannotStartWithoutAnUnexposedListenBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProcessManager()->setIPCType(SWOOLE_IPC_SOCKET);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testEmptyManagerDoesNotRunBoundaryHooksAndOnlyStartsOnce(): void
    {
        $manager = new ProcessManager();
        $manager->start(static function (): void {
            self::fail('Empty managers must not create runtime resources.');
        });
        $this->expectException(LogicException::class);
        $manager->start();
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRegistrationClosesEvenAfterEmptyStartup(): void
    {
        $manager = new ProcessManager();
        $manager->start();
        $this->expectException(LogicException::class);
        $manager->add(static function (Pool $pool, int $workerId): void {
        });
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testStopBeforeStartPreventsExecution(): void
    {
        $manager = new ProcessManager();
        $manager->add(static function (): void {
            self::fail('Cancelled task must not run.');
        }, name: 'cancelled');
        $manager->stop();
        $manager->stop();
        $manager->start(static function (): void {
            self::fail('Cancelled manager must not invoke its boundary.');
        });
        self::assertTrue($manager->has('cancelled'));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testStartupHookCanCancelBeforeForking(): void
    {
        $manager = new ProcessManager();
        $manager->add(static function (): void {
            self::fail('Cancelled task must not run.');
        }, name: 'cancelled');
        $manager->start($manager->stop(...));
        self::assertTrue($manager->has('cancelled'));
    }
}
