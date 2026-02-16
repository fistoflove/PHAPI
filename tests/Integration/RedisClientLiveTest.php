<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration;

use PHAPI\Services\SwooleRedisClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests SwooleRedisClient against a real Redis server.
 * Requires: Redis running on 127.0.0.1:6379.
 */
final class RedisClientLiveTest extends TestCase
{
    private static array $config = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => null,
        'db' => 1, // Use DB 1 to avoid collisions
        'timeout' => 2.0,
    ];

    private SwooleRedisClient $redis;

    public static function setUpBeforeClass(): void
    {
        try {
            $r = new \Redis();
            $r->connect('127.0.0.1', 6379, 1.0);
            $r->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis not available: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->redis = new SwooleRedisClient(self::$config);
        // Clean test keys
        $this->redis->command('FLUSHDB');
    }

    protected function tearDown(): void
    {
        $this->redis->command('FLUSHDB');
    }

    // ── get / set ───────────────────────────────────────────

    public function testSetAndGet(): void
    {
        $result = $this->redis->set('test:key', 'hello');
        $this->assertTrue($result);

        $value = $this->redis->get('test:key');
        $this->assertSame('hello', $value);
    }

    public function testGetNonexistentReturnsNull(): void
    {
        $value = $this->redis->get('test:nonexistent');
        $this->assertNull($value);
    }

    public function testSetWithTtl(): void
    {
        $this->redis->set('test:ttl', 'expires', 10);

        $value = $this->redis->get('test:ttl');
        $this->assertSame('expires', $value);

        // Verify TTL was set
        $ttl = $this->redis->command('TTL', ['test:ttl']);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(10, $ttl);
    }

    public function testSetOverwritesExisting(): void
    {
        $this->redis->set('test:overwrite', 'first');
        $this->redis->set('test:overwrite', 'second');

        $this->assertSame('second', $this->redis->get('test:overwrite'));
    }

    // ── expire ──────────────────────────────────────────────

    public function testExpire(): void
    {
        $this->redis->set('test:exp', 'data');
        $result = $this->redis->expire('test:exp', 30);

        $this->assertTrue($result);
        $ttl = $this->redis->command('TTL', ['test:exp']);
        $this->assertGreaterThan(0, $ttl);
    }

    // ── del / exists ────────────────────────────────────────

    public function testDel(): void
    {
        $this->redis->set('test:del1', 'a');
        $this->redis->set('test:del2', 'b');

        $deleted = $this->redis->del('test:del1', 'test:del2');
        $this->assertSame(2, $deleted);

        $this->assertNull($this->redis->get('test:del1'));
        $this->assertNull($this->redis->get('test:del2'));
    }

    public function testDelNonexistent(): void
    {
        $deleted = $this->redis->del('test:nope');
        $this->assertSame(0, $deleted);
    }

    public function testExists(): void
    {
        $this->redis->set('test:exists', 'yes');

        $this->assertSame(1, $this->redis->exists('test:exists'));
        $this->assertSame(0, $this->redis->exists('test:nope'));
    }

    public function testExistsMultiple(): void
    {
        $this->redis->set('test:e1', 'a');
        $this->redis->set('test:e2', 'b');

        $count = $this->redis->exists('test:e1', 'test:e2', 'test:e3');
        $this->assertSame(2, $count);
    }

    // ── Hash operations ─────────────────────────────────────

    public function testHMSet(): void
    {
        $result = $this->redis->hMSet('test:hash', ['field1' => 'val1', 'field2' => 'val2']);
        $this->assertTrue($result);

        $this->assertSame('val1', $this->redis->hGet('test:hash', 'field1'));
        $this->assertSame('val2', $this->redis->hGet('test:hash', 'field2'));
    }

    public function testHSet(): void
    {
        $result = $this->redis->hSet('test:hash2', 'myfield', 'myvalue');
        $this->assertTrue($result);

        $this->assertSame('myvalue', $this->redis->hGet('test:hash2', 'myfield'));
    }

    public function testHGetNonexistent(): void
    {
        $result = $this->redis->hGet('test:hash3', 'nope');
        $this->assertFalse($result);
    }

    public function testHIncrBy(): void
    {
        $this->redis->hSet('test:counter', 'hits', '10');

        $newVal = $this->redis->hIncrBy('test:counter', 'hits', 5);
        $this->assertSame(15, $newVal);

        $newVal = $this->redis->hIncrBy('test:counter', 'hits', -3);
        $this->assertSame(12, $newVal);
    }

    public function testHIncrByCreatesField(): void
    {
        $newVal = $this->redis->hIncrBy('test:newcounter', 'views', 1);
        $this->assertSame(1, $newVal);
    }

    // ── Sorted set operations ───────────────────────────────

    public function testZAddAndZRangeByScore(): void
    {
        $this->redis->zAdd('test:zset', 10, 'alice');
        $this->redis->zAdd('test:zset', 20, 'bob');
        $this->redis->zAdd('test:zset', 30, 'charlie');

        $members = $this->redis->zRangeByScore('test:zset', 15, 35);
        $this->assertSame(['bob', 'charlie'], $members);
    }

    public function testZRangeByScoreEmpty(): void
    {
        $this->redis->zAdd('test:zset2', 10, 'only');

        $members = $this->redis->zRangeByScore('test:zset2', 20, 30);
        $this->assertSame([], $members);
    }

    public function testZRemRangeByScore(): void
    {
        $this->redis->zAdd('test:zrem', 1, 'low');
        $this->redis->zAdd('test:zrem', 5, 'mid');
        $this->redis->zAdd('test:zrem', 10, 'high');

        $removed = $this->redis->zRemRangeByScore('test:zrem', 1, 5);
        $this->assertSame(2, $removed);

        $remaining = $this->redis->zRangeByScore('test:zrem', 0, 100);
        $this->assertSame(['high'], $remaining);
    }

    // ── command() (raw) ─────────────────────────────────────

    public function testRawCommand(): void
    {
        $this->redis->set('test:raw', 'value');

        // rawcommand('GET', ...) returns the value
        $result = $this->redis->command('GET', ['test:raw']);
        $this->assertSame('value', $result);
    }

    public function testRawCommandPing(): void
    {
        $result = $this->redis->command('PING');
        // Redis returns true or "+PONG"
        $this->assertNotFalse($result);
    }

    // ── Coroutine isolation ─────────────────────────────────

    public function testCoroutineIsolation(): void
    {
        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            $redis = new SwooleRedisClient(self::$config);
            $redis->command('FLUSHDB');

            $wg = new \Swoole\Coroutine\WaitGroup();

            for ($i = 0; $i < 4; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($redis, $i, &$results, $wg) {
                    try {
                        $key = "test:coro:$i";
                        $redis->set($key, "val-$i");
                        $results[$i] = $redis->get($key);
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait();
        });

        $this->assertCount(4, $results);
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame("val-$i", $results[$i]);
        }
    }
}
