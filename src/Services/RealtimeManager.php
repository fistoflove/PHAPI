<?php

namespace PHAPI\Services;

use PHAPI\Runtime\DriverCapabilities;
use PHAPI\Runtime\SwooleDriver;

class RealtimeManager implements Realtime
{
    private DriverCapabilities $capabilities;
    private ?SwooleDriver $swooleDriver;
    private FallbackRealtime $fallback;

    public function __construct(DriverCapabilities $capabilities, ?SwooleDriver $swooleDriver, FallbackRealtime $fallback)
    {
        $this->capabilities = $capabilities;
        $this->swooleDriver = $swooleDriver;
        $this->fallback = $fallback;
    }

    public function broadcast(string $channel, array $message): void
    {
        if ($this->capabilities->supportsWebSockets() && $this->swooleDriver !== null) {
            $server = $this->swooleDriver->websocketServer();
            if ($server !== null) {
                $connections = &$this->swooleDriver->connections();
                $realtime = new SwooleRealtime($server, $connections);
                $realtime->broadcast($channel, $message);
                return;
            }
        }

        $this->fallback->broadcast($channel, $message);
    }
}
