<?php

namespace PHAPI\Tests;

use PHAPI\Services\SwooleMySqlClient;

final class SwooleMySqlClientTest extends SwooleTestCase
{
    public function testMySqlRequiresCoroutineContext(): void
    {
        $client = new SwooleMySqlClient([
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'password' => '',
            'database' => '',
            'charset' => 'utf8mb4',
            'timeout' => 1.0,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MySQL client requires a Swoole coroutine context.');

        $client->query('SELECT 1');
    }
}
