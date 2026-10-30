# SuperKernel Process

Named Pool workers for PHP 8.4.27 or later in the 8.4.x series and Swoole `>=6.2.3 <6.3`. `Contract\ProcessManagerInterface` provides the framework's
process-management contract. `Manager\ProcessManager` creates **one** `Swoole\Process\Pool` per start, with one
worker for every registered entry. Tasks run inside those workers and must not create another native Pool.

## Manager contract

The contract covers the actual responsibilities of Swoole's PHP
[`Process\Manager`](https://github.com/swoole/library/blob/master/src/core/Process/Manager.php): single/batch
registration, startup, Pool IPC type and message-queue key. Framework consumers depend on the interface;
the implementation uses native Pool directly.

| Method                                                                  | Behavior                                                                               |
|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------|
| `add($process, $enableCoroutine = null, $name = null)`                  | Register one task or callable and return this manager                                  |
| `addBatch($workerNum, $process, $enableCoroutine = null, $name = null)` | Add worker registrations to the same Pool atomically                                   |
| `all()`                                                                 | Return registered `ProcessInterface` tasks keyed by name, in worker-ID order           |
| `has($name)`                                                            | Check whether a name is registered; this says nothing about worker liveness            |
| `get($name)`                                                            | Return the same registered task on repeated lookup, including after worker replacement |
| `setIPCType()` / `getIPCType()`                                         | Set/read the IPC mode shared by every worker                                           |
| `setMsgQueueKey()` / `getMsgQueueKey()`                                 | Set/read the Pool's System V message-queue key                                         |
| `start()`                                                               | Start the Pool and wait for shutdown and remaining worker reaping                      |
| `stop()`                                                                | Request shutdown of the entire Pool, from its parent or a worker                       |

`add`, `addBatch` and setters support chaining. An unnamed entry uses its zero-based registration index as its
name. A named batch of multiple workers expands to `name:0`, `name:1`, etc.; a one-worker batch retains `name`.
Zero workers is a no-op. Names are unique, case-sensitive, nonempty strings; fully qualified class names are
retained without truncation. The total registration limit is 1,024 workers.
Registration/settings close on the owner's first start attempt. Empty or pre-cancelled registries create no Pool.

The `$process` parameter accepts `ProcessInterface` or `callable(Pool, int): void`. A callable is adapted to
`Definition\ProcessDefinition`. A null coroutine setting uses the task's property, or false for a callable.
An explicit override adapts the task without mutating the original instance. Without an override, registered
task identity is preserved. `all()`/`get()` expose the effective task, so copying registrations preserves this setting.
Tasks whose `enabled` property is false are skipped by both `add()` and `addBatch()` and consume no names or worker IDs.

Missing names throw `Exception\ProcessNotFoundException`, an `OutOfBoundsException` subclass with an explicit
`@throws` contract. [PhpStorm's default unchecked-exception rules](https://blog.jetbrains.com/phpstorm/2018/04/configurable-unchecked-exceptions/)
avoid mandatory catch warnings for both `has()`-guarded and direct `get()` calls. Custom IDE settings can change this.

## Worker entry points

```php
use SuperKernel\Process\Manager\ProcessManager;
use Swoole\Process\Pool;

$pm = new ProcessManager();
$pm->add(function (Pool $pool, int $workerId): void {
    // This runs in worker 0. Initialize child-owned resources here.
    $pool->shutdown(); // Requests shutdown of the whole Pool.
}, name: 'maintenance');
$pm->start();
```

A typed task implements `ProcessInterface` with readable `bool $enabled` and `bool $enableCoroutine` properties and
`execute(Pool $pool, int $workerId): void`. `ProcessDefinition` adapts a Closure with this signature and accepts
optional `enableCoroutine = false` and `enabled = true` booleans. Per-process `redirectStdinStdout` and `pipeType`
settings do not apply to Pool worker entries and are no longer part of either declaration.

The Pool itself is synchronous. Each worker's registered setting determines whether its task executes directly
or inside `Swoole\Coroutine\run($callback, $pool, $workerId)`, matching the native Manager's per-entry wrapping.
A batch adds workers to this one Pool; it does not create one Pool per call or per task.

`Attribute\Process` accepts `name: null`, `workNums: 1`, and `enableCoroutine: null` by default. A null name uses
the attributed class's fully qualified name, even when the container resolves a different implementation.
Multiple processes append `:0`, `:1`, etc. An omitted or null coroutine setting inherits the task's boolean
`enableCoroutine` property, just like direct manager registration. Explicit true or false overrides that preference
without changing the original task. Only effective boolean values reach Pool workers.

`Provider\ProcessManagerProvider` binds the interface. It validates every `#[Attribute\Process]` class and resolves
each class with nonzero `workNums` through the container once. The manager reads the task's `enabled` hook before
registration and expands enabled tasks through `addBatch()`. Disabled tasks must be resolved to evaluate the hook.
Declarations with `workNums: 0` require no resolution. Neither adds workers. Preparation never executes a task
or starts a Pool.
Constructors and option reads run in the parent and must not initialize reactors or business connections.

## Coroutine inheritance

The Attribute's third constructor parameter and promoted public readonly property are
`?bool $enableCoroutine = null` for inheritance or an explicit boolean override.
`ProcessInterface::$enableCoroutine` remains bool. Explicit overrides do not produce conflict diagnostics.

| Attribute argument | Task property false | Task property true  |
|--------------------|---------------------|---------------------|
| Omitted or `null`  | Direct execution    | Coroutine execution |
| `false`            | Direct execution    | Direct execution    |
| `true`             | Coroutine execution | Coroutine execution |

The recommended declaration expresses the preference once:

```php
use SuperKernel\Process\Attribute\Process;
use SuperKernel\Process\Contract\ProcessInterface;
use Swoole\Process\Pool;

#[Process]
final class Job implements ProcessInterface
{
    public bool $enabled { get => true; }
    public bool $enableCoroutine { get => true; }

    public function execute(Pool $pool, int $workerId): void
    {
        // Perform child-owned work here, then stop this finite example's Pool.
        $pool->shutdown();
    }
}
```

An omitted or null Attribute value inherits the task property; explicit true or false overrides it.
Disabled tasks and zero-worker declarations remain inactive. Resolve the effective value with
`$attribute->enableCoroutine ?? $task->enableCoroutine` before worker execution, using the manager registration API.

Annotation caches retain instantiated Attribute values. Explicit false in a current-schema record remains false.
Schema 4 is required; schema 3 records are rejected. Rebuild by scanning source before restore-only/Phar deployment
and after changing declarations. A rebuild evaluates omitted parameters using the current null default.

## Disabled application example

The `super-kernel/skeleton` project template's `Src\Process\TestProcess` remains disabled through its `enabled`
hook. It is a long-running, supervised example: after enabling it, replace the demonstration heartbeat with
application work. Its `enabled` hook still returns false, so the template does not start a worker by default.

The task receives `StdoutLoggerInterface` through `#[Autowired]`, logs a heartbeat with its worker ID, and sleeps for
30 seconds with `Swoole\Coroutine::sleep(30)`. Its declaration now uses `#[Process]` and inherits true from its
`enableCoroutine` hook. This keeps the sleep inside a coroutine when the
example is registered through discovery. It deliberately never returns from `execute()`: while the Pool is running,
returning would cause replacement of that worker, not completion of a one-shot job.

## Pool lifecycle and IPC

Start in a clean CLI before active coroutines, reactors, or asynchronous work. While the Pool is running,
**returning or failing workers are replaced**, including an empty callback and a successful finite callback.
There is no framework retry budget, backoff, or rule that one worker failure stops its siblings. A finite job must
coordinate completion and explicitly request whole-Pool shutdown when appropriate. See the
[native Pool lifecycle](https://github.com/swoole/swoole-src/blob/v6.2.3/src/os/process_pool.cc).

`$pool->shutdown()`, `$manager->stop()` from a worker, or SIGTERM to the Pool parent requests whole-Pool shutdown.
The native Pool signals its workers; tasks must honor shutdown and finish their cleanup. This API follows the
native waiting behavior; the manager provides no shutdown/kill deadlines or SIGINT forwarding.
An uncooperative worker can therefore delay shutdown indefinitely.

The manager waits for remaining child exits after native `Pool::start()` returns, since Swoole 6.2.3 can leave
final workers awaiting reaping. Native Pool already owns a process-wide child wait loop. Do not combine it with
unrelated child-process owners in the same parent; neither native Pool nor this wrapper preserves those exits.
Registry queries remain available after shutdown and are not runtime health checks.

IPC defaults to `SWOOLE_IPC_NONE`. `SWOOLE_IPC_UNIXSOCK` and `SWOOLE_IPC_MSGQUEUE` are also supported. The queue key
defaults to zero for a private queue. These are Pool-wide settings, including for mixed coroutine registrations.
`SWOOLE_IPC_SOCKET` is rejected: it requires a pre-start `listen()` boundary absent from this manager contract.
Workers receive the native Pool and can use its supported IPC methods; there is no framework per-worker pipe setting.

Creating a second native Pool from a managed worker is prohibited and is rejected by Swoole's
[existing-Pool guard](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/swoole_process_pool.cc).

## Framework startup and Server integration

`Listener\MainProcessCreatedListener` keeps minimum priority and mounts one child with `MainProcess::addProcess()`.
During event dispatch it snapshots the injected registry's tasks, names and IPC settings. An empty registry mounts
nothing. Inside the child it creates a fresh `ProcessManager`, copies those registrations and starts the Pool.
This preserves the manager's creator-only startup guard and isolates native Pool's wait loop from sibling runtimes.
Complete registrations in the event phase before this listener runs; later mutations of the original registry,
including cancellation, do not control the child-owned manager.

Use an injected `SuperKernel\Swoole\Runtime\ProcessRuntime` and call `$runtime->start($config)` with the
application's validated `SwooleConfigInterface`, or use `swoole:start` for event-driven startup.
`MainProcessCreated` exposes the supervisor through `mainProcess`; register tasks through `ProcessManagerInterface`.
Process depends on Swoole, while Swoole has no dependency on Process.

`SwooleConfigInterface` and its validated settings belong to Swoole. MainProcess handles daemonization, writes its
own final PID and privately records its mounted child PIDs. The Pool parent is one such child; neither it nor its
workers writes the main PID file. SIGTERM/SIGINT to MainProcess causes SIGTERM forwarding to mounted children in
order. The Pool parent handles its workers and returns after their cleanup; MainProcess then reaps the Pool parent.
For direct programmatic supervision, construct the manager inside the mounted child:

```php
use SuperKernel\Process\Manager\ProcessManager;
use SuperKernel\Swoole\MainProcess;
use Swoole\Process\Pool;

// $logger and $config are the application's injected StdoutLoggerInterface and SwooleConfigInterface services.
$main = new MainProcess($logger, $config);
$main->addProcess(static function (): void {
    $manager = new ProcessManager();
    $manager->add(static function (Pool $pool, int $workerId): void {
        $pool->shutdown();
    }, name: 'worker');
    $manager->start();
});
$main->start();
```

The manager constructor configures only Pool IPC:

```php
$manager = new SuperKernel\Process\Manager\ProcessManager(
    ipcType: SWOOLE_IPC_NONE,
    msgQueueKey: 0,
);
```

The main supervisor's daemon and PID behavior is controlled by `SwooleConfigInterface::daemon()` and `pidFile()`.
The `super-kernel/skeleton` project template's example is `src/Config/SwooleConfig.php`. Server activation belongs
to Server configuration; logging belongs to the injected logger. Daemon/PID failures and failed child exits use
Swoole's `SupervisorException`; direct manager failures use `ProcessException`.

`SuperKernel\Server\Listener\MainProcessCreatedListener` owns Server registration. On `MainProcessCreated`,
it checks Server's own configuration and mounts an independent child only when `enable()` returns `true`.
That child resolves `ServerInterface` and calls `start()`. Server owns its native or
coroutine runtime. Server and Pool run concurrently under MainProcess; Process does not register Server as a Pool worker.

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```
