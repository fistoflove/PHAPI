<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Database;

use PHAPI\Supabase\Exceptions\SupabaseDatabaseException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Supabase PostgREST database client.
 *
 * @api
 */
final class DatabaseClient
{
    private const RPC_PREFIX = '/rest/v1/rpc/';

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
        private readonly ?string $accessToken = null,
    ) {
    }

    /**
     * Start a query builder for a table.
     */
    public function from(string $table): QueryBuilder
    {
        return new QueryBuilder($this->transport, $this->config, $table, $this->accessToken);
    }

    /**
     * Call a database function via RPC.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function rpc(string $function, array $params = []): array
    {
        $path = self::RPC_PREFIX . rawurlencode($function);
        $headers = $this->config->headers($this->accessToken);

        $response = $this->transport->request('POST', $path, $params, $headers);

        if ($response['status'] >= 400) {
            throw SupabaseDatabaseException::fromResponse($response, 'RPC call failed: ' . $function);
        }

        return is_array($response['data']) ? $response['data'] : [];
    }
}
