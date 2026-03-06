<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Exceptions\HttpRequestException;

class SwooleHttpClient implements HttpClient
{
    public function __construct(
        private readonly float $timeout = 5.0,
    ) {
    }

    /**
     * @param string $url
     * @param string $method
     * @param string|null $body
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    private function requestWithMeta(
        string $url,
        string $method,
        ?string $body = null,
        array $headers = [],
    ): array {
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
        $client->set(['timeout' => $this->timeout]);

        $defaultHeaders = [
            'Accept' => 'application/json',
        ];

        $mergedHeaders = array_merge($defaultHeaders, $headers);

        if ($method === 'POST' && $body !== null) {
            $client->setHeaders($mergedHeaders);
            $client->post($path, $body);
        } else {
            $client->setHeaders($mergedHeaders);
            $client->get($path);
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
    }

    /**
     * Fetch and decode JSON using Swoole coroutine HTTP client.
     *
     * @param string $url
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        $meta = $this->getJsonWithMeta($url, $headers);
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
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function getJsonWithMeta(string $url, array $headers = []): array
    {
        return $this->runInCoroutine(
            $url,
            fn (): array => $this->requestWithMeta($url, 'GET', null, $headers),
        );
    }

    /**
     * @param string $url
     * @param array<string, scalar|null> $form
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function postFormWithMeta(string $url, array $form, array $headers = []): array
    {
        $defaultHeaders = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        $mergedHeaders = array_merge($defaultHeaders, $headers);
        $body = http_build_query($form);

        return $this->runInCoroutine(
            $url,
            fn (): array => $this->requestWithMeta($url, 'POST', $body, $mergedHeaders),
        );
    }

    /**
     * POST JSON-encoded data and decode the JSON response.
     *
     * @param string $url
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $data, array $headers = []): array
    {
        $meta = $this->postJsonWithMeta($url, $data, $headers);
        if ($meta['status'] < 200 || $meta['status'] >= 300) {
            throw new HttpRequestException($url, $meta['status'], $meta['body'], 'HTTP request returned non-2xx status');
        }

        if ($meta['data'] === null) {
            throw new HttpRequestException($url, $meta['status'], $meta['body'], 'Failed to decode JSON response');
        }

        return $meta['data'];
    }

    /**
     * POST JSON-encoded data and return response with metadata.
     *
     * @param string $url
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function postJsonWithMeta(string $url, array $data, array $headers = []): array
    {
        $defaultHeaders = [
            'Content-Type' => 'application/json',
        ];
        $mergedHeaders = array_merge($defaultHeaders, $headers);
        $body = json_encode($data === [] ? new \stdClass() : $data, JSON_THROW_ON_ERROR);

        return $this->runInCoroutine(
            $url,
            fn (): array => $this->requestWithMeta($url, 'POST', $body, $mergedHeaders),
        );
    }

    /**
     * @param string $url
     * @param callable(): array{data: array<string, mixed>|null, status: int, body: string} $request
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    private function runInCoroutine(string $url, callable $request): array
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
            \Swoole\Coroutine\run(function () use ($request, &$result, &$error): void {
                try {
                    $result = $request();
                } catch (\Throwable $e) {
                    $error = $e;
                }
            });
            if ($error !== null) {
                throw $error;
            }
            return $result ?? ['data' => null, 'status' => 0, 'body' => ''];
        }

        return $request();
    }
}
