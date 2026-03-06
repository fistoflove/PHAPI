<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Functions\EdgeFunctionsClient;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class EdgeFunctionsClientTest extends TestCase
{
    private FakeTransport $transport;
    private SupabaseConfig $config;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
        ]);
    }

    private function client(?string $token = 'user-token'): EdgeFunctionsClient
    {
        return new EdgeFunctionsClient($this->transport, $this->config, $token);
    }

    public function testInvokeSuccess(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Hello World'],
            'status' => 200,
            'body' => '{"message":"Hello World"}',
        ]);

        $result = $this->client()->invoke('hello', ['name' => 'World']);

        $this->assertSame(['message' => 'Hello World'], $result['data']);
        $this->assertNull($result['error']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/functions/v1/hello', $this->transport->lastRequest()['path']);
    }

    public function testInvokeWithNullBody(): void
    {
        $this->transport->addResponse([
            'data' => ['ok' => true],
            'status' => 200,
            'body' => '{"ok":true}',
        ]);

        $result = $this->client()->invoke('ping');

        $this->assertNull($result['error']);
        $this->assertNull($this->transport->lastRequest()['body']);
    }

    public function testInvokeError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'Function not found'],
            'status' => 404,
            'body' => '{"message":"Function not found"}',
        ]);

        $result = $this->client()->invoke('nonexistent');

        $this->assertNull($result['data']);
        $this->assertSame(404, $result['error']['status']);
        $this->assertSame('Function not found', $result['error']['message']);
    }

    public function testInvokeWithCustomHeaders(): void
    {
        $this->transport->addResponse([
            'data' => ['ok' => true],
            'status' => 200,
            'body' => '{}',
        ]);

        $this->client()->invoke('fn', ['x' => 1], [
            'headers' => ['X-Custom' => 'value'],
        ]);

        $this->assertSame('value', $this->transport->lastRequest()['headers']['X-Custom']);
    }

    public function testInvokeWithRegion(): void
    {
        $this->transport->addResponse([
            'data' => ['ok' => true],
            'status' => 200,
            'body' => '{}',
        ]);

        $this->client()->invoke('fn', null, ['region' => 'us-east-1']);

        $this->assertSame('us-east-1', $this->transport->lastRequest()['headers']['x-region']);
    }

    public function testInvokeWithGetMethod(): void
    {
        $this->transport->addResponse([
            'data' => ['ok' => true],
            'status' => 200,
            'body' => '{}',
        ]);

        $this->client()->invoke('fn', null, ['method' => 'GET']);

        $this->assertSame('GET', $this->transport->lastRequest()['method']);
    }

    public function testInvokeSendsAuthToken(): void
    {
        $this->transport->addResponse([
            'data' => ['ok' => true],
            'status' => 200,
            'body' => '{}',
        ]);

        $this->client('my-jwt-token')->invoke('fn');

        $this->assertSame('Bearer my-jwt-token', $this->transport->lastRequest()['headers']['Authorization']);
    }
}
