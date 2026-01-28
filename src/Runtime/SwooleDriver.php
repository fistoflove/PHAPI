<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\Contracts\WebSocketDriverInterface;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\HttpKernel;

class SwooleDriver implements RuntimeInterface, WebSocketDriverInterface
{
    private string $host;
    private int $port;
    private bool $enableWebSockets;
    private string $runtimeName;
    private Capabilities $capabilities;
    private ?\Swoole\Server $server = null;
    private bool $started = false;
    /**
     * @var array<int, array{channels: array<string, bool>}>
     */
    private array $connections = [];
    /**
     * @var array<int, callable(\Swoole\Server, int): void>
     */
    private array $onWorkerStartHandlers = [];
    /**
     * @var array<int, array<int, array{factory: callable(): mixed, on_start: (callable(\Swoole\Process): void)|null}>>
     */
    private array $processFactoriesByWorker = [];
    /**
     * @var callable(): void|null
     */
    private $onBoot = null;
    /**
     * @var callable(): void|null
     */
    private $onShutdown = null;
    /**
     * @var callable(Request): void|null
     */
    private $onRequestStart = null;
    /**
     * @var callable(Request, Response): void|null
     */
    private $onRequestEnd = null;
    /**
     * @var callable(\Swoole\WebSocket\Server, mixed, self): void|null
     */
    private $webSocketHandler = null;

    /**
     * Configure the Swoole server host/port and WebSocket support.
     *
     * @param string $host
     * @param int $port
     * @param bool $enableWebSockets
     * @param string $runtimeName
     * @return void
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 9501,
        bool $enableWebSockets = false,
        string $runtimeName = 'swoole'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->enableWebSockets = $enableWebSockets;
        $this->runtimeName = $runtimeName;
        $this->capabilities = new Capabilities(true, $enableWebSockets, true, true);
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return $this->runtimeName;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsWebSockets(): bool
    {
        return $this->capabilities->supportsWebSockets();
    }

    /**
     * {@inheritDoc}
     */
    public function isLongRunning(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @param HttpKernel $kernel
     * @return void
     */
    public function start(HttpKernel $kernel): void
    {
        $this->started = true;
        if ($this->onBoot !== null) {
            ($this->onBoot)();
        }
        if ($this->enableWebSockets) {
            $server = new \Swoole\WebSocket\Server($this->host, $this->port);
            $this->server = $server;

            $server->on('open', function (\Swoole\WebSocket\Server $server, $request) {
                $this->connections[$request->fd] = ['channels' => []];
            });

            $server->on('close', function (\Swoole\WebSocket\Server $server, int $fd) {
                unset($this->connections[$fd]);
            });

            $server->on('message', function (\Swoole\WebSocket\Server $server, $frame) {
                if ($this->webSocketHandler !== null) {
                    $handler = $this->webSocketHandler;
                    $handler($server, $frame, $this);
                }
            });

            if ($this->onWorkerStartHandlers !== []) {
                $handlers = $this->onWorkerStartHandlers;
                $server->on('workerStart', function ($server, int $workerId) use ($handlers) {
                    foreach ($handlers as $handler) {
                        $handler($server, $workerId);
                    }
                    $this->startProcessesForWorker($workerId);
                });
            }

            if ($this->onShutdown !== null) {
                $handler = $this->onShutdown;
                $server->on('shutdown', function () use ($handler) {
                    $handler();
                });
            }

            $server->on('request', function ($request, $response) use ($kernel) {
                $httpRequest = $this->buildRequest($request);
                if ($this->onRequestStart !== null) {
                    ($this->onRequestStart)($httpRequest);
                }
                $httpResponse = $kernel->handle($httpRequest);
                $this->emit($response, $httpResponse);
                if ($this->onRequestEnd !== null) {
                    ($this->onRequestEnd)($httpRequest, $httpResponse);
                }
            });

            $server->start();
            return;
        }

        $server = new \Swoole\Http\Server($this->host, $this->port);
        $this->server = $server;

        if ($this->onWorkerStartHandlers !== []) {
            $handlers = $this->onWorkerStartHandlers;
            $server->on('workerStart', function ($server, int $workerId) use ($handlers) {
                foreach ($handlers as $handler) {
                    $handler($server, $workerId);
                }
                $this->startProcessesForWorker($workerId);
            });
        }

        if ($this->onShutdown !== null) {
            $handler = $this->onShutdown;
            $server->on('shutdown', function () use ($handler) {
                $handler();
            });
        }

        $server->on('request', function ($request, $response) use ($kernel) {
            $httpRequest = $this->buildRequest($request);
            if ($this->onRequestStart !== null) {
                ($this->onRequestStart)($httpRequest);
            }
            $httpResponse = $kernel->handle($httpRequest);
            $this->emit($response, $httpResponse);
            if ($this->onRequestEnd !== null) {
                ($this->onRequestEnd)($httpRequest, $httpResponse);
            }
        });

        $server->start();
    }

    /**
     * {@inheritDoc}
     *
     * @return DriverCapabilities
     */
    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }

