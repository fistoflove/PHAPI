<?php

namespace PHAPI\Services;

use PHAPI\Exceptions\FeatureNotSupportedException;

class FallbackRealtime implements Realtime
{
    private bool $debug;
    private $fallback;

    public function __construct(bool $debug = false, ?callable $fallback = null)
    {
        $this->debug = $debug;
        $this->fallback = $fallback;
    }

    public function broadcast(string $channel, array $message): void
    {
        if ($this->fallback !== null) {
            ($this->fallback)($channel, $message);
            return;
        }

        if ($this->debug) {
            throw new FeatureNotSupportedException(
                'WebSockets are not supported by the current runtime. Use the Swoole runtime or configure a polling fallback.'
            );
        }
    }
}
