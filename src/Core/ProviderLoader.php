<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\PHAPI;

final class ProviderLoader
{
    /**
     * @param array<int, class-string<ServiceProviderInterface>> $providers
     * @param Container $container
     * @param PHAPI $app
     * @return array<int, ServiceProviderInterface>
     */
    public function register(array $providers, Container $container, PHAPI $app): array
    {
        $instances = [];
        foreach ($providers as $providerClass) {
            $provider = $container->get($providerClass);
            if (!$provider instanceof ServiceProviderInterface) {
                throw new \RuntimeException("Provider {$providerClass} must implement ServiceProviderInterface");
            }
            $provider->register($container, $app);
            $instances[] = $provider;
        }

        return $instances;
    }

    /**
     * @param array<int, ServiceProviderInterface> $providers
     * @param PHAPI $app
     * @return void
     */
    public function boot(array $providers, PHAPI $app): void
    {
        foreach ($providers as $provider) {
            $provider->boot($app);
        }
    }
}
