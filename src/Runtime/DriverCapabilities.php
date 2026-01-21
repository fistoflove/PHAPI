<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

interface DriverCapabilities
{
    /**
     * Determine if the runtime supports async I/O.
     *
     * @return bool
     */
    public function supportsAsyncIo(): bool;
    /**
     * Determine if the runtime supports WebSockets.
     *
     * @return bool
     */
    public function supportsWebSockets(): bool;
    /**
     * Determine if the runtime supports streaming responses.
     *
     * @return bool
     */
    public function supportsStreamingResponses(): bool;
    /**
     * Determine if the runtime supports persistent state across requests.
     *
     * @return bool
     */
    public function supportsPersistentState(): bool;
}
