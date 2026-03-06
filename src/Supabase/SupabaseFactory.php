<?php

declare(strict_types=1);

namespace PHAPI\Supabase;

use PHAPI\Supabase\Realtime\RealtimeClient;

/**
 * Factory for creating SupabaseContext instances.
 *
 * Registered as a singleton — holds only immutable config and transport.
 *
 * @api
 */
final class SupabaseFactory
{
    private ?RealtimeClient $realtimeClient = null;

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
    ) {
    }

    /**
     * Create a request-scoped context for the given access token.
     */
    public function createContext(?string $accessToken = null): SupabaseContext
    {
        return new SupabaseContext($this->transport, $this->config, $accessToken);
    }

    /**
     * Create a context with service-role privileges.
     */
    public function createServiceContext(): SupabaseContext
    {
        return new SupabaseContext($this->transport, $this->config, $this->config->serviceRoleKey);
    }

    /**
     * Get the worker-level Realtime client singleton.
     *
     * The WebSocket connection is established lazily on first channel subscribe.
     */
    public function realtime(): RealtimeClient
    {
        if ($this->realtimeClient === null) {
            $token = $this->config->serviceRoleKey !== '' ? $this->config->serviceRoleKey : null;
            $this->realtimeClient = new RealtimeClient($this->config, $token);
        }

        return $this->realtimeClient;
    }

    public function config(): SupabaseConfig
    {
        return $this->config;
    }

    public function transport(): SupabaseTransport
    {
        return $this->transport;
    }
}
