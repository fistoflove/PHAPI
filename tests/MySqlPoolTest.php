<?php

namespace PHAPI\Tests;

use PHAPI\Services\MySqlPool;

final class MySqlPoolTest extends SwooleTestCase
{
    public function testMySqlPoolCanRunOutsideCoroutineContext(): void
    {
        $pool = new MySqlPool([
            'host' => '127.0.0.1',
            'port' => 1,
            'user' => 'root',
            'password' => '',
            'database' => '',
            'charset' => 'utf8mb4',
            'timeout' => 0.1,
            'pool_size' => 1,
            'pool_timeout' => 0.01,
        ]);

        try {
            $pool->query('SELECT 1');
            $this->assertTrue(true);
        } catch (\Throwable $exception) {
            $this->assertStringNotContainsString(
                'MySQL client requires a Swoole coroutine context.',
                $exception->getMessage()
            );
        }
    }
}
