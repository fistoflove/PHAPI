<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Test double for SupabaseTransport that records requests
 * and returns queued responses without requiring Swoole.
 */
class FakeTransport extends SupabaseTransport
{
    /** @var array<int, array{data: mixed, status: int, body: string}> */
    private array $responses = [];

    /** @var array<int, array{method: string, path: string, body: mixed, headers: array<string, string>}> */
    public array $requests = [];

    public function __construct()
    {
        parent::__construct(new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'test-anon-key',
            'service_role_key' => 'test-service-role-key',
        ]));
    }

    /**
     * @param array{data: mixed, status: int, body: string} $response
     */
    public function addResponse(array $response): void
    {
        $this->responses[] = $response;
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
    ): array {
        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'headers' => $headers,
        ];

        if ($this->responses !== []) {
            return array_shift($this->responses);
        }

        return ['data' => null, 'status' => 200, 'body' => ''];
    }

    public function requestRaw(
        string $method,
        string $path,
        string $body,
        array $headers = [],
    ): array {
        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'headers' => $headers,
        ];

        if ($this->responses !== []) {
            return array_shift($this->responses);
        }

        return ['data' => null, 'status' => 200, 'body' => ''];
    }

    /**
     * @return array{method: string, path: string, body: mixed, headers: array<string, string>}|null
     */
    public function lastRequest(): ?array
    {
        if ($this->requests === []) {
            return null;
        }
        return $this->requests[count($this->requests) - 1];
    }

    /**
     * @return array<int, array{method: string, path: string, body: mixed, headers: array<string, string>}>
     */
    public function allRequests(): array
    {
        return $this->requests;
    }
}
