<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\Server\HttpKernel;

interface HttpRuntimeDriver
{
    /**
     * Start the runtime and hand control to the HTTP kernel.
     *
     * @param HttpKernel $kernel
     * @return void
     */
    public function start(HttpKernel $kernel): void;
    /**
     * Describe runtime capabilities.
     *
     * @return DriverCapabilities
     */
    public function capabilities(): DriverCapabilities;
}
