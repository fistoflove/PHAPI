<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

class Capabilities implements DriverCapabilities
{
    private bool $asyncIo;
    private bool $webSockets;
    private bool $streaming;
    private bool $persistentState;

    /**
     * Create a capabilities descriptor.
     *
     * @param bool $asyncIo
     * @param bool $webSockets
     * @param bool $streaming
     * @param bool $persistentState
     * @return void
     */
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

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    public function supportsAsyncIo(): bool
    {
        return $this->asyncIo;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    public function supportsWebSockets(): bool
    {
        return $this->webSockets;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    public function supportsStreamingResponses(): bool
    {
        return $this->streaming;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    public function supportsPersistentState(): bool
    {
        return $this->persistentState;
    }
}
