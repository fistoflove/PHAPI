<?php

declare(strict_types=1);

namespace PHAPI\Supabase;

use PHAPI\Supabase\Auth\AuthClient;
use PHAPI\Supabase\Database\DatabaseClient;
use PHAPI\Supabase\Exceptions\SupabaseRealtimeException;
use PHAPI\Supabase\Functions\EdgeFunctionsClient;
use PHAPI\Supabase\Realtime\RealtimeClient;
use PHAPI\Supabase\Storage\StorageClient;

/**
 * Request-scoped Supabase context.
 *
 * Holds the authenticated user's token and lazily creates service clients.
 * Disposed at the end of each request to prevent cross-request state leaks.
 *
 * @api
 */
final class SupabaseContext
{
    private ?AuthClient $authClient = null;
    private ?DatabaseClient $dbClient = null;
    private ?StorageClient $storageClient = null;
    private ?EdgeFunctionsClient $functionsClient = null;

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
        private readonly ?string $accessToken = null,
        private readonly ?RealtimeClient $realtimeClient = null,
    ) {
    }

    public function auth(): AuthClient
    {
        if ($this->authClient === null) {
            $this->authClient = new AuthClient($this->transport, $this->config, $this->accessToken);
        }
        return $this->authClient;
    }

    public function db(): DatabaseClient
    {
        if ($this->dbClient === null) {
            $this->dbClient = new DatabaseClient($this->transport, $this->config, $this->accessToken);
        }
        return $this->dbClient;
    }

    public function storage(): StorageClient
    {
        if ($this->storageClient === null) {
            $this->storageClient = new StorageClient($this->transport, $this->config, $this->accessToken);
        }
        return $this->storageClient;
    }

    public function functions(): EdgeFunctionsClient
    {
        if ($this->functionsClient === null) {
            $this->functionsClient = new EdgeFunctionsClient($this->transport, $this->config, $this->accessToken);
        }
        return $this->functionsClient;
    }

    /**
     * Get the worker-level Realtime client.
     *
     * The Realtime client is a singleton shared across requests because it
     * maintains a persistent WebSocket connection. It must be injected via
     * the constructor (typically by SupabaseFactory).
     */
    public function realtime(): RealtimeClient
    {
        if ($this->realtimeClient === null) {
            throw new SupabaseRealtimeException(
                'Realtime client not available. Access it via SupabaseFactory::realtime() or inject it in the context constructor.',
            );
        }

        return $this->realtimeClient;
    }

    public function accessToken(): ?string
    {
        return $this->accessToken;
    }
}
