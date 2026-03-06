<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseFactory;
use PHAPI\Supabase\SupabaseTransport;
use PHPUnit\Framework\TestCase;

/**
 * Base class for Supabase integration tests.
 *
 * Supports two modes:
 *   1. Local Docker stack (default):
 *        docker compose -f docker-compose.supabase.yml --env-file docker/supabase/.env up -d
 *
 *   2. Real Supabase project (env vars):
 *        set -a && source .env.supabase && set +a && composer test:supabase
 *
 * When SUPABASE_DB_URL is set, the test schema (tables, functions, grants)
 * is automatically bootstrapped via direct PostgreSQL and torn down after
 * the full suite completes. No manual SQL setup needed.
 *
 * @group integration
 * @group supabase
 */
abstract class SupabaseIntegrationTestCase extends TestCase
{
    protected static SupabaseConfig $config;
    protected static SupabaseTransport $transport;
    protected static SupabaseFactory $factory;
    private static ?SupabaseTestBootstrapper $bootstrapper = null;
    private static bool $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('SUPABASE_URL') ?: 'http://localhost:8000';
        $anonKey = getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJyb2xlIjoiYW5vbiIsImlzcyI6InN1cGFiYXNlIiwiaWF0IjoxNzAwMDAwMDAwLCJleHAiOjQxMDI0NDQ4MDB9.IbzzANYcwCWU72DSTJjxoeyUS6WuH8PVQQoP1ToYcrk';
        $serviceRoleKey = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJyb2xlIjoic2VydmljZV9yb2xlIiwiaXNzIjoic3VwYWJhc2UiLCJpYXQiOjE3MDAwMDAwMDAsImV4cCI6NDEwMjQ0NDgwMH0.BHXMujlqt3YKpGKGh2KQhEIyef5oWmD0QqY8JfPFm-Q';

        // Self-bootstrap schema via direct PostgreSQL if configured
        self::bootstrapSchema();

        // Verify Supabase REST API is reachable
        $ch = curl_init($url . '/rest/v1/');
        if ($ch === false) {
            self::markTestSkipped('curl not available');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $anonKey,
            'Authorization: Bearer ' . $anonKey,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode === 0) {
            self::markTestSkipped(
                'Supabase not available at ' . $url . '. '
                . 'Either set SUPABASE_URL/SUPABASE_ANON_KEY/SUPABASE_SERVICE_ROLE_KEY env vars for a real project, '
                . 'or run: docker compose -f docker-compose.supabase.yml --env-file docker/supabase/.env up -d'
            );
        }

        self::$config = new SupabaseConfig([
            'url' => $url,
            'anon_key' => $anonKey,
            'service_role_key' => $serviceRoleKey,
            'timeout' => 10.0,
        ]);

        self::$transport = new SupabaseTransport(self::$config);
        self::$factory = new SupabaseFactory(self::$transport, self::$config);
    }

    /**
     * Generate a unique test email to avoid collisions between test runs.
     */
    protected function testEmail(string $prefix = 'test'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4)) . '@phapi-test.local';
    }

    /**
     * Bootstrap test schema via direct PostgreSQL connection.
     *
     * Only runs once per test suite execution. Requires SUPABASE_DB_URL
     * and ext-pdo_pgsql. If unavailable, tests assume schema already exists
     * (Docker init.sql or manual setup).
     */
    private static function bootstrapSchema(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        $dbUrl = getenv('SUPABASE_DB_URL');
        if ($dbUrl === false || $dbUrl === '') {
            // No direct PG connection — rely on Docker init.sql or pre-existing schema
            return;
        }

        if (!extension_loaded('pdo_pgsql')) {
            fwrite(STDERR, "[PHAPI Test] SUPABASE_DB_URL is set but ext-pdo_pgsql is not loaded. Skipping bootstrap.\n");
            return;
        }

        try {
            self::$bootstrapper = new SupabaseTestBootstrapper($dbUrl);

            if (!self::$bootstrapper->isReady()) {
                fwrite(STDERR, "[PHAPI Test] Bootstrapping test schema via PostgreSQL...\n");
            }

            self::$bootstrapper->setUp();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[PHAPI Test] Schema bootstrap failed: " . $e->getMessage() . "\n");
            self::$bootstrapper = null;
        }
    }

    /**
     * Clean up: drop test schema if we bootstrapped it.
     *
     * Registered as a shutdown function so it runs once after all test
     * classes, not after each individual class's tearDownAfterClass.
     */
    public static function tearDownSchema(): void
    {
        if (self::$bootstrapper !== null) {
            try {
                fwrite(STDERR, "[PHAPI Test] Tearing down test schema...\n");
                self::$bootstrapper->tearDown();
            } catch (\Throwable $e) {
                fwrite(STDERR, "[PHAPI Test] Schema teardown failed: " . $e->getMessage() . "\n");
            }
            self::$bootstrapper = null;
        }
    }
}

// Register global shutdown to tear down schema once after the entire suite
register_shutdown_function([SupabaseIntegrationTestCase::class, 'tearDownSchema']);
