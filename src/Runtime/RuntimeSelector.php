<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\Exceptions\ConfigException;

class RuntimeSelector
{
    /**
     * Select the runtime driver (Swoole only).
     *
     * @param array<string, mixed> $config
     * @return RuntimeInterface
     *
     * @throws ConfigException
     */
    public static function select(array $config): RuntimeInterface
    {
        if (!extension_loaded('swoole') || !class_exists('Swoole\\Http\\Server')) {
            throw new ConfigException('Swoole extension is required by PHAPI.');
        }

        return self::createSwoole($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return SwooleDriver
     */
    private static function createSwoole(array $config): SwooleDriver
    {
        $host = $config['host'] ?? '0.0.0.0';
        $port = (int)($config['port'] ?? 9501);
        $enableWebSockets = (bool)($config['enable_websockets'] ?? false);
        $settings = self::normalizeSwooleSettings($config['swoole_settings'] ?? []);
        return new SwooleDriver($host, $port, $enableWebSockets, 'swoole', $settings);
    }

    /**
     * @param mixed $value
     * @return array<string, bool|int|float|string>
     */
    private static function normalizeSwooleSettings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $settings = [];
        foreach ($value as $key => $settingValue) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_bool($settingValue) || is_int($settingValue) || is_float($settingValue) || is_string($settingValue)) {
                $settings[$key] = $settingValue;
            }
        }

        return $settings;
    }
}
