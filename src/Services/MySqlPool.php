<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PDO;
use Swoole\Coroutine\Channel;

final class MySqlPool
{
    /**
     * @var array{host: string, port: int, user: string, password: string, database: string, charset: string, timeout: float, pool_size: int, pool_timeout: float}
     */
    private array $config;

    private int $created = 0;
    private ?Channel $pool = null;

    /**
     * @param array{host: string, port: int, user: string, password: string, database: string, charset: string, timeout: float, pool_size: int, pool_timeout: float} $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->withConnection(function (PDO $pdo) use ($sql, $params): array {
            if ($params === []) {
                $statement = $pdo->query($sql);
                if ($statement === false) {
                    return [];
                }
                return $statement->fetchAll(PDO::FETCH_ASSOC);
            }

            $statement = $pdo->prepare($sql);
            $this->bindParams($statement, $params);
            $statement->execute();
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    /**
     * @param array<int, mixed> $params
     */
    public function execute(string $sql, array $params = []): bool
    {
        return $this->withConnection(function (PDO $pdo) use ($sql, $params): bool {
            if ($params === []) {
                return $pdo->exec($sql) !== false;
            }

            $statement = $pdo->prepare($sql);
            $this->bindParams($statement, $params);
            return $statement->execute();
        });
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function withConnection(callable $callback)
    {
        $pdo = $this->borrow();
        try {
            return $callback($pdo);
        } finally {
            $this->release($pdo);
        }
    }

    private function borrow(): PDO
    {
        if (!class_exists('Swoole\\Coroutine')) {
            throw new \RuntimeException('Swoole coroutines are not available.');
        }

        if (\Swoole\Coroutine::getCid() < 0) {
            throw new \RuntimeException('MySQL client requires a Swoole coroutine context.');
        }

        $pool = $this->pool();
        if ($this->poolIsEmpty($pool) && $this->created < $this->config['pool_size']) {
            $this->created++;
            return $this->createConnection();
        }

        $timeout = $this->config['pool_timeout'];
        $client = $pool->pop($timeout);
        if (!$client instanceof PDO) {
            throw new \RuntimeException('MySQL pool timed out waiting for an available connection.');
        }

        return $client;
    }

    private function release(PDO $pdo): void
    {
        $pool = $this->pool();
        if ($this->poolIsFull($pool)) {
            return;
        }
        $pool->push($pdo);
    }

    private function pool(): Channel
    {
        if ($this->pool !== null) {
            return $this->pool;
        }

        $size = max(1, $this->config['pool_size']);
        $this->pool = new Channel($size);
        return $this->pool;
    }

    private function poolIsEmpty(Channel $pool): bool
    {
        /** @phpstan-ignore-next-line */
        return $pool->length() === 0;
    }

    private function poolIsFull(Channel $pool): bool
    {
        /** @phpstan-ignore-next-line */
        return $pool->length() >= max(1, $this->config['pool_size']);
    }

    private function createConnection(): PDO
    {
        if (!class_exists('PDO')) {
            throw new \RuntimeException('ext-pdo_mysql is required for MySQL support.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset']
        );

        return new PDO(
            $dsn,
            $this->config['user'],
            $this->config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => (int)$this->config['timeout'],
            ]
        );
    }

    /**
     * @param array<int, mixed> $params
     */
    private function bindParams(\PDOStatement $statement, array $params): void
    {
        $index = 1;
        foreach ($params as $param) {
            if (is_array($param) && isset($param[0], $param[1]) && is_int($param[1])) {
                $statement->bindValue($index, $param[0], $param[1]);
            } elseif (is_array($param) && isset($param['value'], $param['type']) && is_int($param['type'])) {
                $statement->bindValue($index, $param['value'], $param['type']);
            } else {
                $statement->bindValue($index, $param);
            }
            $index++;
        }
    }
}
