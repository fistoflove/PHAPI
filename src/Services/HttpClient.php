<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Contracts\HttpClientInterface;

interface HttpClient extends HttpClientInterface
{
    /**
     * Fetch and decode JSON from a URL.
     *
     * @param string $url
     * @return array<string, mixed>
     *
     * @throws \PHAPI\Exceptions\HttpRequestException
     */
    public function getJson(string $url): array;

    /**
     * Fetch JSON with metadata.
     *
     * @param string $url
     * @return array{data: array<string, mixed>|null, status: int, body: string}
     */
    public function getJsonWithMeta(string $url): array;
}
