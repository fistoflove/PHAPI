<?php

declare(strict_types=1);

namespace PHAPI\Services;

use PHAPI\Exceptions\FeatureNotSupportedException;

class FallbackRealtime implements Realtime
{
    private bool $debug;
    /**
     * @var callable(string, array<string, mixed>): void|null
     */
    private $fallback;

    /**
     * Create a fallback realtime handler.
     *
     * @param bool $debug
     * @param callable(string, array<string, mixed>): void|null $fallback
     * @return void
     */
    public function __construct(bool $debug = false, ?callable $fallback = null)
    {
        $this->debug = $debug;
        $this->fallback = $fallback;
    }

    /**
     * Broadcast a message via fallback handler.
     *
     * @param string $channel
     * @param array<string, mixed> $message
     * @return void
     */
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
