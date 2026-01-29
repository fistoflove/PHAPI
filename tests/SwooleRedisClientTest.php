<?php

namespace PHAPI\Tests;

use PHAPI\Services\SwooleRedisClient;

final class SwooleRedisClientTest extends SwooleTestCase
{
    public function testRedisRequiresCoroutineContext(): void
    {
        if (!class_exists('Redis')) {
            $this->markTestSkipped('ext-redis is required for Redis client tests.');
        }

        $client = new SwooleRedisClient([
            'host' => '127.0.0.1',
            'port' => 6379,
            'auth' => null,
            'db' => null,
            'timeout' => 1.0,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis client requires a Swoole coroutine context.');

        $client->get('phapi:test');
    }
}
