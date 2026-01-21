<?php

namespace PHAPI\Tools;

/**
 * Extension Loader - Automatically loads Swoole extension if not available
 * Works on platforms that support custom php.ini (like Cloudways)
 */
class ExtensionLoader
{
    private const EXT_DIR = __DIR__ . '/../bin/extensions';
    private const INI_NAME = 'phapi.ini';
    
    /**
     * Try to load Swoole extension if not already loaded
     *
     * @return bool True if Swoole is now loaded, false otherwise
     */
    public static function loadSwoole(): bool
    {
        // Check if Swoole is already loaded
        if (extension_loaded('swoole')) {
            return true;
        }

        // Find matching extension
        $extensionPath = self::findExtension();
        if ($extensionPath === null) {
            return false;
        }

        // Try to load via dl() (if allowed)
        if (function_exists('dl') && ini_get('enable_dl')) {
            if (@dl(basename($extensionPath))) {
                return extension_loaded('swoole');
            }
        }

        // If dl() doesn't work, create custom php.ini
        return self::loadViaCustomIni($extensionPath);
    }

    /**
     * Find matching Swoole extension for current PHP version and platform
     *
     * @return string|null Path to extension file or null if not found
     */
    private static function findExtension(): ?string
    {
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $platform = self::detectPlatform();
        
        // Try exact match first: php8.1/linux-x86_64/swoole.so
        $paths = [
            self::EXT_DIR . "/php{$phpVersion}/{$platform}/swoole.so",
            self::EXT_DIR . "/php{$phpVersion}/{$platform}/swoole-{$platform}.so",
            // Try without minor version
            self::EXT_DIR . "/php" . PHP_MAJOR_VERSION . "/{$platform}/swoole.so",
            // Try generic
            self::EXT_DIR . "/{$platform}/swoole.so",
            self::EXT_DIR . "/swoole.so",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Detect platform identifier
     *
     * @return string Platform identifier (e.g., linux-x86_64, linux-arm64)
     */
    private static function detectPlatform(): string
    {
        $os = PHP_OS;
        $arch = php_uname('m');
        
        // Normalize OS
        $osName = strtolower($os);
        if (strpos($osName, 'linux') !== false) {
            $osName = 'linux';
        } elseif (strpos($osName, 'darwin') !== false) {
            $osName = 'macos';
        } elseif (strpos($osName, 'win') !== false) {
            $osName = 'windows';
        }
        
        // Normalize architecture
        $archNormalized = strtolower($arch);
        if ($archNormalized === 'x86_64' || $archNormalized === 'amd64') {
            $archNormalized = 'x86_64';
        } elseif ($archNormalized === 'aarch64' || $archNormalized === 'arm64') {
            $archNormalized = 'arm64';
        } elseif (strpos($archNormalized, 'arm') !== false) {
            $archNormalized = 'arm';
        }
        
        return "{$osName}-{$archNormalized}";
    }

    /**
     * Load extension via custom php.ini
     *
     * @param string $extensionPath Path to extension file
     * @return bool True if successfully loaded
     */
    private static function loadViaCustomIni(string $extensionPath): bool
    {
        $iniPath = self::getIniPath();
        $extensionDir = dirname($extensionPath);
        $extensionFile = basename($extensionPath);
        
        // Create custom php.ini
        $iniContent = <<<INI
; PHAPI Custom php.ini
; Auto-generated for loading bundled Swoole extension

extension_dir = "$extensionDir"
extension = $extensionFile

INI;

        // Write php.ini in current directory
        if (file_put_contents($iniPath, $iniContent)) {
            // Note: php.ini needs to be specified via -c flag or PHPRC env var
            // This will be handled by the wrapper script
            return true;
        }

        return false;
    }

    /**
     * Get path to custom php.ini file
     *
     * @return string Path to php.ini file
     */
    private static function getIniPath(): string
    {
        // Try current working directory first
        $cwd = getcwd();
        if ($cwd && is_writable($cwd)) {
            return $cwd . '/' . self::INI_NAME;
        }
        
        // Fallback to vendor directory
        return __DIR__ . '/../' . self::INI_NAME;
    }

    /**
     * Check if custom php.ini is needed
     *
     * @return bool True if custom php.ini file exists
     */
    public static function needsCustomIni(): bool
    {
        return !extension_loaded('swoole') && file_exists(self::getIniPath());
    }

    /**
     * Get command to run PHP with custom ini
     *
     * @param string $script Script to run
     * @return string Command string
     */
    public static function getCommand(string $script): string
    {
        $iniPath = self::getIniPath();
        if (file_exists($iniPath)) {
            return "php -c $iniPath $script";
        }
        
        return "php $script";
    }
}

