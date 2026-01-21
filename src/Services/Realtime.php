<?php

declare(strict_types=1);

namespace PHAPI\Services;

interface Realtime
{
    /**
     * Broadcast a message to a channel.
     *
     * @param string $channel
     * @param array<string, mixed> $message
     * @return void
     */
    public function broadcast(string $channel, array $message): void;
}
