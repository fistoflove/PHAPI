<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Exceptions\HttpRequestException;

class SwooleHttpClient implements HttpClient
{
    /**
     * @param string $url
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    private function fetchJsonWithMeta(string $url): array
    {
        if (!class_exists('Swoole\\Coroutine\\Http\\Client')) {
            throw new HttpRequestException($url, 0, '', 'Swoole coroutine HTTP client is not available.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new HttpRequestException($url, 0, '', 'Invalid URL');
        }

        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $scheme === 'https');
        $client->set(['timeout' => 5]);
        $client->get($path);
        $status = $client->statusCode;
        $body = $client->body ?? '';
        $client->close();

        $decoded = json_decode($body, true);
        $data = json_last_error() === JSON_ERROR_NONE ? $decoded : null;

        return [
            'data' => $data,
            'status' => $status,
            'body' => $body,
        ];
    }

    /**
     * Fetch and decode JSON using Swoole coroutine HTTP client.
     *
     * @param string $url
     * @return array<string, mixed>
     */
    public function getJson(string $url): array
    {
        $meta = $this->getJsonWithMeta($url);
        if ($meta['status'] < 200 || $meta['status'] >= 300) {
            throw new HttpRequestException($url, $meta['status'], $meta['body'], 'HTTP request returned non-2xx status');
        }

        if ($meta['data'] === null) {
            throw new HttpRequestException($url, $meta['status'], $meta['body'], 'Failed to decode JSON response');
        }

        return $meta['data'];
    }

    /**
     * @param string $url
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function getJsonWithMeta(string $url): array
    {
        if (!class_exists('Swoole\\Coroutine')) {
            throw new HttpRequestException($url, 0, '', 'Swoole coroutines are not available.');
        }

        if (\Swoole\Coroutine::getCid() < 0) {
            if (!function_exists('Swoole\\Coroutine\\run')) {
                throw new HttpRequestException($url, 0, '', 'Swoole coroutine context is required.');
            }
            $result = null;
            $error = null;
            \Swoole\Coroutine\run(function () use ($url, &$result, &$error): void {
                try {
                    $result = $this->fetchJsonWithMeta($url);
                } catch (\Throwable $e) {
                    $error = $e;
                }
            });
            if ($error !== null) {
                throw $error;
            }
            return $result ?? ['data' => null, 'status' => 0, 'body' => ''];
        }

        return $this->fetchJsonWithMeta($url);
    }
}
