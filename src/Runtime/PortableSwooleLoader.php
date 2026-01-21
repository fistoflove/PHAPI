<?php

declare(strict_types=1);

namespace PHAPI\Runtime;

class PortableSwooleLoader
{
    private static bool $loaded = false;

    /**
     * Attempt to load the bundled Swoole extension.
     *
     * @param array<string, mixed> $config
     * @return bool
     */
    public static function load(array $config = []): bool
    {
        if (extension_loaded('swoole')) {
            return true;
        }

        if (!function_exists('dl')) {
            return false;
        }

        $extensionPath = self::resolveExtensionPathFromConfig($config);

        if ($extensionPath === null) {
            $envDir = getenv('PHAPI_PORTABLE_SWOOLE_DIR');
            $baseDir = $config['portable_swoole_dir']
                ?? (($envDir === false || $envDir === '') ? dirname(__DIR__, 2) . '/portable-swoole' : $envDir);
            $extensionPath = self::resolveExtensionPath($baseDir);
        }

        if ($extensionPath === null || !is_file($extensionPath) || !is_readable($extensionPath)) {
            return false;
        }

        $extensionDir = dirname($extensionPath);
        $extensionFile = basename($extensionPath);
        @ini_set('extension_dir', $extensionDir);
        @dl($extensionFile);

        /** @phpstan-ignore-next-line */
        if (extension_loaded('swoole')) {
            self::$loaded = true;
            return true;
        }

        return false;
    }

    /**
     * Determine if the portable loader successfully loaded the extension.
     *
     * @return bool
     */
    public static function wasLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Resolve the extension path from configuration or defaults.
     *
     * @param array<string, mixed> $config
     * @return string|null
     */
    public static function resolveExtensionPathFromConfig(array $config = []): ?string
    {
        $envPath = getenv('PHAPI_PORTABLE_SWOOLE_EXT');
        $extensionPath = $config['portable_swoole_extension']
            ?? (($envPath === false || $envPath === '') ? null : $envPath);

        if ($extensionPath !== null) {
            return $extensionPath;
        }

        $envDir = getenv('PHAPI_PORTABLE_SWOOLE_DIR');
        $baseDir = $config['portable_swoole_dir']
            ?? (($envDir === false || $envDir === '') ? dirname(__DIR__, 2) . '/portable-swoole' : $envDir);

        return self::resolveExtensionPath($baseDir);
    }

    private static function resolveExtensionPath(string $baseDir): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        $arch = php_uname('m');
        $archDir = $arch === 'x86_64' ? 'linux-x86_64' : 'linux-' . $arch;
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        return rtrim($baseDir, '/') . '/bin/extensions/php' . $version . '/' . $archDir . '/swoole.so';
    }
}
