<?php

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
    private array $connections = [];
    private $onWorkerStart = null;

    public function __construct(string $host = '0.0.0.0', int $port = 9501, bool $enableWebSockets = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->enableWebSockets = $enableWebSockets;
        $this->capabilities = new Capabilities(true, $enableWebSockets, true, true);
    }

    public function start(HttpKernel $kernel): void
    {
        if ($this->enableWebSockets) {
            $server = new \Swoole\WebSocket\Server($this->host, $this->port);
            $this->server = $server;

            $server->on('open', function (\Swoole\WebSocket\Server $server, $request) {
                $this->connections[$request->fd] = true;
            });

            $server->on('close', function (\Swoole\WebSocket\Server $server, int $fd) {
                unset($this->connections[$fd]);
            });

            $server->on('message', function () {
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

    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }

    public function websocketServer(): ?\Swoole\WebSocket\Server
    {
        if ($this->server instanceof \Swoole\WebSocket\Server) {
            return $this->server;
        }
        return null;
    }

    public function &connections(): array
    {
        return $this->connections;
    }

    public function onWorkerStart(callable $handler): void
    {
        $this->onWorkerStart = $handler;
    }

    private function buildRequest($request): Request
    {
        $method = $request->server['request_method'] ?? 'GET';
        $uri = $request->server['request_uri'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $query = $request->get ?? [];
        $headers = $request->header ?? [];
        $cookies = $request->cookie ?? [];
        $body = $this->parseBody($method, $headers, $request->rawContent());

        return new Request($method, $path, $query, $headers, $cookies, $body, $request->server ?? []);
    }

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
