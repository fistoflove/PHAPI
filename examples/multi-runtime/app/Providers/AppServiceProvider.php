<?php

declare(strict_types=1);

namespace PHAPI\Examples\MultiRuntime\Providers;

use PHAPI\Core\Container;
use PHAPI\Core\ServiceProviderInterface;
use PHAPI\Examples\MultiRuntime\Services\ExternalService;
use PHAPI\PHAPI;

final class AppServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container, PHAPI $app): void
    {
        $container->singleton(ExternalService::class, ExternalService::class);
        $container->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
    }

    public function boot(PHAPI $app): void
    {
    }
}
