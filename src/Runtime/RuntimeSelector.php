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
        $runtime = $config['runtime'] ?? (($runtimeEnv === false || $runtimeEnv === '') ? 'fpm' : $runtimeEnv);
        if ($runtime === 'auto') {
            if (extension_loaded('swoole') && class_exists('Swoole\\Http\\Server')) {
                return self::createSwoole($config, 'swoole');
            }
            return new FpmDriver();
        }

        if ($runtime === 'swoole') {
            if (!extension_loaded('swoole') || !class_exists('Swoole\\Http\\Server')) {
                throw new FeatureNotSupportedException('Swoole runtime requested but Swoole is not available.');
            }
            return self::createSwoole($config, 'swoole');
        }

        if ($runtime === 'portable_swoole') {
            if (!extension_loaded('swoole') || !class_exists('Swoole\\Http\\Server')) {
                if (!PortableSwooleLoader::load($config)) {
                    throw new FeatureNotSupportedException(
                        'Portable Swoole runtime requested but Swoole could not be loaded. ' .
                        'Set PHAPI_PORTABLE_SWOOLE_DIR or PHAPI_PORTABLE_SWOOLE_EXT, ' .
                        'run via bin/phapi-run, or start PHP with -d extension=/path/to/swoole.so.'
                    );
                }
            }
            return self::createSwoole($config, 'portable_swoole');
        }

        if ($runtime === 'fpm_amphp' || $runtime === 'amphp') {
            if (!class_exists('Amp\\Http\\Client\\HttpClientBuilder')) {
                throw new FeatureNotSupportedException('AMPHP runtime requested but AMPHP is not installed.');
            }
            return new AmpFpmDriver();
        }

        return new FpmDriver();
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
        return new SwooleDriver($host, $port, $enableWebSockets, $runtimeName);
    }
}
