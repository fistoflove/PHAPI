<?php

namespace PHAPI\Runtime;

class AmpFpmDriver extends FpmDriver
{
    private Capabilities $capabilities;

    public function __construct()
    {
        $this->capabilities = new Capabilities(true, false, false, false);
    }

    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }
}
