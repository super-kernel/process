<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use stdClass;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessInterface;
use SuperKernel\Process\Contract\ProcessManagerInterface;
use SuperKernel\Process\Definition\ProcessDefinition;
use SuperKernel\Process\Manager\ProcessManager;
use SuperKernel\Process\Provider\ProcessManagerProvider;
use SuperKernel\Process\Tests\Fixture\AttributedHandler;
use SuperKernel\Process\Tests\Fixture\ConfiguredProcess;
use SuperKernel\Process\Tests\Fixture\InvalidProcess;
use Swoole\Process\Pool;
use Throwable;

final class ProcessManagerProviderTest extends TestCase
{
    /** @return iterable<string, array{Process, bool, bool}> */
    public static function coroutineSettings(): iterable
    {
        foreach ([
            false,
            true,
        ] as $preference) {
            $suffix = $preference ? 'true' : 'false';
            yield 'omitted/' . $suffix => [
                new Process('jobs', 2),
                $preference,
                $preference,
            ];
            yield 'null/' . $suffix => [
                new Process('jobs', 2, null),
                $preference,
                $preference,
            ];
            yield 'true/' . $suffix => [
                new Process('jobs', 2, true),
                $preference,
                true,
            ];
            yield 'false/' . $suffix => [
                new Process('jobs', 2, false),
                $preference,
                false,
            ];
        }
    }

    /** @throws Throwable When fixture setup or task delegation fails */
    #[DataProvider('coroutineSettings')]
    public function testCoroutineInheritanceAndOverridesMatchDirectRegistration(
        Process $attribute,
        bool    $preference,
        bool    $expected,
    ): void
    {
        $task = new class($preference) implements ProcessInterface {
            public int $coroutineReads = 0;
            public int $executions = 0;
            public ?Pool $pool = null;
            public ?int $workerId = null;
            public bool $enabled { get => true; }
            public bool $enableCoroutine {
                get {
                    ++$this->coroutineReads;
                    return $this->preference;
                }
            }

            public function __construct(private readonly bool $preference)
            {
            }

            public function execute(Pool $pool, int $workerId): void
            {
                ++$this->executions;
                $this->pool = $pool;
                $this->workerId = $workerId;
            }
        };
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            AttributedHandler::class => [
                new AttributeMetadata($attribute),
            ],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(AttributedHandler::class)->willReturn($task);

        $manager = new ProcessManagerProvider()($annotations, $container);

        self::assertSame(null === $attribute->enableCoroutine ? 0 : 1, $task->coroutineReads);
        self::assertSame(0, $task->executions);
        self::assertNull($task->pool);
        $entry = $manager->get('jobs:0');
        self::assertSame([
            'jobs:0' => $entry,
            'jobs:1' => $entry,
        ], $manager->all());
        self::assertSame($expected, $entry->enableCoroutine);
        self::assertSame($preference === $expected, $task === $entry);
        self::assertSame($preference, $task->enableCoroutine);

        $direct = new ProcessManager();
        $direct->add($task, $attribute->enableCoroutine, 'single');
        $direct->addBatch(2, $task, $attribute->enableCoroutine, 'batch');
        foreach ($direct->all() as $registered) {
            self::assertSame($expected, $registered->enableCoroutine);
            self::assertSame($preference === $expected, $task === $registered);
        }
        $pool = new Pool(1);
        $entry->execute($pool, 7);
        self::assertSame(1, $task->executions);
        self::assertSame($pool, $task->pool);
        self::assertSame(7, $task->workerId);
    }

