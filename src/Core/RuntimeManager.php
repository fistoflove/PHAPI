<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Runtime\DriverCapabilities;
use PHAPI\Runtime\RuntimeInterface;
use PHAPI\Runtime\RuntimeSelector;

final class RuntimeManager
{
    /**
     * @var array<string, mixed>
     */
    private array $config;
    private RuntimeInterface $driver;
    private DriverCapabilities $capabilities;

    /**
     * @param array<string, mixed> $config
     * @return void
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->driver = RuntimeSelector::select($config);
        $this->capabilities = $this->driver->capabilities();
    }

    /**
     * @return RuntimeInterface
     */
    public function driver(): RuntimeInterface
    {
        return $this->driver;
    }

    /**
     * @return DriverCapabilities
     */
    public function capabilities(): DriverCapabilities
    {
        return $this->capabilities;
    }

    /**
     * @param array<string, mixed> $config
     * @return void
     */
    public function reconfigure(array $config): void
    {
        $this->config = $config;
        $this->driver = RuntimeSelector::select($config);
        $this->capabilities = $this->driver->capabilities();
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
