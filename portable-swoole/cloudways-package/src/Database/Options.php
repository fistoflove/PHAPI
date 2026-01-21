<?php

namespace PHAPI\Database;

use PHAPI\Database\ConnectionManager;
use PHAPI\Database\Schema;
use PDOException;

/**
 * Options Facade - WordPress-style key-value storage
 * 
 * Provides simple interface for storing and retrieving arbitrary data
 */
class Options
{
    private static ?array $cache = null;
    private static bool $cacheLoaded = false;

    /**
     * Ensure database is initialized
     *
     * @return void
     */
    private static function ensureInitialized(): void
    {
        if (!ConnectionManager::isConfigured()) {
            throw new \RuntimeException('Database not configured. Call configureDatabase() first.');
        }

        if (!Schema::isInitialized()) {
            Schema::initialize();
        }
    }

    /**
     * Load autoload options into cache
     *
     * @return void
     */
    public static function loadAutoload(): void
    {
        if (self::$cacheLoaded) {
            return;
        }

        self::ensureInitialized();

        try {
            $db = ConnectionManager::getConnection();
            $stmt = $db->query("SELECT key, value FROM options WHERE autoload = 1 AND (expires_at IS NULL OR expires_at > datetime('now'))");
            $options = $stmt->fetchAll();

            self::$cache = [];
            foreach ($options as $option) {
                self::$cache[$option['key']] = json_decode($option['value'], true);
            }

            self::$cacheLoaded = true;
        } catch (PDOException $e) {
            // If table doesn't exist yet, start with empty cache
            self::$cache = [];
            self::$cacheLoaded = true;
        }
    }

