<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Services\MySqlPool;

/**
 * Tests MySqlPool coroutine safety: pool exhaustion, current() pinning,
 * borrow outside coroutine, release when full.
 *
 * These tests exercise the pool's Channel-based connection management
 * without requiring a real MySQL server. We use reflection to inject
 * mock PDO connections into the pool's Channel directly.
 *
 * @requires extension pdo_sqlite
 */
final class MySqlPoolCoroutineTest extends SwooleTestCase
{
    private function poolConfig(int $poolSize = 2, float $poolTimeout = 0.05): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 1,
            'user' => 'root',
            'password' => '',
            'database' => '',
            'charset' => 'utf8mb4',
            'timeout' => 0.01,
            'pool_size' => $poolSize,
            'pool_timeout' => $poolTimeout,
        ];
    }

    /**
     * Pre-fill the pool's Channel with fake PDO connections so we don't
     * need a real MySQL server.
     */
    private function seedPool(MySqlPool $pool, int $count): void
    {
        $poolRef = new \ReflectionMethod($pool, 'pool');
        $poolRef->setAccessible(true);
        $channel = $poolRef->invoke($pool);

        $createdRef = new \ReflectionProperty($pool, 'created');
        $createdRef->setAccessible(true);

        for ($i = 0; $i < $count; $i++) {
            $pdo = new \PDO('sqlite::memory:');
            $channel->push($pdo);
        }
        $createdRef->setValue($pool, $count);
    }

    // --- 2a. Pool exhaustion and timeout ---

    public function testPoolTimeoutWhenExhausted(): void
    {
        $pool = new MySqlPool($this->poolConfig(poolSize: 1, poolTimeout: 0.05));

        $timedOut = false;
        $message = '';

        \Swoole\Coroutine\run(function () use ($pool, &$timedOut, &$message): void {
            $this->seedPool($pool, 1);

            // Borrow the only connection
            $conn = $pool->acquire();
            $this->assertInstanceOf(\PDO::class, $conn);

            // Second borrow in a different coroutine should timeout
            $wg = new \Swoole\Coroutine\WaitGroup();
            $wg->add();
            \Swoole\Coroutine::create(function () use ($pool, &$timedOut, &$message, $wg): void {
                try {
                    $pool->acquire();
                } catch (\RuntimeException $e) {
                    $timedOut = true;
                    $message = $e->getMessage();
                }
                $wg->done();
            });
            $wg->wait();

            $pool->releaseConnection($conn);
        });

        $this->assertTrue($timedOut, 'Expected pool timeout');
        $this->assertStringContainsString('timed out', $message);
    }

    // --- 2b. current() pins connection to coroutine ---

    public function testCurrentReturnsSameConnectionInSameCoroutine(): void
    {
        $pool = new MySqlPool($this->poolConfig(poolSize: 2));
        $same = false;

        \Swoole\Coroutine\run(function () use ($pool, &$same): void {
            $this->seedPool($pool, 2);

            $first = $pool->current();
            $second = $pool->current();
            $same = ($first === $second);
        });

        $this->assertTrue($same, 'current() should return the same PDO within a coroutine');
    }

    public function testCurrentReturnsDifferentConnectionsAcrossCoroutines(): void
    {
        $pool = new MySqlPool($this->poolConfig(poolSize: 2));
        $ids = [];

        \Swoole\Coroutine\run(function () use ($pool, &$ids): void {
            $this->seedPool($pool, 2);

            $wg = new \Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 2; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($pool, $i, &$ids, $wg): void {
                    $pdo = $pool->current();
                    $ids[$i] = spl_object_id($pdo);
                    \Swoole\Coroutine::sleep(0.01); // hold the connection
                    $wg->done();
                });
            }
            $wg->wait();
        });

        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1], 'Different coroutines should get different connections');
    }

    // --- 2c. borrow() outside coroutine context ---

    public function testBorrowOutsideCoroutineReturnsSameSharedClient(): void
    {
        $pool = new MySqlPool($this->poolConfig(poolSize: 1));

        // Outside coroutine, borrow() uses sharedClient.
        // This will fail to connect (invalid host), but both calls should
        // fail the same way — proving it tries to reuse the shared client.
        $exception1 = null;
        $exception2 = null;

        try {
            $pool->acquire();
        } catch (\PDOException $e) {
            $exception1 = $e->getMessage();
        }

        try {
            $pool->acquire();
        } catch (\PDOException $e) {
            $exception2 = $e->getMessage();
        }

        $this->assertNotNull($exception1);
        $this->assertSame($exception1, $exception2, 'Both calls should hit the same code path');
    }

    // --- 2d. release() when pool is full ---

    public function testReleaseWhenPoolFullDoesNotBlock(): void
    {
        $pool = new MySqlPool($this->poolConfig(poolSize: 1));
        $completed = false;

        \Swoole\Coroutine\run(function () use ($pool, &$completed): void {
            $this->seedPool($pool, 1);

            $conn = $pool->acquire();
            $pool->releaseConnection($conn);

            // Release again — pool is already full, should silently drop
            $pool->releaseConnection($conn);

            $completed = true;
        });

        $this->assertTrue($completed, 'Double release should not block or crash');
    }

    // --- 2e. Connection released after coroutine ends (via defer) ---

    public function testCurrentConnectionReleasedAfterCoroutineEnds(): void
    {
        $pool = new MySqlPool($this->poolConfig(poolSize: 1));
        $secondAcquired = false;

        \Swoole\Coroutine\run(function () use ($pool, &$secondAcquired): void {
            $this->seedPool($pool, 1);

            // First coroutine: acquire via current(), which uses defer to release
            $wg = new \Swoole\Coroutine\WaitGroup();
            $wg->add();
            \Swoole\Coroutine::create(function () use ($pool, $wg): void {
                $pool->current(); // pinned + defer release
                $wg->done();
            });
            $wg->wait();
            // After coroutine ends, defer should have released the connection

            // Second coroutine: should be able to acquire
            $wg->add();
            \Swoole\Coroutine::create(function () use ($pool, &$secondAcquired, $wg): void {
                try {
                    $pool->current();
                    $secondAcquired = true;
                } catch (\RuntimeException $e) {
                    // pool timed out — connection wasn't released
                    $secondAcquired = false;
                }
                $wg->done();
            });
            $wg->wait();
        });

        $this->assertTrue($secondAcquired, 'Connection should be released back to pool after coroutine ends');
    }
}
