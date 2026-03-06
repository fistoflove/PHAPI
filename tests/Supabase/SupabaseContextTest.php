<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Auth\AuthClient;
use PHAPI\Supabase\Database\DatabaseClient;
use PHAPI\Supabase\Exceptions\SupabaseRealtimeException;
use PHAPI\Supabase\Functions\EdgeFunctionsClient;
use PHAPI\Supabase\Realtime\RealtimeClient;
use PHAPI\Supabase\Storage\StorageClient;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseContext;
use PHPUnit\Framework\TestCase;

final class SupabaseContextTest extends TestCase
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

    public function testAuthReturnsAuthClient(): void
    {
        $context = new SupabaseContext($this->transport, $this->config, 'token');

        $this->assertInstanceOf(AuthClient::class, $context->auth());
    }

    public function testDbReturnsDatabaseClient(): void
    {
        $context = new SupabaseContext($this->transport, $this->config, 'token');

        $this->assertInstanceOf(DatabaseClient::class, $context->db());
    }

    public function testStorageReturnsStorageClient(): void
    {
        $context = new SupabaseContext($this->transport, $this->config, 'token');

        $this->assertInstanceOf(StorageClient::class, $context->storage());
    }

    public function testFunctionsReturnsEdgeFunctionsClient(): void
    {
        $context = new SupabaseContext($this->transport, $this->config, 'token');

        $this->assertInstanceOf(EdgeFunctionsClient::class, $context->functions());
    }

    public function testLazyInitialization(): void
    {
        $context = new SupabaseContext($this->transport, $this->config, 'token');

        $auth1 = $context->auth();
        $auth2 = $context->auth();
        $this->assertSame($auth1, $auth2);

        $db1 = $context->db();
        $db2 = $context->db();
        $this->assertSame($db1, $db2);

        $storage1 = $context->storage();
        $storage2 = $context->storage();
        $this->assertSame($storage1, $storage2);

        $fn1 = $context->functions();
        $fn2 = $context->functions();
        $this->assertSame($fn1, $fn2);
    }

    public function testRealtimeReturnsInjectedClient(): void
    {
        $socket = new FakeRealtimeSocket();
        $realtimeClient = new RealtimeClient($this->config, null, $socket);
        $context = new SupabaseContext($this->transport, $this->config, 'token', $realtimeClient);

        $this->assertSame($realtimeClient, $context->realtime());
    }

    public function testRealtimeThrowsWhenNotInjected(): void
    {
        $context = new SupabaseContext($this->transport, $this->config, 'token');

        $this->expectException(SupabaseRealtimeException::class);
        $context->realtime();
    }

    public function testAccessToken(): void
    {
        $context = new SupabaseContext($this->transport, $this->config, 'my-jwt');
        $this->assertSame('my-jwt', $context->accessToken());

        $anon = new SupabaseContext($this->transport, $this->config);
        $this->assertNull($anon->accessToken());
    }
}
