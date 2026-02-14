<?php

namespace PHAPI\Tests;

use PHAPI\Services\SwooleRedisClient;

final class SwooleRedisClientTest extends SwooleTestCase
{
    public function testRedisClientCanRunOutsideCoroutineContext(): void
    {
        if (!class_exists('Redis')) {
            $this->markTestSkipped('ext-redis is required for Redis client tests.');
        }

        $client = new SwooleRedisClient([
            'host' => '127.0.0.1',
            'port' => 1,
            'auth' => null,
            'db' => null,
            'timeout' => 0.1,
        ]);

        try {
            $client->get('phapi:test');
            $this->assertTrue(true);
        } catch (\Throwable $exception) {
            $this->assertStringNotContainsString(
                'Redis client requires a Swoole coroutine context.',
                $exception->getMessage()
            );
        }
    }
}
