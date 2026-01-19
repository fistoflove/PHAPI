<?php

namespace PHAPI\Services;

class SwooleRealtime implements Realtime
{
    private \Swoole\WebSocket\Server $server;
    private array $connections;

    public function __construct(\Swoole\WebSocket\Server $server, array &$connections)
    {
        $this->server = $server;
        $this->connections = &$connections;
    }

    public function broadcast(string $channel, array $message): void
    {
        $payload = json_encode([
            'channel' => $channel,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($this->connections as $fd => $info) {
            if ($channel === '') {
                $this->server->push($fd, $payload);
                continue;
            }

            $channels = $info['channels'] ?? [];
            if (!empty($channels[$channel])) {
                $this->server->push($fd, $payload);
            }
        }
    }
}
