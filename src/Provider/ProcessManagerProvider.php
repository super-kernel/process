<?php

declare(strict_types=1);

namespace SuperKernel\Process\Provider;

use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessInterface;
use SuperKernel\Process\Contract\ProcessManagerInterface;
use SuperKernel\Process\Manager\ProcessManager;

use function is_a;

#[FactoryProvider(ProcessManagerInterface::class)]
final class ProcessManagerProvider
{
    /**
     * @throws InvalidArgumentException    if a marked class or resolved service does not implement ProcessInterface
     * @throws ContainerExceptionInterface if the container cannot resolve a task with nonzero workNums
     * @throws LogicException              if names are empty/duplicated or the worker registration limit is exceeded
     */
    public function __invoke(
        AnnotationCollectorInterface $collector,
        ContainerInterface           $container,
    ): ProcessManagerInterface
    {
        $manager = new ProcessManager();
        foreach ($collector->getClassesWithAttribute(Process::class) as $class => $attributes) {
            if (!is_a($class, ProcessInterface::class, true)) {
                throw new InvalidArgumentException($class . ' must implement ' . ProcessInterface::class . '.');
            }
            $process = null;
            foreach ($attributes as $metadata) {
                $attribute = $metadata->attribute();
                if (0 === $attribute->workNums) {
                    continue;
                }
                $process ??= $container->get($class);
                if (!$process instanceof ProcessInterface) {
                    throw new InvalidArgumentException('Invalid process container binding: ' . $class);
                }
                $manager->addBatch(
                    $attribute->workNums,
                    $process,
                    $attribute->enableCoroutine,
                    $attribute->name ?? $class,
                );
            }
        }
        return $manager;
    }
}
