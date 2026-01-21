<?php

declare(strict_types=1);

namespace PHAPI\Services;

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
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [];
        }

        $decoded = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
