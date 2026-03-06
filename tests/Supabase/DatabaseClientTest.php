<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Database\DatabaseClient;
use PHAPI\Supabase\Database\QueryBuilder;
use PHAPI\Supabase\Exceptions\SupabaseDatabaseException;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseClientTest extends TestCase
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

    public function testFromReturnsQueryBuilder(): void
    {
        $client = new DatabaseClient($this->transport, $this->config, 'token');
        $builder = $client->from('posts');

        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    public function testRpc(): void
    {
        $this->transport->addResponse([
            'data' => ['result' => 42],
            'status' => 200,
            'body' => '{}',
        ]);

        $client = new DatabaseClient($this->transport, $this->config, 'token');
        $result = $client->rpc('calculate_total', ['order_id' => 1]);

        $this->assertSame(42, $result['result']);
        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('/rest/v1/rpc/calculate_total', $this->transport->lastRequest()['path']);
    }

    public function testRpcThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'function not found'],
            'status' => 404,
            'body' => '{}',
        ]);

        $client = new DatabaseClient($this->transport, $this->config, 'token');

        $this->expectException(SupabaseDatabaseException::class);
        $client->rpc('nonexistent');
    }
}
