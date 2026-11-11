<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Process

`super-kernel/process` 把应用的后台进程作为一个 Swoole 进程池中的命名工作进程运行；这些进程用注解声明，并由 `swoole:start` 在服务器旁边启动。

## 安装

```bash
composer require super-kernel/process
```

本包需要 `>=6.2.3 <6.3` 的 Swoole 扩展以及 `pcntl`、`posix` 和 `sockets` 扩展，并会安装 `super-kernel/swoole`，其 `swoole:start` 命令会启动进程池。由 SKERNEL 构建项目。

## 提供的能力

- 用 `#[Process]` 声明的后台进程，每个都有名称和工作进程数量。
- `ProcessManagerInterface`：按名称注册进程，并把它们作为一个 `Swoole\Process\Pool` 启动。
- 直接运行任务或在协程中运行任务的工作进程，按进程选择。
- 一个进程池：每当任务返回或失败时就替换该工作进程，直到整个进程池被停止。
- 通过 Unix 套接字或 System V 消息队列进行的、作用于整个进程池的进程间通信。
- 在 `swoole:start` 下，进程池位于主进程的独立子进程中，与服务器并列。

它不会：

- 只运行一次任务并报告其已完成；返回的工作进程会被替换；
- 带次数限制或延迟地重试，或者在一个工作进程失败时停止其他工作进程；
- 给工作进程分配自己的管道，或在替换后仍然稳定的地址；
- 强制停止忽略关闭信号的工作进程；
- 运行服务器或它的用户进程；这由 `super-kernel/server` 负责。

## 快速开始

在由 `super-kernel/skeleton` 创建的应用中声明一个后台进程。将它保存为 `src/Process/Ticker.php`：

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

构建项目并启动它：

```bash
skernel build
php bin/bootstrap.php swoole:start
```

它会在服务器的日志行旁边每秒打印一次 `Ticker in worker 0`。按 Ctrl+C 停止进程池和服务器。

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Process\Attribute\Process` | 注解 | 声明后台进程，可带名称、工作进程数量和协程选择。 |
| `SuperKernel\Process\Contract\ProcessInterface` | 接口 | 由后台进程实现：`enabled`、`enableCoroutine` 和 `execute()`。 |
| `SuperKernel\Process\Contract\ProcessManagerInterface` | 接口 | 注册进程，设置进程池的 IPC，并启动或停止进程池。 |
| `SuperKernel\Process\Manager\ProcessManager` | 类 | 管理器，用于在没有容器的情况下运行进程池。 |
| `SuperKernel\Process\Definition\ProcessDefinition` | 类 | 用自己的选项包装一个 `callable(Pool, int): void`。 |

## 需要了解的行为

- 进程池运行期间，任务返回、失败或什么也不做的工作进程会被再次启动；只有 `$pool->shutdown()`、`stop()` 或向进程池父进程发送 `SIGTERM` 才会结束进程池。
- 关闭会向每个工作进程发送信号，并等待它们全部结束，没有期限：忽略 `SIGTERM` 的工作进程会使进程池以及 `swoole:start` 无法停止。
- 在工作进程中启动进程池会以 Swoole 的 `A process pool instance has already been created and cannot be created again` 失败。
- `swoole:start` 在分发 `MainProcessCreated` 时复制已注册的进程；之后注册的进程不会启动。没有启用的进程时，不会启动进程池。
- 注册重复的名称会抛出 `LogicException`，例如 `Duplicate process name: mailer:0.`；用未知名称调用 `get()` 会抛出 `SuperKernel\Process\Exception\ProcessNotFoundException`。
- 进程从构建元数据中发现：添加、删除或修改 `#[Process]` 类之后，请重新运行 `skernel build`，或者用会先构建的 `skernel run` 启动。

## 文档

开发者文档介绍了声明与协程选择、管理器、进程池生命周期与 IPC、在主进程下运行、错误消息以及不要这样做的事项：[SuperKernel Process](https://github.com/super-kernel/.github/blob/main/docs/components/process.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
