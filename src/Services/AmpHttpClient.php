<?php

namespace PHAPI\Services;

class AmpHttpClient implements HttpClient
{
    public function getJson(string $url): array
    {
        if (!class_exists('Amp\\Http\\Client\\HttpClientBuilder') || !function_exists('Amp\\async')) {
            $fallback = new BlockingHttpClient();
            return $fallback->getJson($url);
        }

        $future = \Amp\async(function () use ($url) {
            $client = (new \Amp\Http\Client\HttpClientBuilder())->build();
            $request = new \Amp\Http\Client\Request($url, 'GET');
            $response = $client->request($request);
            return $response->getBody()->buffer();
        });

        $body = $future->await();
        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
