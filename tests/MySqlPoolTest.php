<?php

namespace PHAPI\Tests;

use PHAPI\Services\MySqlPool;

final class MySqlPoolTest extends SwooleTestCase
{
    public function testMySqlRequiresCoroutineContext(): void
    {
        $pool = new MySqlPool([
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'password' => '',
            'database' => '',
            'charset' => 'utf8mb4',
            'timeout' => 1.0,
            'pool_size' => 1,
            'pool_timeout' => 0.01,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MySQL client requires a Swoole coroutine context.');

        $pool->query('SELECT 1');
    }
}
