<?php

declare(strict_types=1);

namespace PHAPI\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseException;

/**
 * HTTP transport for Supabase API calls.
 *
 * Supports all HTTP methods (GET, POST, PATCH, DELETE) using Swoole's
 * coroutine HTTP client. Subclassable for testing.
 *
 * @api
 */
class SupabaseTransport
{
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
            $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
            $client->set(['timeout' => $this->config->timeout]);
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
            $client->close();

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
}
