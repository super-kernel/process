<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use SuperKernel\Annotator\Cache\AnnotationCache;
use SuperKernel\Annotator\Cache\SerializedPackageReferences;
use SuperKernel\Annotator\Exception\AnnotationCacheException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Annotator\Scan\AnnotationScanner;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\PackageMetadataInterface;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\Contract\VendorDirInterface;
use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessInterface;
use SuperKernel\Process\Provider\ProcessManagerProvider;
use SuperKernel\Process\Tests\Fixture\ConfiguredProcess;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function serialize;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ProcessAttributeTest extends TestCase
{
    /** @throws ReflectionException When an expected public parameter or property cannot be reflected */
    public function testNullableOverridePreservesParameterNamesOrderAndBooleanTaskContract(): void
    {
        $reflection = new ReflectionClass(Process::class);
        $parameters = $reflection->getConstructor()->getParameters();
        self::assertSame('name', $parameters[0]->getName());
        self::assertSame('workNums', $parameters[1]->getName());
        self::assertSame('enableCoroutine', $parameters[2]->getName());
        self::assertSame('?bool', (string) $parameters[2]->getType());
        self::assertNull($parameters[2]->getDefaultValue());
        $property = $reflection->getProperty('enableCoroutine');
        self::assertTrue($property->isPublic());
        self::assertTrue($property->isReadOnly());
        self::assertTrue($property->isPromoted());
        self::assertSame('?bool', (string) $property->getType());
        self::assertFalse($property->hasDefaultValue());
        self::assertNull(new Process()->enableCoroutine);
        self::assertNull(new Process(enableCoroutine: null)->enableCoroutine);
        self::assertTrue(new Process('named', 2, true)->enableCoroutine);
        self::assertFalse(new Process(name: 'named', workNums: 2, enableCoroutine: false)->enableCoroutine);
        self::assertSame('bool', (string) new ReflectionClass(ProcessInterface::class)
            ->getProperty('enableCoroutine')->getType());
    }

    /** @throws Throwable When cache restoration, scanning or registration fails */
    public function testSchemaThreeRequiresRebuildingAndCurrentSnapshotsPreserveExplicitFalse(): void
    {
        // Generated from ConfiguredProcess's omitted argument at commit 23323b1 using the actual scanner.
        $contents = file_get_contents(__DIR__ . '/../Fixture/legacy-process-annotations.ser');
        self::assertNotFalse($contents);
        $package = $this->createStub(PackageMetadataInterface::class);
        $package->method('version')->willReturn(null);
        $package->method('name')->willReturn('acme/process');
        $package->method('reference')->willReturn('before-null-coroutine');
        $packages = $this->createStub(PackageManagerInterface::class);
        $packages->method('all')->willReturn([
            $package,
        ]);
        $reflection = new ReflectionClass(ConfiguredProcess::class);
        $packages->method('classes')->willReturn([
            ConfiguredProcess::class => $reflection->getFileName(),
        ]);
        $reflector = $this->createStub(ReflectorInterface::class);
        $reflector->method('reflectClass')->willReturnCallback(
            static fn(string $class): ReflectionClass => new ReflectionClass($class),
        );
        $directory = tempnam(sys_get_temp_dir(), 'sk-process-cache-');
        unlink($directory);
        mkdir($directory);
        $vendor = $this->createStub(VendorDirInterface::class);
        $vendor->method('__toString')->willReturn($directory);
        $cache = new AnnotationCache($vendor);
        try {
            mkdir($directory . '/.cache/annotations/acme', recursive: true);
            file_put_contents($directory . '/.cache/annotations/acme/process.ser', $contents);
            file_put_contents(
                $directory . '/.cache/' . AnnotationCache::INDEX_PATH,
                serialize(new SerializedPackageReferences([
                    'acme/process' => 'before-null-coroutine',
                ])),
            );
            $task = new ConfiguredProcess($directory . '/executed', true);
            $container = $this->createMock(ContainerInterface::class);
            $container->expects(self::exactly(2))->method('get')->with(ConfiguredProcess::class)->willReturn($task);

            try {
                $cache->read($packages);
                self::fail('Schema 3 cannot establish whether any classes were skipped.');
            } catch (AnnotationCacheException $exception) {
                self::assertStringContainsString(
                    'annotation cache for package acme/process is invalid',
                    $exception->getMessage(),
                );
            }

            // Current-schema snapshots still retain explicit false; only rescanning omitted arguments yields null.
            $cache->write($packages, [
                new PackageAnnotations('acme/process', 'before-null-coroutine', [
                    ConfiguredProcess::class => [
                        new AttributeMetadata(new Process(enableCoroutine: false)),
                    ],
                ], [], []),
            ]);
            $restored = $cache->read($packages);
            $old = $restored->getClassesWithAttribute(Process::class)[ConfiguredProcess::class][0]->attribute();
            self::assertFalse($old->enableCoroutine);
            $oldEntry = new ProcessManagerProvider()($restored, $container)->get(ConfiguredProcess::class);
            self::assertFalse($oldEntry->enableCoroutine);
            self::assertNotSame($task, $oldEntry);
            self::assertTrue($task->enableCoroutine);

            $scanner = new AnnotationScanner($packages, $reflector);
            $cache->write($packages, $scanner->scan());
            $rescanned = $cache->read($packages);
            $fresh = $rescanned->getClassesWithAttribute(Process::class)[ConfiguredProcess::class][0]->attribute();
            self::assertNull($fresh->enableCoroutine);
            $newEntry = new ProcessManagerProvider()($rescanned, $container)->get(ConfiguredProcess::class);
            self::assertSame($task, $newEntry);
            self::assertTrue($newEntry->enableCoroutine);
            self::assertFileDoesNotExist($directory . '/executed');
        } finally {
            $cache->invalidate();
            rmdir($directory . '/.cache');
            rmdir($directory);
        }
    }
}
