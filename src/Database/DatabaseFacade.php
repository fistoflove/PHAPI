<?php

declare(strict_types=1);

namespace PHAPI\Database;

/**
 * Database Facade
 *
 * Provides a simple facade for database operations (options, transients)
 */
class DatabaseFacade
{
    /**
     * Configure database connection
     *
     * Sets up SQLite database with automatic schema initialization.
     * Database is optional - features work without it.
     *
     * @param string $dbPath Path to SQLite database file
     * @param array<string, mixed> $options Connection options:
     *   - readonly: bool (default: false)
     *   - wal_mode: bool (default: true)
     *   - timeout: int Milliseconds (default: 5000)
     *   - busy_timeout: int Milliseconds (default: 30000)
     *   - autoload: bool Enable autoload middleware (default: true)
     * @return void
     */
    public static function configure(string $dbPath, array $options = []): void
    {
        $defaultOptions = [
            'readonly' => false,
            'wal_mode' => true,
            'timeout' => 5000,
            'busy_timeout' => 30000,
            'autoload' => true,
        ];

        $config = array_merge($defaultOptions, $options);

        ConnectionManager::configureSqlite($dbPath, $config);
        Schema::initialize();
    }

    /**
     * Configure Turso connection (Swoole only).
     *
     * @param string $url
     * @param string $token
     * @param array<string, mixed> $options
     * @return void
     */
    public static function configureTurso(string $url, string $token, array $options = []): void
    {
        ConnectionManager::configureTurso($url, $token, $options);
        Schema::initialize();
    }

    /**
     * Get database connection
     *
     * Returns PDO instance if database is configured, null otherwise.
     *
     * @return DatabaseConnectionInterface|null
     */
    public static function getConnection(): ?DatabaseConnectionInterface
    {
        if (!ConnectionManager::isConfigured()) {
            return null;
        }

        return ConnectionManager::getConnection();
    }

    /**
     * Check if database is configured
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return ConnectionManager::isConfigured();
    }

    /**
     * Get option value (WordPress-style)
     *
     * Simple key-value storage for arbitrary data.
     * Returns default value if option doesn't exist.
     *
     * @param string $key Option key
     * @param mixed $default Default value if option doesn't exist
     * @return mixed
     */
    public static function option(string $key, $default = null)
    {
        if (!ConnectionManager::isConfigured()) {
            return $default;
        }

        return Options::get($key, $default);
    }

    /**
     * Set option value (WordPress-style)
     *
     * Stores arbitrary data with a key.
     * Supports autoload and expiration options.
     *
     * @param string $key Option key
     * @param mixed $value Option value (any type)
     * @param array<string, mixed> $options Additional options:
     *   - autoload: bool Load into memory cache (default: false)
     *   - expires: int|string Seconds until expiration or date string
     * @return bool Success
     */
    public static function setOption(string $key, $value, array $options = []): bool
    {
        if (!ConnectionManager::isConfigured()) {
            return false;
        }

        return Options::set($key, $value, $options);
    }

    /**
     * Check if option exists
     *
     * @param string $key Option key
     * @return bool
     */
    public static function hasOption(string $key): bool
    {
        if (!ConnectionManager::isConfigured()) {
            return false;
        }

        return Options::has($key);
    }

    /**
     * Delete option
     *
     * @param string $key Option key
     * @return bool Success
     */
    public static function deleteOption(string $key): bool
    {
        if (!ConnectionManager::isConfigured()) {
            return false;
        }

        return Options::delete($key);
    }

    /**
     * Delete all options with given prefix
     *
     * @param string $prefix Key prefix
     * @return int Number of deleted options
     */
    public static function deleteOptionGroup(string $prefix): int
    {
        if (!ConnectionManager::isConfigured()) {
            return 0;
        }

        return Options::deleteGroup($prefix);
    }

    /**
     * Get all options with given prefix
     *
     * @param string $prefix Key prefix
     * @return array<string, mixed> Associative array of key => value
     */
    public static function getOptionGroup(string $prefix): array
    {
        if (!ConnectionManager::isConfigured()) {
            return [];
        }

        return Options::getGroup($prefix);
    }

    /**
     * Set transient (temporary option with expiration)
     *
     * Transients are temporary key-value pairs that automatically expire.
     *
     * @param string $key Transient key
     * @param mixed $value Transient value
     * @param int $expires Seconds until expiration (default: 3600 = 1 hour)
     * @return bool Success
     */
    public static function transient(string $key, $value, int $expires = 3600): bool
    {
        if (!ConnectionManager::isConfigured()) {
            return false;
        }

        return Options::transient($key, $value, $expires);
    }

    /**
     * Get transient value
     *
     * Returns the transient value if it exists and hasn't expired.
     * Returns default value if transient doesn't exist or has expired.
     *
     * @param string $key Transient key
     * @param mixed $default Default value if transient doesn't exist or expired
     * @return mixed
     */
    public static function getTransient(string $key, $default = null)
    {
        if (!ConnectionManager::isConfigured()) {
            return $default;
        }

        return Options::getTransient($key, $default);
    }

    /**
     * Delete transient
     *
     * @param string $key Transient key
     * @return bool Success
     */
    public static function deleteTransient(string $key): bool
    {
        if (!ConnectionManager::isConfigured()) {
            return false;
        }

        return Options::deleteTransient($key);
    }
}
