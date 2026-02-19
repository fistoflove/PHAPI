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
     * @param array<string, mixed> $config
     * @return void
     */
    public function register(Container $container, array $config): void;

    /**
     * Boot services after registration.
     *
     * @param PHAPI $app
     * @return void
     */
    public function boot(PHAPI $app): void;
}
