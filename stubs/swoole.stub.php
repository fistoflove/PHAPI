<?php

namespace Swoole;

class Server
{
    public function on(string $event, callable $handler): void
    {
    }
    public function start(): void
    {
    }
    public function task(mixed $data): bool
    {
        return true;
    }
    public function finish(string $data): void
    {
    }
}

class Timer
{
    public static function tick(int $ms, callable $handler): int
    {
        return 1;
    }
    public static function clear(int $timerId): void
    {
    }
}

class Coroutine
{
    public static function create(callable $fn, mixed ...$args): int
    {
        return 0;
    }
    public static function getCid(): int
    {
        return 0;
    }
    public static function sleep(float $seconds): void
    {
    }
}

namespace Swoole\Coroutine;

class WaitGroup
{
    public function add(int $count = 1): void
    {
    }
    public function done(): void
    {
    }
    public function wait(float $timeout = -1): bool
    {
        return true;
    }
}

class Channel
{
    public function __construct(int $size = 0)
    {
    }
    public function push(mixed $data, float $timeout = -1): bool
    {
        return true;
    }
    public function pop(float $timeout = -1): mixed
    {
        return null;
    }
}

class System
{
    public static function writeFile(string $filename, string $content, int $flags = 0): bool
    {
        return true;
    }
}

namespace Swoole\Coroutine\Http;

class Client
{
    public string $body = '';
    public int $statusCode = 0;
    public function __construct(string $host, int $port, bool $ssl = false)
    {
    }
    /** @param array<string, mixed> $settings */
    public function set(array $settings): void
    {
    }
    public function post(string $path, string $data): bool
    {
        return true;
    }
    public function get(string $path): bool
    {
        return true;
    }
    public function close(): void
    {
    }
}

namespace Swoole\Coroutine;

function run(callable $fn): void
{
}

namespace Swoole\WebSocket;

class Server extends \Swoole\Server
{
    public function __construct(string $host, int $port)
    {
    }
    public function push(int $fd, string $data, int $opcode = 1, bool $finish = true): bool
    {
        return true;
    }
}

namespace Swoole\Http;

class Server extends \Swoole\Server
{
    public function __construct(string $host, int $port)
    {
    }
}
