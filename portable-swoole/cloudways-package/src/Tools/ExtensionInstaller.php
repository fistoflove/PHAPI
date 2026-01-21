<?php

namespace PHAPI\Tools;

/**
 * Extension Installer - Helps install/verify Swoole extensions
 */
class ExtensionInstaller
{
    /**
     * Check Swoole extension status
     *
     * @return array Status information
     */
    public static function checkStatus(): array
    {
        $status = [
            'loaded' => extension_loaded('swoole'),
            'version' => null,
            'php_version' => PHP_VERSION,
            'php_major' => PHP_MAJOR_VERSION,
            'php_minor' => PHP_MINOR_VERSION,
            'platform' => self::detectPlatform(),
            'extension_dir' => ini_get('extension_dir'),
            'custom_ini_supported' => false,
        ];

        if ($status['loaded']) {
            $status['version'] = phpversion('swoole');
        }

        // Test custom php.ini support
        $testIni = sys_get_temp_dir() . '/phapi-test-' . uniqid() . '.ini';
        if (file_put_contents($testIni, '; test')) {
            $output = [];
            $returnVar = 0;
            exec("php -c $testIni -r 'echo php_ini_loaded_file();' 2>&1", $output, $returnVar);
            $status['custom_ini_supported'] = ($returnVar === 0);
            @unlink($testIni);
        }

        // Check for bundled extension
        $bundledPath = self::findBundledExtension();
        $status['bundled_extension_available'] = ($bundledPath !== null);
        $status['bundled_extension_path'] = $bundledPath;

        return $status;
    }

    /**
     * Detect platform identifier
     *
     * @return string Platform identifier
     */
    private static function detectPlatform(): string
    {
        $os = strtolower(PHP_OS);
        $arch = strtolower(php_uname('m'));
        
        if (strpos($os, 'linux') !== false) {
            $os = 'linux';
        }
        
        if ($arch === 'x86_64' || $arch === 'amd64') {
            $arch = 'x86_64';
        } elseif ($arch === 'aarch64' || $arch === 'arm64') {
            $arch = 'arm64';
        }
        
        return "{$os}-{$arch}";
    }

    /**
     * Find bundled Swoole extension
     *
     * @return string|null Path to extension or null
     */
    private static function findBundledExtension(): ?string
    {
        $extDir = __DIR__ . '/../bin/extensions';
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $platform = self::detectPlatform();
        
        $paths = [
            "{$extDir}/php{$phpVersion}/{$platform}/swoole.so",
            "{$extDir}/php" . PHP_MAJOR_VERSION . "/{$platform}/swoole.so",
            "{$extDir}/{$platform}/swoole.so",
            "{$extDir}/swoole.so",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Install bundled extension (create custom php.ini)
     *
     * @return bool True if successful
     */
    public static function install(): bool
    {
        $bundledPath = self::findBundledExtension();
        if ($bundledPath === null) {
            return false;
        }

        $extensionDir = dirname($bundledPath);
        $extensionFile = basename($bundledPath);
        $iniPath = getcwd() . '/phapi.ini';

        $iniContent = <<<INI
; PHAPI Custom php.ini
; Auto-generated for loading bundled Swoole extension
; PHP Version: {PHP_VERSION}
; Platform: {PLATFORM}

extension_dir = "$extensionDir"
extension = $extensionFile

INI;

        $iniContent = str_replace(['{PHP_VERSION}', '{PLATFORM}'], [
            PHP_VERSION,
            self::detectPlatform()
        ], $iniContent);

        return file_put_contents($iniPath, $iniContent) !== false;
    }

    /**
     * Get installation instructions
     *
     * @return array Instructions based on status
     */
    public static function getInstructions(): array
    {
        $status = self::checkStatus();
        $instructions = [];

        if ($status['loaded']) {
            $instructions[] = "✓ Swoole is already loaded (version: {$status['version']})";
            return $instructions;
        }

        if ($status['bundled_extension_available']) {
            $instructions[] = "Bundled extension found: {$status['bundled_extension_path']}";
            
            if ($status['custom_ini_supported']) {
                $instructions[] = "Run: php bin/phapi-install";
                $instructions[] = "Then use: php -c phapi.ini app.php";
            } else {
                $instructions[] = "Custom php.ini not supported. Try: php -c phapi.ini app.php manually";
            }
        } else {
            $instructions[] = "No bundled extension found for:";
            $instructions[] = "  PHP Version: {$status['php_version']}";
            $instructions[] = "  Platform: {$status['platform']}";
            $instructions[] = "";
            $instructions[] = "Options:";
            $instructions[] = "  1. Request Swoole installation from hosting provider";
            $instructions[] = "  2. Compile Swoole extension for your platform";
            $instructions[] = "  3. Use Docker if available";
        }

        return $instructions;
    }
}

