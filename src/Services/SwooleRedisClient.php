<?php

declare(strict_types=1);

namespace PHAPI\Services;

final class SwooleRedisClient
{
    /**
     * @var array{host: string, port: int, auth: string|null, db: int|null, timeout: float}
     */
    private array $config;

    /**
     * @var array<int, \Redis>
     */
    private array $clients = [];

    /**
     * @param array{host: string, port: int, auth: string|null, db: int|null, timeout: float} $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return \Redis
     */
    private function connect(): \Redis
    {
        if (!class_exists('Swoole\\Coroutine')) {
            throw new \RuntimeException('Swoole coroutines are not available.');
        }

        $cid = \Swoole\Coroutine::getCid();
        if ($cid < 0) {
            throw new \RuntimeException('Redis client requires a Swoole coroutine context.');
        }

        if (!class_exists('Redis')) {
            throw new \RuntimeException('ext-redis is required for Redis support.');
        }

        if (isset($this->clients[$cid]) && $this->isConnected($this->clients[$cid])) {
            return $this->clients[$cid];
        }

        $client = new \Redis();
        $connected = $client->connect($this->config['host'], $this->config['port'], $this->config['timeout']);
        if ($connected === false) {
            $message = method_exists($client, 'getLastError') ? (string)$client->getLastError() : '';
            $error = $message !== '' ? $message : 'Unable to connect to Redis.';
            throw new \RuntimeException($error);
        }

        $auth = $this->config['auth'];
        if ($auth !== null && $auth !== '') {
            if ($client->auth($auth) === false) {
                $message = method_exists($client, 'getLastError') ? (string)$client->getLastError() : '';
                $error = $message !== '' ? $message : 'Redis auth failed.';
                throw new \RuntimeException($error);
            }
        }

        $db = $this->config['db'];
        if ($db !== null) {
            if ($client->select($db) === false) {
                $message = method_exists($client, 'getLastError') ? (string)$client->getLastError() : '';
                $error = $message !== '' ? $message : 'Redis select failed.';
                throw new \RuntimeException($error);
            }
        }

        $this->clients[$cid] = $client;
        \Swoole\Coroutine::defer(function () use ($cid, $client): void {
            if (isset($this->clients[$cid])) {
                $client->close();
                unset($this->clients[$cid]);
            }
        });

        return $client;
    }

    private function isConnected(\Redis $client): bool
    {
        return method_exists($client, 'isConnected') ? $client->isConnected() : true;
    }

    public function get(string $key): ?string
    {
        $value = $this->connect()->get($key);
        return $value === false ? null : (string)$value;
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        if ($ttl !== null) {
            return $this->connect()->setEx($key, $ttl, $value);
        }

        return $this->connect()->set($key, $value);
    }

    public function del(string ...$keys): int
    {
        return $this->connect()->del(array_values($keys));
    }

    public function exists(string ...$keys): int
    {
        return $this->connect()->exists(array_values($keys));
    }

    /**
     * @param string $command
     * @param array<int, mixed> $args
     * @return mixed
     */
    public function command(string $command, array $args = [])
    {
        return $this->connect()->rawCommand($command, ...$args);
    }
}
