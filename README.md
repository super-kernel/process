<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Process

`super-kernel/process` runs named Swoole Pool workers. `Contract\ProcessManagerInterface` is the framework's process-management contract, mirroring Swoole's PHP [`Process\Manager`](https://github.com/swoole/library/blob/master/src/core/Process/Manager.php). `Manager\ProcessManager` creates **one** `Swoole\Process\Pool` per start, with one worker for every registered entry. Tasks run inside those workers and must not create another native Pool.

## Manager contract

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

`add`, `addBatch` and setters support chaining. An unnamed entry uses its zero-based registration index as its name. A named batch of multiple workers expands to `name:0`, `name:1`, etc.; a one-worker batch retains `name`. Zero workers is a no-op. Names are unique, case-sensitive, nonempty strings; fully qualified class names are retained without truncation. The total registration limit is 1,024 workers. Registration and settings close on the owner's first start attempt. Empty or pre-cancelled registries create no Pool.

The `$process` parameter accepts `ProcessInterface` or `callable(Pool, int): void`. A callable is adapted to `Definition\ProcessDefinition`. A null coroutine setting uses the task's property, or false for a callable. An explicit override adapts the task without mutating the original instance. Without an override, registered task identity is preserved. `all()`/`get()` expose the effective task, so copying registrations preserves this setting. Tasks whose `enabled` property is false are skipped by both `add()` and `addBatch()` and consume no names or worker IDs.

Missing names throw `Exception\ProcessNotFoundException`, an `OutOfBoundsException` subclass.

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

A typed task implements `ProcessInterface` with readable `bool $enabled` and `bool $enableCoroutine` properties and `execute(Pool $pool, int $workerId): void`. `ProcessDefinition` adapts a Closure with this signature and accepts optional `enableCoroutine = false` and `enabled = true` booleans. Per-process `redirectStdinStdout` and `pipeType` settings do not apply to Pool workers.

The Pool itself is synchronous. Each worker's registered setting determines whether its task executes directly or inside `Swoole\Coroutine\run($callback, $pool, $workerId)`, matching the native Manager's per-entry wrapping. A batch adds workers to this one Pool; it does not create one Pool per call or per task.

## Declaring processes

`Attribute\Process` accepts `name: null`, `workNums: 1`, and `enableCoroutine: null` by default. A null name uses the attributed class's fully qualified name, even when the container resolves a different implementation. Multiple processes append `:0`, `:1`, etc.

The container supplies `ProcessManagerInterface` with every `#[Process]` class registered. Each declaration is validated, and each class with nonzero `workNums` is resolved once through the container; enabled tasks are expanded through `addBatch()`. Disabled tasks are resolved to read their `enabled` hook but add no workers, and declarations with `workNums: 0` are not resolved. Preparation never executes a task or starts a Pool. Constructors and option reads run in the parent and must not initialize reactors or business connections.

## Coroutine inheritance

The Attribute's `?bool $enableCoroutine = null` inherits the task's boolean `enableCoroutine` property, just like direct manager registration; explicit true or false overrides it without changing the task.

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

Disabled tasks and zero-worker declarations remain inactive. Custom integrations that register declarations themselves resolve the effective value with `$attribute->enableCoroutine ?? $task->enableCoroutine` and pass it to the manager registration API.

Annotation caches retain instantiated Attribute values. Rebuild them by scanning source before restore-only or Phar deployment and after changing declarations.

## Disabled application example

The `super-kernel/skeleton` project template's `Src\Process\TestProcess` is a long-running, supervised example that stays disabled: its `enabled` hook returns false, so the template does not start a worker by default. After enabling it, replace the demonstration heartbeat with application work.

The task receives `StdoutLoggerInterface` through `#[Autowired]`, logs a heartbeat with its worker ID, and sleeps for 30 seconds with `Swoole\Coroutine::sleep(30)`. Its `#[Process]` declaration inherits true from its `enableCoroutine` hook, which keeps the sleep inside a coroutine. It deliberately never returns from `execute()`: while the Pool is running, returning would cause replacement of that worker, not completion of a one-shot job.

## Pool lifecycle and IPC

Start in a clean CLI before active coroutines, reactors, or asynchronous work. While the Pool is running, **returning or failing workers are replaced**, including an empty callback and a successful finite callback. There is no framework retry budget, backoff, or rule that one worker failure stops its siblings. A finite job must coordinate completion and explicitly request whole-Pool shutdown when appropriate. See the [native Pool lifecycle](https://github.com/swoole/swoole-src/blob/v6.2.3/src/os/process_pool.cc).

`$pool->shutdown()`, `$manager->stop()` from a worker, or SIGTERM to the Pool parent requests whole-Pool shutdown. The native Pool signals its workers; tasks must honor shutdown and finish their cleanup. The manager follows the native waiting behavior and provides no shutdown or kill deadlines or SIGINT forwarding, so an uncooperative worker can delay shutdown indefinitely.

After native `Pool::start()` returns, the manager waits for the remaining child exits. The native Pool owns a process-wide child wait loop, so do not combine it with unrelated child-process owners in the same parent; their exits are not preserved. Registry queries remain available after shutdown and are not runtime health checks.

IPC defaults to `SWOOLE_IPC_NONE`. `SWOOLE_IPC_UNIXSOCK` and `SWOOLE_IPC_MSGQUEUE` are also supported. The queue key defaults to zero for a private queue. These are Pool-wide settings, including for mixed coroutine registrations. `SWOOLE_IPC_SOCKET` is rejected: it requires a pre-start `listen()` step that this manager does not provide. Workers receive the native Pool and can use its supported IPC methods; there is no per-worker pipe setting.

The manager constructor configures only Pool IPC:

```php
$manager = new SuperKernel\Process\Manager\ProcessManager(
    ipcType: SWOOLE_IPC_NONE,
    msgQueueKey: 0,
);
```

Creating a second native Pool from a managed worker is prohibited and is rejected by Swoole's [existing-Pool guard](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/swoole_process_pool.cc).

## Running under the main process

Use `swoole:start` for event-driven startup, or an injected `SuperKernel\Swoole\Runtime\ProcessRuntime` and `$runtime->start($config)` with the application's validated `SwooleConfigInterface`. On `MainProcessCreated`, at minimum priority, Process mounts one child under the main process and starts a fresh Pool there with a copy of the registered tasks, names, and IPC settings. An empty registry mounts nothing. Complete registrations through `ProcessManagerInterface` during the event phase; later changes to the original registry, including cancellation, do not affect the running child.

The main process handles daemonization, writes its own final PID, and records its mounted child PIDs; daemon and PID behavior come from `SwooleConfigInterface::daemon()` and `pidFile()`, as in the `super-kernel/skeleton` project template's `src/Config/SwooleConfig.php`. The Pool parent is one mounted child; neither it nor its workers writes the main PID file. SIGTERM or SIGINT to the main process forwards SIGTERM to mounted children in order. The Pool parent handles its workers and returns after their cleanup, and the main process then reaps it.

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

Daemon and PID failures and failed child exits raise Swoole's `SupervisorException`; direct manager failures raise `ProcessException`.

When Server's configuration enables it, `super-kernel/server` mounts its own independent child under the same main process. Server and Pool run concurrently; Process does not register Server as a Pool worker.
