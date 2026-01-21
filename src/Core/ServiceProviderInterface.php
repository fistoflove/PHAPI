<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\PHAPI;

interface ServiceProviderInterface
{
    /**
     * Register bindings or services.
     *
     * @param Container $container
     * @param PHAPI $app
     * @return void
     */
    public function register(Container $container, PHAPI $app): void;

    /**
     * Boot services after registration.
     *
     * @param PHAPI $app
     * @return void
     */
    public function boot(PHAPI $app): void;
}
