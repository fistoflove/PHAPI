<?php

declare(strict_types=1);

namespace PHAPI\Contracts;

interface WebSocketDriverInterface
{
    /**
     * Send a payload to a specific WebSocket connection.
     *
     * @param int $fd
     * @param string $payload
     * @return bool
     */
    public function send(int $fd, string $payload): bool;

    /**
     * Subscribe a connection to a channel.
     *
     * @param int $fd
     * @param string $channel
     * @return void
     */
    public function subscribe(int $fd, string $channel): void;

    /**
     * Unsubscribe a connection from a channel.
     *
     * @param int $fd
     * @param string $channel
     * @return void
     */
    public function unsubscribe(int $fd, string $channel): void;

    /**
     * Determine if a WebSocket connection is established.
     *
     * @param int $fd
     * @return bool
     */
    public function isConnectionEstablished(int $fd): bool;

    /**
     * Disconnect a WebSocket connection.
     *
     * @param int $fd
     * @param int $code
     * @param string $reason
     * @return bool
     */
    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool;

    /**
     * Access the connection registry.
     *
     * @return array<int, array{channels: array<string, bool>}>
     */
    public function connections(): array;
}
