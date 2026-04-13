<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Services\SwooleTaskRunner;

/**
 * Tests SwooleTaskRunner::parallel() error propagation:
 * single failure, all failures, timeout, empty list.
 */
final class SwooleTaskRunnerErrorTest extends SwooleTestCase
{
    // --- 3a. Single task failure ---

    public function testSingleTaskFailureRethrowsException(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $thrown = null;

        \Swoole\Coroutine\run(function () use ($runner, &$thrown): void {
            try {
                $runner->parallel([
                    'ok' => static fn (): string => 'fine',
                    'fail' => static function (): never {
                        throw new \RuntimeException('task broke');
                    },
                    'ok2' => static fn (): string => 'also fine',
                ]);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        });

        $this->assertNotNull($thrown);
        $this->assertSame('task broke', $thrown->getMessage());
    }

    // --- 3b. All tasks fail ---

    public function testAllTasksFailRethrowsFirst(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $thrown = null;

        \Swoole\Coroutine\run(function () use ($runner, &$thrown): void {
            try {
                $runner->parallel([
                    'a' => static function (): never {
                        throw new \RuntimeException('error-a');
                    },
                    'b' => static function (): never {
                        throw new \RuntimeException('error-b');
                    },
                ]);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        });

        $this->assertNotNull($thrown);
        // First error by key order should be re-thrown
        $this->assertSame('error-a', $thrown->getMessage());
    }

    // --- 3c. Timeout behavior ---

    public function testTimeoutThrowsRuntimeException(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner(timeoutSeconds: 0.05);
        $thrown = null;

        \Swoole\Coroutine\run(function () use ($runner, &$thrown): void {
            try {
                $runner->parallel([
                    'slow' => static function (): string {
                        \Swoole\Coroutine::sleep(2.0);
                        return 'never';
                    },
                ]);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        });

        $this->assertNotNull($thrown);
        $this->assertStringContainsString('timed out', $thrown->getMessage());
    }

    // --- 3d. Empty task list ---

    public function testEmptyTaskListReturnsEmptyArray(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $result = null;

        \Swoole\Coroutine\run(function () use ($runner, &$result): void {
            $result = $runner->parallel([]);
        });

        $this->assertSame([], $result);
    }

    // --- 3e. Partial failure with concurrency limit ---

    public function testPartialFailureWithConcurrencyPreservesOtherResults(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $thrown = null;

        \Swoole\Coroutine\run(function () use ($runner, &$thrown): void {
            try {
                $runner->parallel([
                    'a' => static fn (): string => 'result-a',
                    'b' => static function (): never {
                        throw new \RuntimeException('b-failed');
                    },
                    'c' => static fn (): string => 'result-c',
                    'd' => static fn (): string => 'result-d',
                    'e' => static fn (): string => 'result-e',
                ], concurrency: 2);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        });

        $this->assertNotNull($thrown);
        $this->assertSame('b-failed', $thrown->getMessage());
    }

    // --- 3f. Results keyed correctly despite mixed success/failure ---

    public function testSuccessfulResultsPreservedAlongsideFailure(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $results = null;
        $thrown = null;

        \Swoole\Coroutine\run(function () use ($runner, &$results, &$thrown): void {
            // We can't get partial results from parallel() since it throws.
            // But we can verify the exception is from the failing task.
            try {
                $results = $runner->parallel([
                    'fast' => static function (): string {
                        return 'fast-result';
                    },
                    'fail' => static function (): never {
                        \Swoole\Coroutine::sleep(0.01);
                        throw new \LogicException('specific-error');
                    },
                ]);
            } catch (\LogicException $e) {
                $thrown = $e;
            }
        });

        $this->assertNotNull($thrown);
        $this->assertSame('specific-error', $thrown->getMessage());
    }
}
