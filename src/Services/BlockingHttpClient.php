<?php

namespace PHAPI\Services;

class BlockingHttpClient implements HttpClient
{
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
