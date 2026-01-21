<?php

declare(strict_types=1);

namespace PHAPI\Contracts;

interface WebSocketDriverInterface
{
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
     * Access the connection registry.
     *
     * @return array<int, array{channels: array<string, bool>}>
     */
    public function connections(): array;
}
