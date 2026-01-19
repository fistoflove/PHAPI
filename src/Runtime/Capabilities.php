<?php

namespace PHAPI\Runtime;

class Capabilities implements DriverCapabilities
{
    private bool $asyncIo;
    private bool $webSockets;
    private bool $streaming;
    private bool $persistentState;

    public function __construct(
        bool $asyncIo = false,
        bool $webSockets = false,
        bool $streaming = false,
        bool $persistentState = false
    ) {
        $this->asyncIo = $asyncIo;
        $this->webSockets = $webSockets;
        $this->streaming = $streaming;
        $this->persistentState = $persistentState;
    }

    public function supportsAsyncIo(): bool
    {
        return $this->asyncIo;
    }

    public function supportsWebSockets(): bool
    {
        return $this->webSockets;
    }

    public function supportsStreamingResponses(): bool
    {
        return $this->streaming;
    }

    public function supportsPersistentState(): bool
    {
        return $this->persistentState;
    }
}
