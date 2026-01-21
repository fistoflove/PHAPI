<?php

declare(strict_types=1);

namespace PHAPI\Services;

interface HttpClient
{
    /**
     * Fetch and decode JSON from a URL.
     *
     * @param string $url
     * @return array<string, mixed>
     */
    public function getJson(string $url): array;
}
