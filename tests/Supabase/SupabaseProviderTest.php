<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Core\Container;
use PHAPI\Exceptions\ConfigException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseContext;
use PHAPI\Supabase\SupabaseFactory;
use PHAPI\Supabase\SupabaseProvider;
use PHAPI\Supabase\SupabaseTransport;
use PHPUnit\Framework\TestCase;

final class SupabaseProviderTest extends TestCase
{
    public function testRegisterThrowsWithoutUrl(): void
    {
        $provider = new SupabaseProvider();
        $container = new Container();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('url');
        $provider->register($container, ['supabase' => ['anon_key' => 'key']]);
    }

    public function testRegisterThrowsWithoutAnonKey(): void
    {
        $provider = new SupabaseProvider();
        $container = new Container();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('anon_key');
        $provider->register($container, ['supabase' => ['url' => 'https://test.supabase.co']]);
    }

    public function testRegisterBindsSingletons(): void
    {
        $provider = new SupabaseProvider();
        $container = new Container();

        $provider->register($container, [
            'supabase' => [
                'url' => 'https://test.supabase.co',
                'anon_key' => 'anon-key',
                'service_role_key' => 'service-key',
            ],
        ]);

        $this->assertTrue($container->has(SupabaseConfig::class));
        $this->assertTrue($container->has(SupabaseTransport::class));
        $this->assertTrue($container->has(SupabaseFactory::class));
        $this->assertTrue($container->has(SupabaseContext::class));
    }

    public function testConfigSingleton(): void
    {
        $provider = new SupabaseProvider();
        $container = new Container();

        $provider->register($container, [
            'supabase' => [
                'url' => 'https://test.supabase.co',
                'anon_key' => 'anon-key',
            ],
        ]);

        $config = $container->get(SupabaseConfig::class);
        $this->assertInstanceOf(SupabaseConfig::class, $config);
        $this->assertSame('https://test.supabase.co', $config->url);
        $this->assertSame('anon-key', $config->anonKey);
    }

    public function testFactorySingleton(): void
    {
        $provider = new SupabaseProvider();
        $container = new Container();

        $provider->register($container, [
            'supabase' => [
                'url' => 'https://test.supabase.co',
                'anon_key' => 'anon-key',
            ],
        ]);

        $factory = $container->get(SupabaseFactory::class);
        $this->assertInstanceOf(SupabaseFactory::class, $factory);

        // Singleton: same instance
        $factory2 = $container->get(SupabaseFactory::class);
        $this->assertSame($factory, $factory2);
    }

    public function testContextIsRequestScoped(): void
    {
        $provider = new SupabaseProvider();
        $container = new Container();

        $provider->register($container, [
            'supabase' => [
                'url' => 'https://test.supabase.co',
                'anon_key' => 'anon-key',
            ],
        ]);

        $context1 = $container->get(SupabaseContext::class);
        $this->assertInstanceOf(SupabaseContext::class, $context1);

        // Same within request scope
        $context2 = $container->get(SupabaseContext::class);
        $this->assertSame($context1, $context2);

        // New scope creates new context
        $container->endRequestScope();
        $container->beginRequestScope();
        $context3 = $container->get(SupabaseContext::class);
        $this->assertNotSame($context1, $context3);
    }
}
