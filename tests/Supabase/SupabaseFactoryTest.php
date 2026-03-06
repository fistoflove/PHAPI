<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Realtime\RealtimeClient;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseContext;
use PHAPI\Supabase\SupabaseFactory;
use PHPUnit\Framework\TestCase;

final class SupabaseFactoryTest extends TestCase
{
    private FakeTransport $transport;
    private SupabaseConfig $config;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
            'service_role_key' => 'service-key',
        ]);
    }

    public function testCreateContextWithToken(): void
    {
        $factory = new SupabaseFactory($this->transport, $this->config);
        $context = $factory->createContext('user-jwt');

        $this->assertInstanceOf(SupabaseContext::class, $context);
        $this->assertSame('user-jwt', $context->accessToken());
    }

    public function testCreateContextWithoutToken(): void
    {
        $factory = new SupabaseFactory($this->transport, $this->config);
        $context = $factory->createContext();

        $this->assertNull($context->accessToken());
    }

    public function testCreateServiceContext(): void
    {
        $factory = new SupabaseFactory($this->transport, $this->config);
        $context = $factory->createServiceContext();

        $this->assertSame('service-key', $context->accessToken());
    }

    public function testEachContextIsIndependent(): void
    {
        $factory = new SupabaseFactory($this->transport, $this->config);
        $ctx1 = $factory->createContext('token-1');
        $ctx2 = $factory->createContext('token-2');

        $this->assertNotSame($ctx1, $ctx2);
        $this->assertSame('token-1', $ctx1->accessToken());
        $this->assertSame('token-2', $ctx2->accessToken());
    }

    public function testConfigAccessor(): void
    {
        $factory = new SupabaseFactory($this->transport, $this->config);
        $this->assertSame($this->config, $factory->config());
    }

    public function testTransportAccessor(): void
    {
        $factory = new SupabaseFactory($this->transport, $this->config);
        $this->assertSame($this->transport, $factory->transport());
    }

    public function testRealtimeReturnsSingleton(): void
    {
        $factory = new SupabaseFactory($this->transport, $this->config);

        $rt1 = $factory->realtime();
        $rt2 = $factory->realtime();

        $this->assertInstanceOf(RealtimeClient::class, $rt1);
        $this->assertSame($rt1, $rt2);
    }
}