    /**
     * Get the active WebSocket server when WebSockets are enabled.
     *
     * @return \Swoole\WebSocket\Server|null
     */
    public function websocketServer(): ?\Swoole\WebSocket\Server
    {
        if ($this->server instanceof \Swoole\WebSocket\Server) {
            return $this->server;
        }
        return null;
    }

    /**
     * Access the connection registry by reference.
     *
     * @return array<int, array{channels: array<string, bool>}>
     */
    public function &connections(): array
    {
        return $this->connections;
    }

    /**
     * Register a worker-start hook.
     *
     * @param callable(\Swoole\Server, int): void $handler
     * @return void
     */
    public function onWorkerStart(callable $handler): void
    {
        $this->onWorkerStartHandlers[] = $handler;
    }

    /**
     * Register a background process factory for a worker.
     *
     * @param callable(): mixed $factory
     * @param (callable(\Swoole\Process): void)|null $onStart
     * @param int $workerId
     * @return void
     */
    public function spawnProcess(callable $factory, ?callable $onStart = null, int $workerId = 0): void
    {
        if ($this->started) {
            throw new \RuntimeException('spawnProcess must be registered before the Swoole server starts.');
        }

        $this->processFactoriesByWorker[$workerId][] = [
            'factory' => $factory,
            'on_start' => $onStart,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function onBoot(callable $handler): void
    {
        $this->onBoot = $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function onShutdown(callable $handler): void
    {
        $this->onShutdown = $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function onRequestStart(callable $handler): void
    {
        $this->onRequestStart = $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function onRequestEnd(callable $handler): void
    {
        $this->onRequestEnd = $handler;
    }

    /**
     * Register a WebSocket message handler.
     *
     * @param callable(\Swoole\WebSocket\Server, mixed, self): void $handler
     * @return void
     */
    public function setWebSocketHandler(callable $handler): void
    {
        $this->webSocketHandler = $handler;
    }

    /**
     * Subscribe a connection to a channel.
     *
     * @param int $fd
     * @param string $channel
     * @return void
     */
    public function subscribe(int $fd, string $channel): void
    {
        if ($channel === '') {
            return;
        }
        if (!isset($this->connections[$fd])) {
            $this->connections[$fd] = ['channels' => []];
        }
        $this->connections[$fd]['channels'][$channel] = true;
    }

    /**
     * Unsubscribe a connection from a channel.
     *
     * @param int $fd
     * @param string $channel
     * @return void
     */
    public function unsubscribe(int $fd, string $channel): void
    {
        if ($channel === '' || !isset($this->connections[$fd]['channels'])) {
            return;
        }
        unset($this->connections[$fd]['channels'][$channel]);
    }

    protected function startProcessesForWorker(int $workerId): void
    {
        if ($this->coroutineId() >= 0) {
            if (!class_exists('Swoole\\Timer')) {
                $this->logProcessDeferralError('Swoole timer is required to spawn processes outside coroutines.');
                return;
            }
            $this->deferTimer(function () use ($workerId): void {
                $this->deferStartProcessesForWorker($workerId, 0);
            });
            return;
        }

        $this->startProcessesForWorkerOutsideCoroutine($workerId);
    }

    protected function startProcessesForWorkerOutsideCoroutine(int $workerId): void
    {
        $entries = $this->processFactoriesByWorker[$workerId] ?? [];
        foreach ($entries as $entry) {
            $process = $entry['factory']();
            if (!$process instanceof \Swoole\Process) {
                throw new \RuntimeException('spawnProcess factory must return a Swoole\\Process instance.');
            }
            $process->start();
            if ($entry['on_start'] !== null) {
                ($entry['on_start'])($process);
            }
        }
    }

    private function deferStartProcessesForWorker(int $workerId, int $attempt): void
    {
        if ($this->coroutineId() < 0) {
            $this->startProcessesForWorkerOutsideCoroutine($workerId);
            return;
        }

        if (!class_exists('Swoole\\Event')) {
            $this->logProcessDeferralError('Swoole event loop is required to spawn processes outside coroutines.');
            return;
        }

        if ($attempt >= 100) {
            $this->logProcessDeferralError('Unable to spawn processes outside coroutine context after multiple attempts.');
            return;
        }

        $this->deferEvent(function () use ($workerId, $attempt): void {
            $this->deferStartProcessesForWorker($workerId, $attempt + 1);
        });
    }

    protected function coroutineId(): int
    {
        if (!class_exists('Swoole\\Coroutine')) {
            return -1;
        }
        return \Swoole\Coroutine::getCid();
    }

    protected function deferTimer(callable $callback): void
    {
        \Swoole\Timer::after(0, $callback);
    }

    protected function deferEvent(callable $callback): void
    {
        \Swoole\Event::defer($callback);
    }

    protected function logProcessDeferralError(string $message): void
    {
        error_log('PHAPI: ' . $message);
    }

    /**
     * @param mixed $request
     * @return Request
     */
    private function buildRequest($request): Request
    {
        $method = $request->server['request_method'] ?? 'GET';
        $uri = $request->server['request_uri'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            $path = '/';
        }
        $query = $request->get ?? [];
        $headers = $request->header ?? [];
        $cookies = $request->cookie ?? [];
        $body = $this->parseBody($method, $headers, $request->rawContent());
        $server = $request->server ?? [];
        $server['REQUEST_TIME_FLOAT'] = $server['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $server['REQUEST_TIME'] = $server['REQUEST_TIME'] ?? time();

        return new Request($method, $path, $query, $headers, $cookies, $body, $server);
    }

    /**
     * @param string $method
     * @param array<string, string> $headers
     * @param string $raw
     * @return mixed
     */
    private function parseBody(string $method, array $headers, string $raw)
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }

        if ($raw === '') {
            return null;
        }

        $contentType = strtolower($headers['content-type'] ?? '');
        if (strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($raw, $parsed);
            return $parsed;
        }

        return $raw;
    }

    /**
     * @param mixed $swooleResponse
     * @param Response $response
     * @return void
     */
    private function emit($swooleResponse, Response $response): void
    {
        $swooleResponse->status($response->status());
        foreach ($response->headers() as $name => $value) {
            $swooleResponse->header($name, $value);
        }

        if ($response->isStream()) {
            $callback = $response->streamCallback();
            if ($callback !== null) {
                $result = $callback();
                if (is_iterable($result)) {
                    foreach ($result as $chunk) {
                        $swooleResponse->write($chunk);
                    }
                    $swooleResponse->end();
                    return;
                }
                if (is_string($result)) {
                    $swooleResponse->end($result);
                    return;
                }
            }
            $swooleResponse->end();
            return;
        }

        $swooleResponse->end($response->body());
    }
}
