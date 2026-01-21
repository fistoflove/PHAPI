<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

class AmpFpmDriver extends FpmDriver
{
    private Capabilities $capabilities;

    /**
     * Initialize an FPM driver with AMPHP async capabilities.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->capabilities = new Capabilities(true, false, false, false);
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'fpm_amphp';
    }

    /**
     * {@inheritDoc}
     */
    public function isLongRunning(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * @return DriverCapabilities
     */
    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }
}
