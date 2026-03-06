<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Core\Container;
use PHAPI\Exceptions\ConfigException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseContext;
use PHAPI\Supabase\SupabaseFactory;
use PHAPI\Supabase\SupabaseProvider;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Integration tests for SupabaseProvider registration.
 *
 * @group integration
 * @group supabase
 */
final class ProviderIntegrationTest extends SupabaseIntegrationTestCase
{
    public function testRegisterBindsSingletons(): void
    {
        $container = new Container();
        $provider = new SupabaseProvider();

        $provider->register($container, [
            'supabase' => [
                'url' => self::$config->url,
                'anon_key' => self::$config->anonKey,
                'service_role_key' => self::$config->serviceRoleKey,
            ],
        ]);

        $this->assertInstanceOf(SupabaseConfig::class, $container->get(SupabaseConfig::class));
        $this->assertInstanceOf(SupabaseTransport::class, $container->get(SupabaseTransport::class));
        $this->assertInstanceOf(SupabaseFactory::class, $container->get(SupabaseFactory::class));
    }

    public function testRegisterBindsRequestScopedContext(): void
    {
        $container = new Container();
        $provider = new SupabaseProvider();

        $provider->register($container, [
            'supabase' => [
                'url' => self::$config->url,
                'anon_key' => self::$config->anonKey,
                'service_role_key' => self::$config->serviceRoleKey,
            ],
        ]);

        $context = $container->get(SupabaseContext::class);
        $this->assertInstanceOf(SupabaseContext::class, $context);
    }

    public function testRegisterThrowsWithoutUrl(): void
    {
        $container = new Container();
        $provider = new SupabaseProvider();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('url');
        $provider->register($container, [
            'supabase' => ['anon_key' => 'test'],
        ]);
    }

    public function testRegisterThrowsWithoutAnonKey(): void
    {
        $container = new Container();
        $provider = new SupabaseProvider();

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('anon_key');
        $provider->register($container, [
            'supabase' => ['url' => 'http://localhost'],
        ]);
    }

    public function testRegisteredFactoryCanQueryDatabase(): void
    {
        $container = new Container();
        $provider = new SupabaseProvider();

        $provider->register($container, [
            'supabase' => [
                'url' => self::$config->url,
                'anon_key' => self::$config->anonKey,
                'service_role_key' => self::$config->serviceRoleKey,
            ],
        ]);

        /** @var SupabaseFactory $factory */
        $factory = $container->get(SupabaseFactory::class);
        $result = $factory->createServiceContext()->db()->from('posts')->limit(1)->get();
        $this->assertIsArray($result);
    }
}
