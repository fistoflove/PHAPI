<?php

namespace PHAPI\Runtime;

interface DriverCapabilities
{
    public function supportsAsyncIo(): bool;
    public function supportsWebSockets(): bool;
    public function supportsStreamingResponses(): bool;
    public function supportsPersistentState(): bool;
}
