<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

use PHAPI\Exceptions\FeatureNotSupportedException;

class RuntimeSelector
{
    /**
     * Select the appropriate runtime driver based on configuration.
     *
     * @param array<string, mixed> $config
     * @return RuntimeInterface
     *
     * @throws FeatureNotSupportedException
     */
    public static function select(array $config): RuntimeInterface
    {
        $runtimeEnv = getenv('APP_RUNTIME');
        $runtime = $config['runtime'] ?? (($runtimeEnv === false || $runtimeEnv === '') ? 'swoole' : $runtimeEnv);
        if ($runtime === 'swoole') {
            if (!extension_loaded('swoole') || !class_exists('Swoole\\Http\\Server')) {
                throw new FeatureNotSupportedException('Swoole runtime requested but Swoole is not available.');
            }
            return self::createSwoole($config, 'swoole');
        }

        if ($runtime === 'portable_swoole') {
            throw new FeatureNotSupportedException('portable_swoole runtime is no longer supported. Use runtime=swoole.');
        }

        throw new FeatureNotSupportedException("Unsupported runtime '{$runtime}'. Supported runtime: swoole.");
    }

    /**
     * @param array<string, mixed> $config
     * @return SwooleDriver
     */
    private static function createSwoole(array $config, string $runtimeName): SwooleDriver
    {
        $host = $config['host'] ?? '0.0.0.0';
        $port = (int)($config['port'] ?? 9501);
        $enableWebSockets = (bool)($config['enable_websockets'] ?? false);
        $settings = self::normalizeSwooleSettings($config['swoole_settings'] ?? []);
        return new SwooleDriver($host, $port, $enableWebSockets, $runtimeName, $settings);
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
