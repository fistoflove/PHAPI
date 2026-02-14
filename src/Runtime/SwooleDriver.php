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
    /**
     * @var array<string, bool|int|float|string>
     */
    private array $settings;
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
     * @var callable(\Swoole\Server, int, int, mixed): mixed|null
     */
    private $taskHandler = null;
    /**
     * @var callable(\Swoole\Server, int, mixed): void|null
     */
    private $taskFinishHandler = null;

    /**
     * Configure the Swoole server host/port and WebSocket support.
     *
     * @param string $host
     * @param int $port
     * @param bool $enableWebSockets
     * @param string $runtimeName
     * @param array<string, bool|int|float|string> $settings
     * @return void
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 9501,
        bool $enableWebSockets = false,
        string $runtimeName = 'swoole',
        array $settings = []
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->enableWebSockets = $enableWebSockets;
        $this->runtimeName = $runtimeName;
        $this->settings = $settings;
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
            $this->applySettings($server);
            $this->registerTaskCallbacksIfNeeded($server);

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
        $this->applySettings($server);
        $this->registerTaskCallbacksIfNeeded($server);

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
     * Return normalized Swoole server settings used by this driver.
     *
     * @return array<string, bool|int|float|string>
     */
    public function settings(): array
    {
        return $this->settings;
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
     * Register a task handler used when task workers are enabled.
     *
     * @param callable(\Swoole\Server, int, int, mixed): mixed $handler
     */
    public function setTaskHandler(callable $handler): void
    {
        $this->taskHandler = $handler;
    }

    /**
     * Register a task-finish handler used when task workers are enabled.
     *
     * @param callable(\Swoole\Server, int, mixed): void $handler
     */
    public function setTaskFinishHandler(callable $handler): void
    {
        $this->taskFinishHandler = $handler;
    }

    /**
     * Dispatch a background task to task workers.
     *
     * @param mixed $payload
     * @return int|false
     */
    public function dispatchTask(mixed $payload)
    {
        if ($this->taskWorkerCount() <= 0) {
            throw new \RuntimeException('Task workers are not enabled. Set task_worker_num > 0 in swoole_settings.');
        }

        $server = $this->server;
        if (!$server instanceof \Swoole\Server) {
            throw new \RuntimeException('Cannot dispatch task before Swoole server starts.');
        }

        /** @var int|false $taskId */
        $taskId = $server->task($payload);
        return $taskId;
    }

    /**
     * Send a payload to a specific WebSocket connection.
     *
     * @param int $fd
     * @param string $payload
     * @return bool
     */
    public function send(int $fd, string $payload): bool
    {
        $server = $this->websocketServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            return false;
        }

        if (!$this->isConnectionEstablished($fd)) {
            return false;
        }

        try {
            return call_user_func([$server, 'push'], $fd, $payload);
        } catch (\Throwable) {
            return false;
        }
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

    /**
     * Determine if a WebSocket connection is currently established.
     *
     * @param int $fd
     * @return bool
     */
    public function isConnectionEstablished(int $fd): bool
    {
        $server = $this->websocketServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            return false;
        }

        if (is_callable([$server, 'isEstablished'])) {
            return (bool) $server->isEstablished($fd);
        }

        if (is_callable([$server, 'exist'])) {
            return (bool) $server->exist($fd);
        }

        return false;
    }

    /**
     * Disconnect an established WebSocket connection.
     *
     * @param int $fd
     * @param int $code
     * @param string $reason
     * @return bool
     */
    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        $server = $this->websocketServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            return false;
        }

        if (!$this->isConnectionEstablished($fd)) {
            return false;
        }

        if (!is_callable([$server, 'disconnect'])) {
            return false;
        }

        try {
            return (bool) call_user_func([$server, 'disconnect'], $fd, $code, $reason);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Register a recurring timer.
     *
     * @param int $intervalMs
     * @param callable(): void $handler
     * @return int|false
     */
    public function every(int $intervalMs, callable $handler)
    {
        if ($intervalMs < 1) {
            throw new \InvalidArgumentException('Timer interval must be at least 1ms.');
        }

        return $this->timerTick($intervalMs, $handler);
    }

    /**
     * Register a one-shot timer.
     *
     * @param int $delayMs
     * @param callable(): void $handler
     * @return int|false
     */
    public function after(int $delayMs, callable $handler)
    {
        if ($delayMs < 1) {
            throw new \InvalidArgumentException('Timer delay must be at least 1ms.');
        }

        return $this->timerAfter($delayMs, $handler);
    }

    /**
     * Clear a timer id.
     *
     * @param int $timerId
     * @return bool
     */
    public function clearTimer(int $timerId): bool
    {
        if ($timerId <= 0) {
            return false;
        }

        return $this->timerClear($timerId);
    }

    protected function startProcessesForWorker(int $workerId): void
    {
        if (!$this->hasProcessFactoriesForWorker($workerId)) {
            return;
        }

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
        if (!$this->hasProcessFactoriesForWorker($workerId)) {
            return;
        }

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

    private function applySettings(\Swoole\Server $server): void
    {
        if ($this->settings === []) {
            return;
        }

        if (!is_callable([$server, 'set'])) {
            return;
        }

        call_user_func([$server, 'set'], $this->settings);
    }

    private function registerTaskCallbacksIfNeeded(\Swoole\Server $server): void
    {
        if ($this->taskWorkerCount() <= 0) {
            return;
        }

        $server->on('task', function (...$args) {
            return $this->handleTaskEvent($args);
        });
        $server->on('finish', function (...$args): void {
            $this->handleTaskFinishEvent($args);
        });
    }

    private function taskWorkerCount(): int
    {
        $value = $this->settings['task_worker_num'] ?? 0;
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    private function hasProcessFactoriesForWorker(int $workerId): bool
    {
        return isset($this->processFactoriesByWorker[$workerId]) && $this->processFactoriesByWorker[$workerId] !== [];
    }

    /**
     * @param array<int|string, mixed> $args
     * @return mixed
     */
    private function handleTaskEvent(array $args)
    {
        $server = $args[0] ?? null;
        if (!$server instanceof \Swoole\Server) {
            return null;
        }

        $taskId = 0;
        $srcWorkerId = 0;
        $data = null;

        if (isset($args[1], $args[2], $args[3]) && is_int($args[1]) && is_int($args[2])) {
            $taskId = $args[1];
            $srcWorkerId = $args[2];
            $data = $args[3];
        } elseif (isset($args[1]) && is_object($args[1])) {
            $task = $args[1];
            $taskId = is_numeric($task->id ?? null) ? (int) $task->id : 0;
            $srcWorkerId = is_numeric($task->worker_id ?? null) ? (int) $task->worker_id : 0;
            $data = $task->data ?? null;
        }

        if ($this->taskHandler !== null) {
            $handler = $this->taskHandler;
            return $handler($server, $taskId, $srcWorkerId, $data);
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function handleTaskFinishEvent(array $args): void
    {
        if ($this->taskFinishHandler === null) {
            return;
        }

        $server = $args[0] ?? null;
        if (!$server instanceof \Swoole\Server) {
            return;
        }

        $taskId = isset($args[1]) && is_int($args[1]) ? $args[1] : 0;
        $data = $args[2] ?? null;

        $handler = $this->taskFinishHandler;
        $handler($server, $taskId, $data);
    }

    protected function coroutineId(): int
    {
        if (!class_exists('Swoole\\Coroutine')) {
            return -1;
        }
        return \Swoole\Coroutine::getCid();
    }

    /**
     * @param callable(): void $callback
     */
    protected function deferTimer(callable $callback): void
    {
        $this->timerAfter(1, $callback);
    }

    /**
     * @param callable(): void $callback
     */
    protected function deferEvent(callable $callback): void
    {
        \Swoole\Event::defer($callback);
    }

    protected function logProcessDeferralError(string $message): void
    {
        error_log('PHAPI: ' . $message);
    }

    /**
     * @param int $intervalMs
     * @param callable(): void $handler
     * @return int|false
     */
    protected function timerTick(int $intervalMs, callable $handler)
    {
        if (!class_exists('Swoole\\Timer')) {
            throw new \RuntimeException('Swoole timer support is not available.');
        }

        return \Swoole\Timer::tick($intervalMs, $handler);
    }

    /**
     * @param int $delayMs
     * @param callable(): void $handler
     * @return int|false
     */
    protected function timerAfter(int $delayMs, callable $handler)
    {
        if (!class_exists('Swoole\\Timer')) {
            throw new \RuntimeException('Swoole timer support is not available.');
        }

        /** @phpstan-ignore-next-line */
        return \Swoole\Timer::after($delayMs, $handler);
    }

    protected function timerClear(int $timerId): bool
    {
        if (!class_exists('Swoole\\Timer')) {
            throw new \RuntimeException('Swoole timer support is not available.');
        }

        \Swoole\Timer::clear($timerId);
        return true;
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
        $headers = $response->headerLines();
        $counts = [];
        foreach ($headers as $header) {
            $lower = strtolower($header['name']);
            $counts[$lower] = ($counts[$lower] ?? 0) + 1;
        }

        foreach ($headers as $header) {
            $name = $header['name'];
            $value = $header['value'];
            $lowerName = strtolower($name);
            $replace = ($counts[$lowerName] ?? 0) <= 1 && $lowerName !== 'set-cookie';
            $swooleResponse->header($name, $value, $replace);
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
