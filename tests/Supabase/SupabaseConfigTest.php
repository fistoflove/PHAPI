<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class SupabaseConfigTest extends TestCase
{
    public function testParsesConfigArray(): void
    {
        $config = new SupabaseConfig([
            'url' => 'https://test.supabase.co/',
            'anon_key' => 'anon-key',
            'service_role_key' => 'service-key',
            'schema' => 'custom',
            'timeout' => 10.0,
            'retries' => 3,
        ]);

        $this->assertSame('https://test.supabase.co', $config->url);
        $this->assertSame('anon-key', $config->anonKey);
        $this->assertSame('service-key', $config->serviceRoleKey);
        $this->assertSame('custom', $config->schema);
        $this->assertSame(10.0, $config->timeout);
        $this->assertSame(3, $config->retries);
    }

    public function testParsesConfigWithBuckets(): void
    {
        $config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'key',
            'buckets' => [
                'avatars' => ['public' => true],
                'docs' => ['public' => false, 'file_size_limit' => 10485760],
            ],
        ]);

        $this->assertCount(2, $config->buckets);
        $this->assertTrue($config->buckets['avatars']['public']);
        $this->assertFalse($config->buckets['docs']['public']);
        $this->assertSame(10485760, $config->buckets['docs']['file_size_limit']);
    }

    public function testDefaultValues(): void
    {
        $config = new SupabaseConfig([]);

        $this->assertSame('', $config->url);
        $this->assertSame('', $config->anonKey);
        $this->assertSame('', $config->serviceRoleKey);
        $this->assertSame('public', $config->schema);
        $this->assertSame(5.0, $config->timeout);
        $this->assertSame(0, $config->retries);
        $this->assertSame([], $config->buckets);
    }

    public function testHeadersWithToken(): void
    {
        $config = new SupabaseConfig([
            'anon_key' => 'my-key',
        ]);

        $headers = $config->headers('user-jwt');
        $this->assertSame('my-key', $headers['apikey']);
        $this->assertSame('Bearer user-jwt', $headers['Authorization']);
    }

    public function testHeadersWithoutTokenUsesAnonKey(): void
    {
        $config = new SupabaseConfig([
            'anon_key' => 'my-key',
        ]);

        $headers = $config->headers();
        $this->assertSame('Bearer my-key', $headers['Authorization']);
    }

    public function testServiceRoleHeaders(): void
    {
        $config = new SupabaseConfig([
            'anon_key' => 'anon',
            'service_role_key' => 'service',
        ]);

        $headers = $config->serviceRoleHeaders();
        $this->assertSame('anon', $headers['apikey']);
        $this->assertSame('Bearer service', $headers['Authorization']);
    }
}
