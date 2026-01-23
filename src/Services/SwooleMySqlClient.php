<?php

declare(strict_types=1);

namespace PHAPI\Services;

final class SwooleMySqlClient
{
    /**
     * @var array{host: string, port: int, user: string, password: string, database: string, charset: string, timeout: float}
     */
    private array $config;

    private ?\Swoole\Coroutine\MySQL $client = null;

    /**
     * @param array{host: string, port: int, user: string, password: string, database: string, charset: string, timeout: float} $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return \Swoole\Coroutine\MySQL
     */
    private function connect(): \Swoole\Coroutine\MySQL
    {
        if ($this->client !== null && $this->client->connected) {
            return $this->client;
        }

        if (\Swoole\Coroutine::getCid() < 0) {
            throw new \RuntimeException('MySQL client requires a Swoole coroutine context.');
        }

        $client = new \Swoole\Coroutine\MySQL();
        $connected = $client->connect([
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'user' => $this->config['user'],
            'password' => $this->config['password'],
            'database' => $this->config['database'],
            'charset' => $this->config['charset'],
            'timeout' => $this->config['timeout'],
        ]);

        if ($connected === false) {
            $error = $client->connect_error !== '' ? $client->connect_error : 'Unable to connect to MySQL.';
            throw new \RuntimeException($error);
        }

        $this->client = $client;

        return $client;
    }

    /**
     * @param string $sql
     * @return array<int, array<string, mixed>>|bool
     */
    public function query(string $sql)
    {
        return $this->connect()->query($sql);
    }

    /**
     * @param string $sql
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>|bool
     */
    public function execute(string $sql, array $params = [])
    {
        if ($params === []) {
            return $this->query($sql);
        }

        $statement = $this->connect()->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('MySQL prepare failed.');
        }

        return $statement->execute($params);
    }

    public function begin(): bool
    {
        return (bool)$this->connect()->query('BEGIN');
    }

    public function commit(): bool
    {
        return (bool)$this->connect()->query('COMMIT');
    }

    public function rollback(): bool
    {
        return (bool)$this->connect()->query('ROLLBACK');
    }
}