    /**
     * Get option value
     *
     * @param string $key Option key
     * @param mixed $default Default value if option doesn't exist
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::ensureInitialized();

        // Check cache first (for autoload options)
        if (self::$cacheLoaded && isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $db = ConnectionManager::getConnection();
            $stmt = $db->prepare("SELECT value, expires_at FROM options WHERE key = ?");
            $stmt->execute([$key]);
            $option = $stmt->fetch();

            if (!$option) {
                return $default;
            }

            // Check expiration
            if ($option['expires_at'] && $option['expires_at'] < date('Y-m-d H:i:s')) {
                self::delete($key);
                return $default;
            }

            $value = json_decode($option['value'], true);
            
            // Cache autoload options
            if (self::$cacheLoaded) {
                $autoloadStmt = $db->prepare("SELECT autoload FROM options WHERE key = ? AND autoload = 1");
                $autoloadStmt->execute([$key]);
                if ($autoloadStmt->fetch()) {
                    self::$cache[$key] = $value;
                }
            }

            return $value ?? $default;
        } catch (PDOException $e) {
            return $default;
        }
    }

    /**
     * Set option value
     *
     * @param string $key Option key
     * @param mixed $value Option value (any type)
     * @param array $options Additional options (autoload, expires)
     * @return bool
     */
    public static function set(string $key, $value, array $options = []): bool
    {
        self::ensureInitialized();

        $autoload = $options['autoload'] ?? false;
        $expires = $options['expires'] ?? null;

        $expiresAt = null;
        if ($expires !== null) {
            if (is_int($expires)) {
                $expiresAt = date('Y-m-d H:i:s', time() + $expires);
            } elseif (is_string($expires)) {
                $expiresAt = $expires;
            }
        }

        try {
            $db = ConnectionManager::getConnection();
            $jsonValue = json_encode($value);
            
            $stmt = $db->prepare("
                INSERT INTO options (key, value, autoload, expires_at, updated_at)
                VALUES (?, ?, ?, ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET
                    value = excluded.value,
                    autoload = excluded.autoload,
                    expires_at = excluded.expires_at,
                    updated_at = datetime('now')
            ");

            $result = $stmt->execute([
                $key,
                $jsonValue,
                $autoload ? 1 : 0,
                $expiresAt
            ]);

            // Update cache if autoload
            if (self::$cacheLoaded) {
                if ($autoload) {
                    self::$cache[$key] = $value;
                } else {
                    unset(self::$cache[$key]);
                }
            }

            return $result;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Check if option exists
     *
     * @param string $key Option key
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::ensureInitialized();

        // Check cache first
        if (self::$cacheLoaded && isset(self::$cache[$key])) {
            return true;
        }

        try {
            $db = ConnectionManager::getConnection();
            $stmt = $db->prepare("SELECT 1 FROM options WHERE key = ? AND (expires_at IS NULL OR expires_at > datetime('now'))");
            $stmt->execute([$key]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Delete option
     *
     * @param string $key Option key
     * @return bool
     */
    public static function delete(string $key): bool
    {
        self::ensureInitialized();

        try {
            $db = ConnectionManager::getConnection();
            $stmt = $db->prepare("DELETE FROM options WHERE key = ?");
            $result = $stmt->execute([$key]);

            // Remove from cache
            if (self::$cacheLoaded && isset(self::$cache[$key])) {
                unset(self::$cache[$key]);
            }

            return $result;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Delete all options with given prefix
     *
     * @param string $prefix Key prefix
     * @return int Number of deleted options
     */
    public static function deleteGroup(string $prefix): int
    {
        self::ensureInitialized();

        try {
            $db = ConnectionManager::getConnection();
            $stmt = $db->prepare("DELETE FROM options WHERE key LIKE ?");
            $stmt->execute([$prefix . '%']);
            
            $count = $stmt->rowCount();

            // Clear cache entries with prefix
            if (self::$cacheLoaded) {
                foreach (array_keys(self::$cache) as $key) {
                    if (strpos($key, $prefix) === 0) {
                        unset(self::$cache[$key]);
                    }
                }
            }

            return $count;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Get all options with given prefix
     *
     * @param string $prefix Key prefix
     * @return array Associative array of key => value
     */
    public static function getGroup(string $prefix): array
    {
        self::ensureInitialized();

        try {
            $db = ConnectionManager::getConnection();
            $stmt = $db->prepare("SELECT key, value FROM options WHERE key LIKE ? AND (expires_at IS NULL OR expires_at > datetime('now'))");
            $stmt->execute([$prefix . '%']);
            $options = $stmt->fetchAll();

            $result = [];
            foreach ($options as $option) {
                $result[$option['key']] = json_decode($option['value'], true);
            }

            return $result;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get multiple options at once
     *
     * @param array $keys Array of keys
     * @return array Associative array of key => value
     */
    public static function getMany(array $keys): array
    {
        self::ensureInitialized();

        if (empty($keys)) {
            return [];
        }

        // Check cache first
        $result = [];
        $missingKeys = [];

        if (self::$cacheLoaded) {
            foreach ($keys as $key) {
                if (isset(self::$cache[$key])) {
                    $result[$key] = self::$cache[$key];
                } else {
                    $missingKeys[] = $key;
                }
            }

            if (empty($missingKeys)) {
                return $result;
            }
        } else {
            $missingKeys = $keys;
        }

        try {
            $db = ConnectionManager::getConnection();
            $placeholders = implode(',', array_fill(0, count($missingKeys), '?'));
            $stmt = $db->prepare("SELECT key, value FROM options WHERE key IN ($placeholders) AND (expires_at IS NULL OR expires_at > datetime('now'))");
            $stmt->execute($missingKeys);
            $options = $stmt->fetchAll();

            foreach ($options as $option) {
                $result[$option['key']] = json_decode($option['value'], true);
            }

            return $result;
        } catch (PDOException $e) {
            return $result;
        }
    }

    /**
     * Clear expired options
     *
     * @return int Number of deleted options
     */
    public static function clearExpired(): int
    {
        self::ensureInitialized();

        try {
            $db = ConnectionManager::getConnection();
            $stmt = $db->exec("DELETE FROM options WHERE expires_at IS NOT NULL AND expires_at < datetime('now')");
            
            // Clear cache if expired options were deleted
            if (self::$cacheLoaded && $stmt > 0) {
                // Reload cache to remove expired entries
                self::$cacheLoaded = false;
                self::loadAutoload();
            }

            return $stmt;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Clear all options cache
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = null;
        self::$cacheLoaded = false;
    }

    /**
     * Set transient (temporary option with expiration)
     * 
     * Convenience method for setting options that always expire.
     * Transients are always temporary and never autoload.
     *
     * @param string $key Transient key
     * @param mixed $value Transient value
     * @param int $expires Seconds until expiration (default: 3600 = 1 hour)
     * @return bool Success
     */
    public static function transient(string $key, $value, int $expires = 3600): bool
    {
        return self::set($key, $value, [
            'expires' => $expires,
            'autoload' => false
        ]);
    }

    /**
     * Get transient value
     * 
     * Convenience method for getting transient options.
     * Returns default if transient doesn't exist or has expired.
     *
     * @param string $key Transient key
     * @param mixed $default Default value if transient doesn't exist or expired
     * @return mixed
     */
    public static function getTransient(string $key, $default = null)
    {
        return self::get($key, $default);
    }

    /**
     * Delete transient
     * 
     * Convenience method for deleting transient options.
     *
     * @param string $key Transient key
     * @return bool Success
     */
    public static function deleteTransient(string $key): bool
    {
        return self::delete($key);
    }

    /**
     * Clear expired transients (same as clearExpired, but semantically clearer)
     *
     * @return int Number of deleted transients
     */
    public static function clearExpiredTransients(): int
    {
        return self::clearExpired();
    }
}
