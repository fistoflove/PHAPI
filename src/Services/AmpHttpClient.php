<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Exceptions\HttpRequestException;

class AmpHttpClient implements HttpClient
{
    /**
     * Fetch and decode JSON using AMPHP HTTP client.
     *
     * @param string $url
     * @return array<string, mixed>
     */
    public function getJson(string $url): array
    {
        if (!class_exists('Amp\\Http\\Client\\HttpClientBuilder') || !function_exists('Amp\\async')) {
            $fallback = new BlockingHttpClient();
            return $fallback->getJson($url);
        }

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
        if (!class_exists('Amp\\Http\\Client\\HttpClientBuilder') || !function_exists('Amp\\async')) {
            $fallback = new BlockingHttpClient();
            return $fallback->getJsonWithMeta($url);
        }

        $future = \Amp\async(function () use ($url) {
            $client = (new \Amp\Http\Client\HttpClientBuilder())->build();
            $request = new \Amp\Http\Client\Request($url, 'GET');
            $response = $client->request($request);
            $status = $response->getStatus();
            $body = $response->getBody()->buffer();
            return ['status' => $status, 'body' => $body];
        });

        $result = $future->await();
        $body = $result['body'];
        $decoded = json_decode($body, true);
        $data = json_last_error() === JSON_ERROR_NONE ? $decoded : null;

        return [
            'data' => $data,
            'status' => (int)$result['status'],
            'body' => $body,
        ];
    }
}
