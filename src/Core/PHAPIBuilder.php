<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Exceptions\ConfigException;
use PHAPI\PHAPI;

/**
 * @api
 */
final class PHAPIBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    public function host(string $host): self
    {
        $this->config['host'] = $host;
        return $this;
    }

    public function port(int $port): self
    {
        $this->config['port'] = $port;
        return $this;
    }

    public function debug(bool $debug): self
    {
        $this->config['debug'] = $debug;
        return $this;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function mysql(array $config): self
    {
        $this->config['mysql'] = $config;
        return $this;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function redis(array $config): self
    {
        $this->config['redis'] = $config;
        return $this;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function openfga(array $config): self
    {
        $this->config['openfga'] = $config;
        return $this;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function telemetry(array $config): self
    {
        $this->config['telemetry'] = $config;
        return $this;
    }

    /**
     * @param array<int, class-string<ServiceProviderInterface>> $providers
     */
    public function providers(array $providers): self
    {
        $this->config['providers'] = $providers;
        return $this;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function swooleSettings(array $settings): self
    {
        $this->config['swoole_settings'] = $settings;
        return $this;
    }

    public function enableWebSockets(bool $enabled = true): self
    {
        $this->config['enable_websockets'] = $enabled;
        return $this;
    }

    /**
     * @param bool|array{monitor?: bool} $endpoints
     */
    public function defaultEndpoints(bool|array $endpoints): self
    {
        $this->config['default_endpoints'] = $endpoints;
        return $this;
    }

    /**
     * Generic escape hatch for any config key not covered by typed setters.
     */
    public function config(string $key, mixed $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    public function build(): PHAPI
    {
        $this->validate();
        return new PHAPI($this->config);
    }

    private function validate(): void
    {
        if (isset($this->config['port'])) {
            $port = $this->config['port'];
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw new ConfigException("Invalid port: {$port}. Must be between 1 and 65535.");
            }
        }

        if (isset($this->config['mysql'])) {
            $mysql = $this->config['mysql'];
            if (!is_array($mysql)) {
                throw new ConfigException('mysql config must be an array.');
            }
        }

        if (isset($this->config['redis'])) {
            $redis = $this->config['redis'];
            if (!is_array($redis)) {
                throw new ConfigException('redis config must be an array.');
            }
        }

        if (isset($this->config['openfga'])) {
            $openfga = $this->config['openfga'];
            if (!is_array($openfga)) {
                throw new ConfigException('openfga config must be an array.');
            }
        }

        if (isset($this->config['providers'])) {
            $providers = $this->config['providers'];
            if (!is_array($providers)) {
                throw new ConfigException('providers must be an array.');
            }
        }

        if (isset($this->config['swoole_settings'])) {
            $settings = $this->config['swoole_settings'];
            if (!is_array($settings)) {
                throw new ConfigException('swoole_settings must be an array.');
            }
        }
    }
}
