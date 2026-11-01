<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Process

`super-kernel/process` 运行具名的 Swoole Pool 工作进程。`Contract\ProcessManagerInterface` 是框架的进程管理契约，对应 Swoole PHP 库中的 [`Process\Manager`](https://github.com/swoole/library/blob/master/src/core/Process/Manager.php)。`Manager\ProcessManager` 每次启动只创建**一个** `Swoole\Process\Pool`，为每个已注册的条目分配一个工作进程。任务在这些工作进程中运行，不得再创建另一个原生 Pool。

## 管理器契约

| 方法                                                                    | 行为                                                               |
|-------------------------------------------------------------------------|--------------------------------------------------------------------|
| `add($process, $enableCoroutine = null, $name = null)`                  | 注册一个任务或可调用值，并返回当前管理器                           |
| `addBatch($workerNum, $process, $enableCoroutine = null, $name = null)` | 以原子方式向同一个 Pool 添加多个工作进程注册                       |
| `all()`                                                                 | 按工作进程 ID 顺序，返回以名称为键的已注册 `ProcessInterface` 任务 |
| `has($name)`                                                            | 检查某个名称是否已注册；不反映工作进程是否存活                     |
| `get($name)`                                                            | 重复查找时返回同一个已注册的任务，工作进程被替换后也是如此         |
| `setIPCType()` / `getIPCType()`                                         | 设置/读取所有工作进程共享的 IPC 模式                               |
| `setMsgQueueKey()` / `getMsgQueueKey()`                                 | 设置/读取 Pool 的 System V 消息队列键                              |
| `start()`                                                               | 启动 Pool，并等待关闭以及剩余工作进程被回收                        |
| `stop()`                                                                | 从父进程或某个工作进程请求关闭整个 Pool                            |

`add`、`addBatch` 和各个 setter 支持链式调用。未命名的条目以其从零开始的注册序号作为名称。多个工作进程的具名批次会展开为 `name:0`、`name:1` 等；只有一个工作进程的批次保留 `name`。工作进程数为零时什么也不做。名称是唯一、区分大小写的非空字符串；完全限定类名会完整保留，不会被截断。注册总数上限为 1,024 个工作进程。所有者第一次尝试启动后，注册和设置即被关闭。空的或已提前取消的注册表不会创建 Pool。

`$process` 参数接受 `ProcessInterface` 或 `callable(Pool, int): void`。可调用值会被适配为 `Definition\ProcessDefinition`。协程设置为 null 时使用任务自身的属性，可调用值则为 false。显式覆盖会适配该任务，而不会修改原始实例。没有覆盖时，保留所注册任务的身份。`all()`/`get()` 暴露的是生效后的任务，因此复制注册信息时会保留这一设置。`enabled` 属性为 false 的任务会被 `add()` 和 `addBatch()` 跳过，不占用名称或工作进程 ID。

名称不存在时抛出 `Exception\ProcessNotFoundException`，它是 `OutOfBoundsException` 的子类。

## 工作进程入口

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

类型化的任务实现 `ProcessInterface`，带有可读的 `bool $enabled` 和 `bool $enableCoroutine` 属性，以及 `execute(Pool $pool, int $workerId): void`。`ProcessDefinition` 适配具有这一签名的 Closure，并接受可选的布尔参数 `enableCoroutine = false` 和 `enabled = true`。单个进程的 `redirectStdinStdout` 和 `pipeType` 设置不适用于 Pool 工作进程。

Pool 本身是同步的。每个工作进程注册时的设置决定其任务是直接执行，还是在 `Swoole\Coroutine\run($callback, $pool, $workerId)` 中执行，这与原生 Manager 对每个条目的包装方式一致。一个批次是向这一个 Pool 添加工作进程，而不是每次调用或每个任务各创建一个 Pool。

## 声明进程

`Attribute\Process` 的默认参数为 `name: null`、`workNums: 1` 和 `enableCoroutine: null`。名称为 null 时使用所标注类的完全限定名，即使容器解析出的是另一个实现。多个进程会追加 `:0`、`:1` 等后缀。

容器提供的 `ProcessManagerInterface` 已注册了所有 `#[Process]` 类。每个声明都会被校验，每个 `workNums` 非零的类都会通过容器解析一次；启用的任务通过 `addBatch()` 展开。禁用的任务仍会被解析以读取其 `enabled` hook，但不会添加工作进程；`workNums: 0` 的声明不会被解析。准备阶段从不执行任务，也不启动 Pool。构造函数和选项读取都在父进程中运行，不得初始化 reactor 或业务连接。

## 协程设置的继承

注解的 `?bool $enableCoroutine = null` 继承任务的布尔属性 `enableCoroutine`，与直接通过管理器注册时一样；显式的 true 或 false 会覆盖它，但不改变任务本身。

| 注解参数      | 任务属性为 false | 任务属性为 true |
|---------------|------------------|-----------------|
| 省略或 `null` | 直接执行         | 协程执行        |
| `false`       | 直接执行         | 直接执行        |
| `true`        | 协程执行         | 协程执行        |

推荐的声明方式只表达一次偏好：

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

禁用的任务和工作进程数为零的声明保持不活动。自行注册声明的自定义集成，用 `$attribute->enableCoroutine ?? $task->enableCoroutine` 计算生效值，并把它传给管理器的注册 API。

注解缓存保存的是实例化后的注解值。在仅恢复或 Phar 部署之前，以及修改声明之后，请通过扫描源码重新生成缓存。

## 已禁用的应用示例

`super-kernel/skeleton` 项目模板中的 `Src\Process\TestProcess` 是一个长期运行、受监管的示例，默认保持禁用：它的 `enabled` hook 返回 false，所以模板默认不会启动工作进程。启用它之后，请把演示用的心跳替换为应用自己的工作。

该任务通过 `#[Autowired]` 接收 `StdoutLoggerInterface`，记录一条带工作进程 ID 的心跳日志，然后用 `Swoole\Coroutine::sleep(30)` 休眠 30 秒。它的 `#[Process]` 声明从其 `enableCoroutine` hook 继承了 true，因此休眠发生在协程中。它有意从不从 `execute()` 返回：Pool 运行期间，返回会导致该工作进程被替换，而不是完成一次性的任务。

## Pool 生命周期与 IPC

请在干净的 CLI 中启动，此时还没有活动的协程、reactor 或异步任务。Pool 运行期间，**返回或失败的工作进程都会被替换**，包括空回调和成功完成的有限回调。框架没有重试预算、退避策略，也没有"一个工作进程失败就停止其兄弟进程"的规则。有限的任务必须自行协调完成，并在适当时显式请求关闭整个 Pool。参见[原生 Pool 生命周期](https://github.com/swoole/swoole-src/blob/v6.2.3/src/os/process_pool.cc)。

`$pool->shutdown()`、在工作进程中调用 `$manager->stop()`，或向 Pool 父进程发送 SIGTERM，都会请求关闭整个 Pool。原生 Pool 会向其工作进程发信号；任务必须响应关闭并完成清理。管理器遵循原生的等待行为，不提供关闭或强制终止的期限，也不转发 SIGINT，因此不配合的工作进程可能无限期地拖延关闭。

原生 `Pool::start()` 返回后，管理器会等待剩余的子进程退出。原生 Pool 拥有一个进程级的子进程等待循环，因此不要在同一个父进程中把它与其他无关的子进程所有者混用；那些子进程的退出状态不会被保留。注册表查询在关闭后仍然可用，但它不是运行时健康检查。

IPC 默认为 `SWOOLE_IPC_NONE`，也支持 `SWOOLE_IPC_UNIXSOCK` 和 `SWOOLE_IPC_MSGQUEUE`。队列键默认为零，表示私有队列。这些都是 Pool 级的设置，混合了协程注册时也是如此。`SWOOLE_IPC_SOCKET` 会被拒绝：它需要一个在启动前执行的 `listen()` 步骤，而这个管理器不提供。工作进程会收到原生 Pool，可以使用其支持的 IPC 方法；没有针对单个工作进程的管道设置。

管理器的构造函数只配置 Pool 的 IPC：

```php
$manager = new SuperKernel\Process\Manager\ProcessManager(
    ipcType: SWOOLE_IPC_NONE,
    msgQueueKey: 0,
);
```

禁止在受管理的工作进程中再创建第二个原生 Pool，Swoole 的[已有 Pool 保护](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/swoole_process_pool.cc)会拒绝这样做。

## 在主进程下运行

使用 `swoole:start` 进行事件驱动的启动，或者注入 `SuperKernel\Swoole\Runtime\ProcessRuntime`，用应用经过校验的 `SwooleConfigInterface` 调用 `$runtime->start($config)`。在 `MainProcessCreated` 事件中，Process 以最低优先级在主进程下挂载一个子进程，并在其中用已注册任务、名称和 IPC 设置的副本启动一个新的 Pool。注册表为空时不挂载任何东西。请在事件阶段通过 `ProcessManagerInterface` 完成注册；之后对原注册表的修改（包括取消）不会影响正在运行的子进程。

主进程负责守护进程化，写入它自己最终的 PID，并记录已挂载子进程的 PID；守护进程和 PID 的行为来自 `SwooleConfigInterface::daemon()` 和 `pidFile()`，示例见 `super-kernel/skeleton` 项目模板中的 `src/Config/SwooleConfig.php`。Pool 父进程是其中一个被挂载的子进程；它和它的工作进程都不写主 PID 文件。向主进程发送 SIGTERM 或 SIGINT，会按顺序向已挂载的子进程转发 SIGTERM。Pool 父进程处理其工作进程，在它们完成清理后返回，然后由主进程回收它。

需要直接以编程方式监管时，在被挂载的子进程内部构造管理器：

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

守护进程和 PID 的失败以及子进程的失败退出会抛出 Swoole 的 `SupervisorException`；直接使用管理器时的失败抛出 `ProcessException`。

当 Server 的配置启用它时，`super-kernel/server` 会在同一个主进程下挂载自己独立的子进程。Server 和 Pool 并行运行；Process 不会把 Server 注册为 Pool 的工作进程。
