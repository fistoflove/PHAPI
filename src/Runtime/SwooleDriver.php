<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;
use PHAPI\Server\HttpKernel;

class SwooleDriver implements HttpRuntimeDriver
{
    private string $host;
    private int $port;
    private bool $enableWebSockets;
    private Capabilities $capabilities;
    private ?\Swoole\Server $server = null;
    /**
     * @var array<int, array{channels: array<string, bool>}>
     */
    private array $connections = [];
    /**
     * @var callable(\Swoole\Server, int): void|null
     */
    private $onWorkerStart = null;
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
     * @return void
     */
    public function __construct(string $host = '0.0.0.0', int $port = 9501, bool $enableWebSockets = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->enableWebSockets = $enableWebSockets;
        $this->capabilities = new Capabilities(true, $enableWebSockets, true, true);
    }

    /**
     * {@inheritDoc}
     *
     * @param HttpKernel $kernel
     * @return void
     */
    public function start(HttpKernel $kernel): void
    {
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

            if ($this->onWorkerStart !== null) {
                $handler = $this->onWorkerStart;
                $server->on('workerStart', function ($server, int $workerId) use ($handler) {
                    $handler($server, $workerId);
                });
            }

            $server->on('request', function ($request, $response) use ($kernel) {
                $httpRequest = $this->buildRequest($request);
                $httpResponse = $kernel->handle($httpRequest);
                $this->emit($response, $httpResponse);
            });

            $server->start();
            return;
        }

        $server = new \Swoole\Http\Server($this->host, $this->port);
        $this->server = $server;

        if ($this->onWorkerStart !== null) {
            $handler = $this->onWorkerStart;
            $server->on('workerStart', function ($server, int $workerId) use ($handler) {
                $handler($server, $workerId);
            });
        }

        $server->on('request', function ($request, $response) use ($kernel) {
            $httpRequest = $this->buildRequest($request);
            $httpResponse = $kernel->handle($httpRequest);
            $this->emit($response, $httpResponse);
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
        $this->onWorkerStart = $handler;
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
