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
            $message = (string)$client->getLastError();
            $error = $message !== '' ? $message : 'Unable to connect to Redis.';
            throw new \RuntimeException($error);
        }

        $auth = $this->config['auth'];
        if ($auth !== null && $auth !== '') {
            if ($client->auth($auth) === false) {
                $message = (string)$client->getLastError();
                $error = $message !== '' ? $message : 'Redis auth failed.';
                throw new \RuntimeException($error);
            }
        }

        $db = $this->config['db'];
        if ($db !== null) {
            if ($client->select($db) === false) {
                $message = (string)$client->getLastError();
                $error = $message !== '' ? $message : 'Redis select failed.';
                throw new \RuntimeException($error);
            }
        }

        $this->clients[$cid] = $client;
        /** @phpstan-ignore-next-line */
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
        return $client->isConnected();
    }

    public function get(string $key): ?string
    {
        $value = $this->connect()->get($key);
        return $value === false ? null : (string)$value;
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        if ($ttl !== null) {
            return $this->connect()->setex($key, $ttl, $value);
        }

        return $this->connect()->set($key, $value);
    }

    public function expire(string $key, int $ttl): bool
    {
        return $this->connect()->expire($key, $ttl);
    }

    /**
     * @param array<string, string> $data
     */
    public function hMSet(string $key, array $data): bool
    {
        return $this->connect()->hMset($key, $data);
    }

    public function hSet(string $key, string $field, string $value): bool
    {
        return (bool)$this->connect()->hSet($key, $field, $value);
    }

    public function hIncrBy(string $key, string $field, int $value): int
    {
        return (int)$this->connect()->hIncrBy($key, $field, $value);
    }

    public function hGet(string $key, string $field): string|false
    {
        return $this->connect()->hGet($key, $field);
    }

    public function zAdd(string $key, int $score, string $member): int
    {
        return (int)$this->connect()->zAdd($key, $score, $member);
    }

    public function zRemRangeByScore(string $key, int $min, int $max): int
    {
        return (int)$this->connect()->zRemRangeByScore($key, (string)$min, (string)$max);
    }

    /**
     * @return array<int, string>
     */
    public function zRangeByScore(string $key, int $min, int $max): array
    {
        $result = $this->connect()->zRangeByScore($key, (string)$min, (string)$max);
        return is_array($result) ? array_values($result) : [];
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
        return $this->connect()->rawcommand($command, ...$args);
    }
}