    /**
     * The callback retains the signature required by its registration or reflection contract.
     *
     * @throws Throwable When fixture setup or the operation under test throws
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function testFactoryBindsTheNewManagerAndRegistersActiveProcessesInOrder(): void
    {
        $binding = new ReflectionClass(ProcessManagerProvider::class)
            ->getAttributes(FactoryProvider::class)[0]->newInstance();
        self::assertSame(ProcessManagerInterface::class, $binding->className);
        $task = new ProcessDefinition(
            static function (Pool $pool, int $workerId): void {
                self::fail('Collection must not execute a process.');
            },
        );
        $annotations = $this->createMock(AnnotationCollectorInterface::class);
        $annotations->expects(self::once())->method('getClassesWithAttribute')->with(Process::class)->willReturn([
            AttributedHandler::class => [
                new AttributeMetadata(new Process('enabled', workNums: 2)),
                new AttributeMetadata(new Process('zero', workNums: 0)),
            ],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(AttributedHandler::class)->willReturn($task);

        $manager = new ProcessManagerProvider()($annotations, $container);

        self::assertSame([
            'enabled:0' => $task,
            'enabled:1' => $task,
        ], $manager->all());
        self::assertTrue($manager->has('enabled:0'));
        self::assertSame($task, $manager->get('enabled:0'));
        self::assertFalse($manager->has('enabled'));
        self::assertFalse($manager->has('zero'));
    }

    /**
     * The callback retains the signature required by its registration or reflection contract.
     *
     * @throws Throwable When fixture setup or the operation under test throws
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function testNullNamesUseTheAttributedClassWithBatchSuffixes(): void
    {
        $task = new ProcessDefinition(
            static function (Pool $pool, int $workerId): void {
                self::fail('Collection must not execute a process.');
            },
        );
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            AttributedHandler::class => [
                new AttributeMetadata(new Process()),
            ],
            ConfiguredProcess::class => [
                new AttributeMetadata(new Process(null, workNums: 2)),
            ],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturn($task);

        $manager = new ProcessManagerProvider()($annotations, $container);

        self::assertSame([
            AttributedHandler::class        => $task,
            ConfiguredProcess::class . ':0' => $task,
            ConfiguredProcess::class . ':1' => $task,
        ], $manager->all());
        self::assertTrue($manager->has(AttributedHandler::class));
        self::assertSame($task, $manager->get(AttributedHandler::class));
        self::assertSame($task, $manager->get(ConfiguredProcess::class . ':1'));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'nonzero' => [
            1,
        ];
        yield 'zero' => [
            0,
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidMarkedClassesAreRejectedEvenWithZeroProcesses(int $workNums): void
    {
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            InvalidProcess::class => [
                new AttributeMetadata(new Process('invalid', $workNums)),
            ],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $this->expectException(InvalidArgumentException::class);

        new ProcessManagerProvider()($annotations, $container);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testZeroProcessesDoNotResolveImplementations(): void
    {
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            AttributedHandler::class => [
                new AttributeMetadata(new Process('zero', workNums: 0)),
            ],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');

        self::assertSame([], new ProcessManagerProvider()($annotations, $container)->all());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDisabledTasksAreResolvedOnceAndAddNoWorkers(): void
    {
        $task = new class() implements ProcessInterface {
            public bool $enabled { get => false; }

            public bool $enableCoroutine {
                get {
                    TestCase::fail('Disabled task options must not be read.');
                }
            }

            public function execute(Pool $pool, int $workerId): void
            {
                TestCase::fail('Disabled tasks must not execute.');
            }
        };
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            AttributedHandler::class => [
                new AttributeMetadata(new Process('first', workNums: 2, enableCoroutine: true)),
                new AttributeMetadata(new Process('second')),
            ],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(AttributedHandler::class)->willReturn($task);

        $manager = new ProcessManagerProvider()($annotations, $container);

        self::assertSame([], $manager->all());
        $manager->start(static function (): void {
            self::fail('Disabled tasks must not allocate runtime resources.');
        });
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testInvalidContainerBindingsFailDuringPreparation(): void
    {
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            AttributedHandler::class => [
                new AttributeMetadata(new Process('invalid-binding')),
            ],
        ]);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new stdClass());
        $this->expectException(InvalidArgumentException::class);

        new ProcessManagerProvider()($annotations, $container);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testExpandedNameCollisionsAreRejected(): void
    {
        $annotations = $this->createStub(AnnotationCollectorInterface::class);
        $annotations->method('getClassesWithAttribute')->willReturn([
            AttributedHandler::class => [
                new AttributeMetadata(new Process('worker', workNums: 2)),
                new AttributeMetadata(new Process('worker:0')),
            ],
        ]);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn(new AttributedHandler());
        $this->expectException(LogicException::class);

        new ProcessManagerProvider()($annotations, $container);
    }
}
