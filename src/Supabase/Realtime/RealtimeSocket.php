<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Realtime;

/**
 * WebSocket transport abstraction for Supabase Realtime.
 *
 * Decouples the RealtimeClient from Swoole so unit tests can inject
 * a fake implementation without requiring the Swoole extension.
 *
 * @api
 */
interface RealtimeSocket
{
    /**
     * Connect and upgrade to WebSocket.
     */
    public function connect(string $host, int $port, bool $ssl, string $path): void;

    /**
     * Send a text frame.
     */
    public function send(string $data): bool;

    /**
     * Receive a text frame. Returns message data, or null on timeout/disconnect.
     */
    public function recv(float $timeout): ?string;

    /**
     * Close the connection.
     */
    public function close(): void;

    /**
     * Whether the WebSocket connection is active.
     */
    public function isConnected(): bool;
}
