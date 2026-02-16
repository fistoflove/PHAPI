<?php

declare(strict_types=1);

namespace PHAPI\Contracts;

interface HttpClientInterface
{
    /**
     * Fetch and decode JSON from a URL.
     *
     * @param string $url
     * @param array<string, string> $headers
     * @return array<string, mixed>
     *
     * @throws \PHAPI\Exceptions\HttpRequestException
     */
    public function getJson(string $url, array $headers = []): array;

    /**
     * Fetch JSON with metadata.
     *
     * @param string $url
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function getJsonWithMeta(string $url, array $headers = []): array;

    /**
     * Submit an x-www-form-urlencoded POST request and decode JSON when possible.
     *
     * @param string $url
     * @param array<string, scalar|null> $form
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function postFormWithMeta(string $url, array $form, array $headers = []): array;

    /**
     * POST JSON-encoded data and decode the JSON response.
     *
     * @param string $url
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     *
     * @throws \PHAPI\Exceptions\HttpRequestException
     */
    public function postJson(string $url, array $data, array $headers = []): array;

    /**
     * POST JSON-encoded data and return response with metadata.
     *
     * @param string $url
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function postJsonWithMeta(string $url, array $data, array $headers = []): array;
}
