<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Integration tests for SupabaseFactory accessors.
 *
 * @group integration
 * @group supabase
 */
final class FactoryIntegrationTest extends SupabaseIntegrationTestCase
{
    public function testConfigReturnsSupabaseConfig(): void
    {
        $config = self::$factory->config();

        $this->assertInstanceOf(SupabaseConfig::class, $config);
        $this->assertNotEmpty($config->url);
        $this->assertNotEmpty($config->anonKey);
    }

    public function testTransportReturnsSupabaseTransport(): void
    {
        $transport = self::$factory->transport();

        $this->assertInstanceOf(SupabaseTransport::class, $transport);
    }

    public function testCreateContextReturnsWorkingContext(): void
    {
        $context = self::$factory->createContext();

        // Anon context should be able to query the database
        $result = $context->db()->from('posts')->limit(1)->get();
        $this->assertIsArray($result);
    }

    public function testCreateServiceContextReturnsPrivilegedContext(): void
    {
        $context = self::$factory->createServiceContext();

        // Service context should be able to list users (admin operation)
        $result = $context->auth()->admin()->listUsers();
        $this->assertArrayHasKey('users', $result);
    }
}
