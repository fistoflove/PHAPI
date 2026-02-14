<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Runtime\SwooleDriver;

class RealtimeManager implements Realtime
{
    private ?SwooleDriver $swooleDriver;

    /**
     * Create a realtime manager.
     *
     * @param SwooleDriver|null $swooleDriver
     * @return void
     */
    public function __construct(?SwooleDriver $swooleDriver)
    {
        $this->swooleDriver = $swooleDriver;
    }

    /**
     * Broadcast a message to a channel.
     *
     * @param string $channel
     * @param array<string, mixed> $message
     * @return void
     */
    public function broadcast(string $channel, array $message): void
    {
        if ($this->swooleDriver === null) {
            throw new \RuntimeException('Realtime requires the Swoole runtime driver.');
        }

        $server = $this->swooleDriver->websocketServer();
        if ($server === null) {
            throw new \RuntimeException('Realtime requires enable_websockets=true.');
        }

        $connections = &$this->swooleDriver->connections();
        $realtime = new WebSocketRealtime($server, $connections);
        $realtime->broadcast($channel, $message);
    }
}
