<?php

declare(strict_types=1);

namespace PHAPI\Supabase;

/**
 * Immutable Supabase project configuration.
 *
 * @api
 */
final class SupabaseConfig
{
    public readonly string $url;
    public readonly string $anonKey;
    public readonly string $serviceRoleKey;
    public readonly string $schema;
    public readonly float $timeout;
    public readonly int $retries;
    /** @var array<string, array<string, mixed>> */
    public readonly array $buckets;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->url = rtrim((string) ($config['url'] ?? ''), '/');
        $this->anonKey = (string) ($config['anon_key'] ?? '');
        $this->serviceRoleKey = (string) ($config['service_role_key'] ?? '');
        $this->schema = (string) ($config['schema'] ?? 'public');
        $this->timeout = (float) ($config['timeout'] ?? 5.0);
        $this->retries = (int) ($config['retries'] ?? 0);
        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = $config['buckets'] ?? [];
        $this->buckets = $buckets;
    }

    /**
     * @return array<string, string>
     */
    public function headers(?string $accessToken = null): array
    {
        $headers = [
            'apikey' => $this->anonKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $token = $accessToken ?? $this->anonKey;
        $headers['Authorization'] = 'Bearer ' . $token;

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    public function serviceRoleHeaders(): array
    {
        return [
            'apikey' => $this->anonKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
