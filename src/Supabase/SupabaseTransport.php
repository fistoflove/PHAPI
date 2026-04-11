<?php

declare(strict_types=1);

namespace PHAPI\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseException;

/**
 * HTTP transport for Supabase API calls.
 *
 * Uses a Swoole Channel-based connection pool shared across all coroutines
 * within a worker process. Pre-warmed connections avoid TLS handshake storms
 * under concurrent load. Each coroutine borrows a connection, uses it, and
 * returns it to the pool.
 *
 * Subclassable for testing (see FakeTransport).
 *
 * @api
 */
class SupabaseTransport
{
    private const DEFAULT_POOL_SIZE = 8;

    /** @var \Swoole\Coroutine\Channel|null */
    private $pool = null;

    private string $poolHost = '';
    private int $poolPort = 0;

    public function __construct(
        private readonly SupabaseConfig $config,
    ) {
    }

    /**
     * @param array<int|string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{data: mixed, status: int, body: string}
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
    ): array {
        $url = $this->config->url . $path;

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new SupabaseException('Invalid Supabase URL: ' . $url);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $requestPath = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $requestPath .= '?' . $parts['query'];
        }

        return $this->doRequest($method, $host, $port, $scheme === 'https', $requestPath, $body, $headers);
    }

    /**
     * Send a request with a raw string body (for file uploads/binary data).
     *
     * @param array<string, string> $headers
     * @return array{data: mixed, status: int, body: string}
     */
    public function requestRaw(
        string $method,
        string $path,
        string $body,
        array $headers = [],
    ): array {
        $url = $this->config->url . $path;

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new SupabaseException('Invalid Supabase URL: ' . $url);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $requestPath = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $requestPath .= '?' . $parts['query'];
        }

        return $this->doRequest($method, $host, $port, $scheme === 'https', $requestPath, null, $headers, $body);
    }

    /**
     * @param array<int|string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{data: mixed, status: int, body: string}
     */
    protected function doRequest(
        string $method,
        string $host,
        int $port,
        bool $ssl,
        string $path,
        ?array $body,
        array $headers,
        ?string $rawBody = null,
    ): array {
        if (!class_exists('Swoole\\Coroutine\\Http\\Client')) {
            throw new SupabaseException('Swoole coroutine HTTP client is not available.');
        }

        $execute = function () use ($method, $host, $port, $ssl, $path, $body, $headers, $rawBody): array {
            $client = $this->borrowClient($host, $port, $ssl);

            try {
                $client->setHeaders($headers);

                $encodedBody = $rawBody ?? ($body !== null
                    ? json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR)
                    : null);

                $method = strtoupper($method);

                if ($method === 'GET') {
                    $client->get($path);
                } elseif ($method === 'POST') {
                    $client->post($path, $encodedBody ?? '');
                } else {
                    $client->setMethod($method);
                    if ($encodedBody !== null) {
                        $client->setData($encodedBody);
                    }
                    $client->execute($path);
                }

                $status = $client->statusCode;
                $responseBody = $client->body ?? '';

                // If connection failed, discard this client and create a fresh one for the pool
                if ($status <= 0) {
                    $client->close();
                    $this->returnClient($this->createClient($host, $port, $ssl));
                } else {
                    $this->returnClient($client);
                }
            } catch (\Throwable $e) {
                // On any error, discard the client and replenish the pool
                try {
                    $client->close();
                } catch (\Throwable) {
                }
                $this->returnClient($this->createClient($host, $port, $ssl));
                throw $e;
            }

            $decoded = json_decode($responseBody, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : null;

            return [
                'data' => $data,
                'status' => $status,
                'body' => $responseBody,
            ];
        };

        if (!class_exists('Swoole\\Coroutine')) {
            throw new SupabaseException('Swoole coroutines are not available.');
        }

        if (\Swoole\Coroutine::getCid() < 0) {
            if (!function_exists('Swoole\\Coroutine\\run')) {
                throw new SupabaseException('Swoole coroutine context is required.');
            }
            $result = null;
            $error = null;
            \Swoole\Coroutine\run(function () use ($execute, &$result, &$error): void {
                try {
                    $result = $execute();
                } catch (\Throwable $e) {
                    $error = $e;
                }
            });
            if ($error !== null) {
                throw $error;
            }
            return $result ?? ['data' => null, 'status' => 0, 'body' => ''];
        }

        return $execute();
    }

    /**
     * Borrow a client from the connection pool.
     *
     * The pool is lazily initialized on first use. If the pool is empty,
     * the coroutine blocks until a client is returned by another coroutine
     * (with a timeout equal to the configured request timeout).
     */
    private function borrowClient(string $host, int $port, bool $ssl): \Swoole\Coroutine\Http\Client
    {
        $this->ensurePool($host, $port, $ssl);

        /** @var \Swoole\Coroutine\Channel $pool */
        $pool = $this->pool;

        $client = $pool->pop($this->config->timeout);
        if ($client === false) {
            // Pool exhausted and timed out — create overflow client
            return $this->createClient($host, $port, $ssl);
        }

        return $client;
    }

    /**
     * Return a client to the pool. If the pool is full, the client is discarded.
     */
    private function returnClient(\Swoole\Coroutine\Http\Client $client): void
    {
        if ($this->pool === null) {
            $client->close();
            return;
        }

        // Non-blocking push — if pool is full, discard
        if (!$this->pool->push($client, 0.0)) {
            $client->close();
        }
    }

    /**
     * Lazily initialize the connection pool with pre-warmed clients.
     */
    private function ensurePool(string $host, int $port, bool $ssl): void
    {
        if ($this->pool !== null && $this->poolHost === $host && $this->poolPort === $port) {
            return;
        }

        $poolSize = $this->config->retries > 0 ? $this->config->retries : self::DEFAULT_POOL_SIZE;

        $this->pool = new \Swoole\Coroutine\Channel($poolSize);
        $this->poolHost = $host;
        $this->poolPort = $port;

        // Pre-warm all pool slots
        for ($i = 0; $i < $poolSize; $i++) {
            $this->pool->push($this->createClient($host, $port, $ssl));
        }
    }

    private function createClient(string $host, int $port, bool $ssl): \Swoole\Coroutine\Http\Client
    {
        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
        $client->set([
            'timeout' => $this->config->timeout,
            'keep_alive' => true,
        ]);

        return $client;
    }
}
