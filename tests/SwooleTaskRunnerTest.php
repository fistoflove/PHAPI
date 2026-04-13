<?php

namespace PHAPI\Tests;

use PHAPI\Services\SwooleTaskRunner;

final class SwooleTaskRunnerTest extends SwooleTestCase
{
    public function testParallelRunsOutsideCoroutine(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $results = $runner->parallel([
            'first' => static fn (): int => \Swoole\Coroutine::getCid(),
            'second' => static fn (): int => \Swoole\Coroutine::getCid(),
        ]);

        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertGreaterThan(0, $results['first']);
        $this->assertGreaterThan(0, $results['second']);
    }

    public function testParallelStartsTasksBeforeRelease(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $results = null;
        $started = [];

        \Swoole\Coroutine\run(function () use ($runner, &$results, &$started): void {
            $start = new \Swoole\Coroutine\Channel(2);
            $release = new \Swoole\Coroutine\Channel(2);

            \Swoole\Coroutine::create(function () use ($runner, $start, $release, &$results): void {
                $results = $runner->parallel([
                    'first' => static function () use ($start, $release): int {
                        $start->push('first');
                        $release->pop();
                        return \Swoole\Coroutine::getCid();
                    },
                    'second' => static function () use ($start, $release): int {
                        $start->push('second');
                        $release->pop();
                        return \Swoole\Coroutine::getCid();
                    },
                ]);
            });

            $started[] = $start->pop(1.0);
            $started[] = $start->pop(1.0);
            $release->push(true);
            $release->push(true);
        });

        $this->assertNotContains(false, $started);
        $this->assertIsArray($results);
        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertGreaterThan(0, $results['first']);
        $this->assertGreaterThan(0, $results['second']);
    }

    public function testParallelRespectsMaxConcurrency(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $maxObserved = 0;
        $active = 0;
        $results = null;

        \Swoole\Coroutine\run(function () use ($runner, &$results, &$maxObserved, &$active): void {
            $tasks = [];
            for ($i = 0; $i < 10; $i++) {
                $tasks["t{$i}"] = static function () use (&$maxObserved, &$active): int {
                    $active++;
                    if ($active > $maxObserved) {
                        $maxObserved = $active;
                    }
                    \Swoole\Coroutine::sleep(0.01);
                    $active--;
                    return \Swoole\Coroutine::getCid();
                };
            }
            $results = $runner->parallel($tasks, concurrency: 3);
        });

        $this->assertLessThanOrEqual(3, $maxObserved);
        $this->assertGreaterThan(0, $maxObserved);
        $this->assertIsArray($results);
        $this->assertCount(10, $results);
    }

    public function testParallelConcurrencyOneIsSequential(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $order = [];
        $results = null;

        \Swoole\Coroutine\run(function () use ($runner, &$results, &$order): void {
            $tasks = [];
            for ($i = 0; $i < 5; $i++) {
                $idx = $i;
                $tasks["t{$i}"] = static function () use ($idx, &$order): int {
                    $order[] = $idx;
                    \Swoole\Coroutine::sleep(0.001);
                    return $idx;
                };
            }
            $results = $runner->parallel($tasks, concurrency: 1);
        });

        $this->assertSame([0, 1, 2, 3, 4], $order);
        $this->assertIsArray($results);
        $this->assertCount(5, $results);
    }

    public function testParallelConcurrencyNullIsUnlimited(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner();
        $maxObserved = 0;
        $active = 0;
        $results = null;

        \Swoole\Coroutine\run(function () use ($runner, &$results, &$maxObserved, &$active): void {
            $tasks = [];
            for ($i = 0; $i < 5; $i++) {
                $tasks["t{$i}"] = static function () use (&$maxObserved, &$active): int {
                    $active++;
                    if ($active > $maxObserved) {
                        $maxObserved = $active;
                    }
                    \Swoole\Coroutine::sleep(0.02);
                    $active--;
                    return \Swoole\Coroutine::getCid();
                };
            }
            $results = $runner->parallel($tasks, concurrency: null);
        });

        // With null concurrency and sleep, all 5 should run at once
        $this->assertSame(5, $maxObserved);
        $this->assertIsArray($results);
        $this->assertCount(5, $results);
    }

    public function testParallelTimesOut(): void
    {
        if (!function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine runner not available.');
        }

        $runner = new SwooleTaskRunner(0.01);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task runner timed out.');

        $runner->parallel([
            'slow' => static function (): bool {
                \Swoole\Coroutine::sleep(0.05);
                return true;
            },
        ]);
    }
}
