<?php

declare(strict_types=1);

namespace PHAPI\Services;

class SwooleRealtime implements Realtime
{
    private \Swoole\WebSocket\Server $server;
    /**
     * @var array<int, array{channels: array<string, bool>}>
     */
    private array $connections;

    /**
     * Create a realtime WebSocket broadcaster.
     *
     * @param \Swoole\WebSocket\Server $server
     * @param array<int, array{channels: array<string, bool>}> $connections
     * @return void
     */
    public function __construct(\Swoole\WebSocket\Server $server, array &$connections)
    {
        $this->server = $server;
        $this->connections = &$connections;
    }

    /**
     * Broadcast a message to subscribed connections.
     *
     * @param string $channel
     * @param array<string, mixed> $message
     * @return void
     */
    public function broadcast(string $channel, array $message): void
    {
        $payload = json_encode([
            'channel' => $channel,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $payload = '';
        }

        foreach ($this->connections as $fd => $info) {
            if ($channel === '') {
                $this->server->push($fd, $payload);
                continue;
            }

            $channels = $info['channels'];
            if (($channels[$channel] ?? false) === true) {
                $this->server->push($fd, $payload);
            }
        }
    }
}
