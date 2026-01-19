<?php

namespace PHAPI\Services;

class SwooleHttpClient implements HttpClient
{
    public function getJson(string $url): array
    {
        if (!class_exists('Swoole\\Coroutine\\Http\\Client')) {
            $fallback = new BlockingHttpClient();
            return $fallback->getJson($url);
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return [];
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
        $body = $client->body ?? '';
        $client->close();

        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
