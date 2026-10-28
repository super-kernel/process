<?php

declare(strict_types=1);

namespace SuperKernel\Process\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperKernel\Process\Tests\Fixture\Scenario;
use Throwable;

use function array_column;
use function array_filter;
use function array_values;
use function bin2hex;
use function dirname;
use function fclose;
use function file;
use function file_get_contents;
use function glob;
use function hrtime;
use function in_array;
use function is_file;
use function json_decode;
use function mkdir;
use function posix_kill;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function random_bytes;
use function rmdir;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use function var_export;

use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const SIGKILL;

final class NativeProcessTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        foreach ([
            'empty',
            'batch',
            'mixed',
            'restart-return',
            'restart-throw',
            'restart-coroutine-return',
            'restart-coroutine-throw',
            'external-stop',
            'supervised-stop',
            'manager-stop',
            'nested-pool',
            'ipc-unixsock',
            'ipc-msgqueue',
        ] as $scenario) {
            yield $scenario => [
                $scenario,
            ];
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('scenarios')]
    public function testPoolRegistrationReplacementShutdownAndReaping(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/sk-pool-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $bootstrap = '$loader = require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$tests = new Composer\\Autoload\\ClassLoader();'
            . '$tests->addPsr4("SuperKernel\\\\Process\\\\Tests\\\\", '
            . var_export(dirname(__DIR__), true) . '); $tests->register(true); posix_setsid();'
            . Scenario::class . '::run(' . var_export($scenario, true) . ', ' . var_export($directory, true) . ');';
        $process = proc_open([
            PHP_BINARY,
            '-r',
            $bootstrap,
        ], [
            0 => [
                'pipe',
                'r',
            ],
            1 => [
                'file',
                $directory . '/stdout',
                'a',
            ],
            2 => [
                'file',
                $directory . '/stderr',
                'a',
            ],
        ], $pipes);
        fclose($pipes[0]);
        $pid = proc_get_status($process)['pid'];
        try {
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $state = proc_get_status($process);
                $events = $this->readEvents($directory);
                $done = [] !== $this->events($events, 'reaped');
                if ($done && !$state['running']) {
                    break;
                }
                if (!$state['running']) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);

            self::assertNotEmpty($this->events($events, 'reaped'), file_get_contents($directory . '/stderr'));
            self::assertSame(-1, $this->events($events, 'reaped')[0]['remainingChild']);
            self::assertCount(1, $this->events($events, 'returned'));
            self::assertSame([], $this->events($events, 'expired'));
            $returned = $this->events($events, 'returned')[0];
            self::assertFileDoesNotExist($directory . '/main.pid');
            foreach ($this->events($events, 'started') as $worker) {
                self::assertSame($worker['parent'], $worker['masterPid']);
                if ('supervised-stop' === $scenario) {
                    self::assertNotSame($returned['pid'], $worker['parent']);
                } else {
                    self::assertSame($returned['pid'], $worker['parent']);
                }
            }
            foreach ($this->events($events, 'stopped') as $worker) {
                self::assertLessThanOrEqual($returned['time'], $worker['time']);
                if ('supervised-stop' === $scenario) {
                    self::assertSame($returned['pid'], $worker['mainPid']);
                }
            }
            if ('supervised-stop' === $scenario) {
                self::assertSame($returned['pid'], $this->events($events, 'supervised')[0]['published']);
            }
            if (in_array($scenario, [
                'batch',
                'mixed',
                'external-stop',
                'supervised-stop',
                'manager-stop',
            ], true)) {
                self::assertCount(2, $this->events($events, 'started'));
                self::assertCount(2, $this->events($events, 'stopped'));
                foreach ($this->events($events, 'started') as $worker) {
                    self::assertSame('mixed' === $scenario && 1 === $worker['workerId'], $worker['coroutine']);
                }
                self::assertSame(
                    'mixed' === $scenario ? [
                    'synchronous',
                    'coroutine',
                ] : [
                    'batch:0',
                    'batch:1',
                ],
                    $returned['names'],
                );
            }
            if (str_starts_with($scenario, 'restart-')) {
                $attempts = $this->events($events, 'attempt');
                self::assertCount(2, $attempts);
                self::assertSame([
                    1,
                    2,
                ], array_column($attempts, 'generation'));
                self::assertSame([
                    0,
                    0,
                ], array_column($attempts, 'workerId'));
                self::assertNotSame($attempts[0]['pid'], $attempts[1]['pid']);
                self::assertSame($returned['pid'], $attempts[1]['parent']);
                self::assertSame(str_contains($scenario, 'coroutine'), $attempts[1]['coroutine']);
                self::assertCount(1, $this->events($events, 'started'));
                self::assertCount(1, $this->events($events, 'stopped'));
                self::assertSame([
                    'restarted',
                    'sibling',
                ], $returned['names']);
            }
            if ('nested-pool' === $scenario) {
                self::assertCount(1, $this->events($events, 'nested-rejected'));
                self::assertStringContainsString(
                    'already been created',
                    $this->events($events, 'nested-rejected')[0]['message'],
                );
            }
            if (str_starts_with($scenario, 'ipc-')) {
                self::assertCount(1, $this->events($events, 'message'));
                self::assertSame('pool-message', $this->events($events, 'message')[0]['value']);
            }
        } finally {
            // Every scenario runs in its own session; only kill that test's group on incomplete cleanup.
            if (proc_get_status($process)['running']) {
                posix_kill(-$pid, SIGKILL);
            }
            proc_close($process);
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private function readEvents(string $directory): array
    {
        if (!is_file($directory . '/events.jsonl')) {
            return [];
        }
        $events = [];
        foreach (file($directory . '/events.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
            $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function events(array $events, string $event): array
    {
        return array_values(array_filter($events, static fn(array $entry): bool => $event === $entry['event']));
    }
}
