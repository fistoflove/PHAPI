<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Exceptions\HttpRequestException;

class BlockingHttpClient implements HttpClient
{
    /**
     * Fetch and decode JSON using blocking HTTP.
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
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new HttpRequestException($url, 0, '', 'HTTP request failed');
        }

        /** @var array<int, string> $responseHeaders */
        $responseHeaders = $http_response_header;
        $status = $this->parseStatus($responseHeaders);
        $decoded = json_decode($response, true);
        $data = json_last_error() === JSON_ERROR_NONE ? $decoded : null;

        return [
            'data' => $data,
            'status' => $status,
            'body' => $response,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return int
     */
    private function parseStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $header, $matches) === 1) {
                return (int)$matches[1];
            }
        }

        return 0;
    }
}
