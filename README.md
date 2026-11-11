<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Process

`super-kernel/process` runs an application's background processes as the named workers of one Swoole process pool, declared with an Attribute and started next to the server by `swoole:start`.

## Installation

```bash
composer require super-kernel/process
```

The package needs the Swoole extension `>=6.2.3 <6.3` and the `pcntl`, `posix`, and `sockets` extensions, and it installs `super-kernel/swoole`, whose `swoole:start` command starts the pool. SKERNEL builds the project.

## What it provides

- Background processes declared with `#[Process]`, each with a name and a number of workers.
- `ProcessManagerInterface`, which registers processes by name and starts them as one `Swoole\Process\Pool`.
- Workers that run their task directly or inside a coroutine, chosen per process.
- A pool that replaces a worker whenever its task returns or fails, until the whole pool is stopped.
- Pool-wide inter-process communication through Unix sockets or a System V message queue.
- Under `swoole:start`, the pool in its own child of the main process, next to the server.

It does not:

- run a task once and report it as finished; a returning worker is replaced;
- retry with a limit or a delay, or stop the other workers when one fails;
- give a worker its own pipe or a stable address that survives replacement;
- force a worker to stop that ignores the shutdown signal;
- run the server or its user processes; `super-kernel/server` does.

## Quick start

In an application created from `super-kernel/skeleton`, declare a background process. Save it as `src/Process/Ticker.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Process;

use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessInterface;
use Swoole\Coroutine;
use Swoole\Process\Pool;

#[Process]
final class Ticker implements ProcessInterface
{
    public bool $enabled {
        get => true;
    }

    public bool $enableCoroutine {
        get => true;
    }

    public function execute(Pool $pool, int $workerId): void
    {
        while (true) {
            echo "Ticker in worker {$workerId}", PHP_EOL;
            Coroutine::sleep(1);
        }
    }
}
```

Build the project and start it:

```bash
skernel build
php bin/bootstrap.php swoole:start
```

It prints `Ticker in worker 0` every second next to the server's log lines. Press Ctrl+C to stop the pool and the server.

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\Process\Attribute\Process` | Attribute | Declares a background process with an optional name, a worker count, and a coroutine choice. |
| `SuperKernel\Process\Contract\ProcessInterface` | Interface | Implemented by a background process: `enabled`, `enableCoroutine`, and `execute()`. |
| `SuperKernel\Process\Contract\ProcessManagerInterface` | Interface | Registers processes, sets the pool's IPC, and starts or stops the pool. |
| `SuperKernel\Process\Manager\ProcessManager` | Class | The manager, for running a pool without the container. |
| `SuperKernel\Process\Definition\ProcessDefinition` | Class | Wraps a `callable(Pool, int): void` with its own options. |

## Behavior to know

- While the pool runs, a worker whose task returns, fails, or does nothing is started again; only `$pool->shutdown()`, `stop()`, or `SIGTERM` to the pool's parent ends the pool.
- Shutdown signals every worker and waits for all of them, without a deadline: a worker that ignores `SIGTERM` keeps the pool, and `swoole:start`, from stopping.
- Starting a pool inside a worker fails with Swoole's `A process pool instance has already been created and cannot be created again`.
- `swoole:start` copies the registered processes when it dispatches `MainProcessCreated`; processes registered later are not started. With no enabled process, no pool is started.
- Registration raises `LogicException` for a duplicate name, such as `Duplicate process name: mailer:0.`, and `get()` raises `SuperKernel\Process\Exception\ProcessNotFoundException` for an unknown name.
- Processes are found in the build metadata: run `skernel build` again after adding, removing, or changing a `#[Process]` class, or start with `skernel run`, which builds first.

## Documentation

The developer documentation covers declarations and coroutine choices, the manager, pool lifecycle and IPC, running under the main process, the error messages, and what not to do: [SuperKernel Process](https://github.com/super-kernel/.github/blob/main/docs/components/process.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
