<?php

declare(strict_types=1);

namespace PHAPI\Tests;

use PHAPI\Services\MySqlPool;

final class MySqlPoolTest extends SwooleTestCase
{
    private function invalidPool(): MySqlPool
    {
        return new MySqlPool([
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
    }

    public function testMySqlPoolCanRunOutsideCoroutineContext(): void
    {
        $pool = $this->invalidPool();

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

    public function testQueryWithInvalidHostThrowsPdoException(): void
    {
        $pool = $this->invalidPool();

        $this->expectException(\PDOException::class);
        $pool->query('SELECT 1');
    }

    public function testExecuteWithInvalidHostThrowsPdoException(): void
    {
        $pool = $this->invalidPool();

        $this->expectException(\PDOException::class);
        $pool->execute('INSERT INTO t VALUES (1)');
    }

    public function testWithConnectionPropagatesException(): void
    {
        $pool = $this->invalidPool();

        $this->expectException(\PDOException::class);
        $pool->withConnection(fn (\PDO $pdo) => $pdo->query('SELECT 1'));
    }
}
