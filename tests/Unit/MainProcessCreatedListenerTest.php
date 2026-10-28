<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessManagerInterface;
use SuperKernel\Process\Listener\MainProcessCreatedListener;
use SuperKernel\Process\Manager\ProcessManager;
use SuperKernel\Process\Provider\ProcessManagerProvider;
use SuperKernel\Process\Tests\Fixture\ConfiguredProcess;
use SuperKernel\Process\Tests\Fixture\SwooleConfig;
use SuperKernel\Swoole\Event\MainProcessCreated;
use SuperKernel\Swoole\MainProcess;
use Throwable;

use function file_get_contents;
use function getmypid;
use function json_decode;
use function pcntl_waitpid;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MIN;
use const SWOOLE_IPC_UNIXSOCK;
use const WNOHANG;

final class MainProcessCreatedListenerTest extends TestCase
{
    /** @return iterable<string, array{Process, bool, bool}> */
    public static function configurations(): iterable
    {
        foreach ([
            false,
            true,
        ] as $preference) {
            $suffix = $preference ? 'true' : 'false';
            yield 'omitted/' . $suffix => [
                new Process(),
                $preference,
                $preference,
            ];
            yield 'null/' . $suffix => [
                new Process(enableCoroutine: null),
                $preference,
                $preference,
            ];
            yield 'override/' . $suffix => [
                new Process(enableCoroutine: !$preference),
                $preference,
                !$preference,
            ];
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('configurations')]
    public function testCallbackStartsTheRegisteredPoolWorker(
        Process $attribute,
        bool    $preference,
        bool    $coroutine,
    ): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sk-pool-options-');
        $task = new ConfiguredProcess($log, $preference);
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            ConfiguredProcess::class => [
                new AttributeMetadata($attribute),
            ],
        ]);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($task);
        $registered = new ProcessManagerProvider()($annotations, $container);
        $entry = $registered->get(ConfiguredProcess::class);
        $listener = new MainProcessCreatedListener($registered);
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        try {
            self::assertSame(PHP_INT_MIN, new ReflectionClass($listener)
                ->getAttributes(Listener::class)[0]->newInstance()->priority);
            self::assertSame([
                MainProcessCreated::class,
            ], $listener->listen());
            $listener->process(new MainProcessCreated($main));
            self::assertSame('', file_get_contents($log));

            $main->start();

            $result = json_decode(file_get_contents($log), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotSame(getmypid(), $result['pid']);
            self::assertNotSame(getmypid(), $result['parent']);
            self::assertSame($result['parent'], $result['masterPid']);
            self::assertSame(0, $result['workerId']);
            self::assertSame($coroutine, $result['coroutine']);
            self::assertSame($preference, $task->enableCoroutine);
            self::assertSame($coroutine, $entry->enableCoroutine);
            self::assertSame($entry, $registered->get(ConfiguredProcess::class));
            self::assertSame(-1, pcntl_waitpid($result['pid'], $status, WNOHANG));
            self::assertSame(-1, pcntl_waitpid($result['masterPid'], $status, WNOHANG));
        } finally {
            unlink($log);
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testListenerCopiesRegisteredWorkAndIpcSettingsIntoThePoolParent(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sk-same-pool-manager-');
        $task = new ConfiguredProcess($log, false);
        $manager = new ProcessManager();
        $manager->setIPCType(SWOOLE_IPC_UNIXSOCK);
        $manager->add($task, name: 'configured');
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        new MainProcessCreatedListener($manager)->process(new MainProcessCreated($main));
        try {
            self::assertSame('', file_get_contents($log));
            $main->start();

            $result = json_decode(file_get_contents($log), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotSame(getmypid(), $result['masterPid']);
            self::assertSame($task, $manager->get('configured'));
            self::assertSame(SWOOLE_IPC_UNIXSOCK, $manager->getIPCType());
            self::assertGreaterThan(0, $result['pipe']);
        } finally {
            unlink($log);
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testEmptyRegistryDoesNotMountAChildOrCreateDaemonResources(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sk-listener-empty-');
        unlink($path);
        $manager = $this->createMock(ProcessManagerInterface::class);
        $manager->expects(self::once())->method('all')->willReturn([]);
        $manager->expects(self::never())->method('start');
        $manager->expects(self::never())->method('getIPCType');
        $config = new SwooleConfig(daemon: true, pidFile: $path);
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), $config);
        new MainProcessCreatedListener($manager)->process(new MainProcessCreated($main));

        $main->start();

        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.log');
    }
}
