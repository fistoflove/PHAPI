<?php

namespace PHAPI\Runtime;

use PHAPI\Server\HttpKernel;

interface HttpRuntimeDriver
{
    public function start(HttpKernel $kernel): void;
    public function capabilities(): DriverCapabilities;
}
