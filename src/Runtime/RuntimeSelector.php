<?php

namespace PHAPI\Runtime;

use PHAPI\Exceptions\FeatureNotSupportedException;

class RuntimeSelector
{
    public static function select(array $config): HttpRuntimeDriver
    {
        $runtime = $config['runtime'] ?? getenv('APP_RUNTIME') ?: 'fpm';
        if ($runtime === 'auto') {
            if (extension_loaded('swoole') && class_exists('Swoole\\Http\\Server')) {
                return self::createSwoole($config);
            }
            return new FpmDriver();
        }

        if ($runtime === 'swoole') {
            if (!extension_loaded('swoole') || !class_exists('Swoole\\Http\\Server')) {
                throw new FeatureNotSupportedException('Swoole runtime requested but Swoole is not available.');
            }
            return self::createSwoole($config);
        }

        if ($runtime === 'fpm_amphp' || $runtime === 'amphp') {
            if (!class_exists('Amp\\Http\\Client\\HttpClientBuilder')) {
                throw new FeatureNotSupportedException('AMPHP runtime requested but AMPHP is not installed.');
            }
            return new AmpFpmDriver();
        }

        return new FpmDriver();
    }

    private static function createSwoole(array $config): SwooleDriver
    {
        $host = $config['host'] ?? '0.0.0.0';
        $port = (int)($config['port'] ?? 9501);
        $enableWebSockets = (bool)($config['enable_websockets'] ?? false);
        return new SwooleDriver($host, $port, $enableWebSockets);
    }
}
