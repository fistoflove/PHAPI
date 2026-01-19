<?php

namespace PHAPI\Database;

use PDO;
use PDOException;

/**
 * Database Connection Manager
 * Handles SQLite connections with Swoole-optimized pooling
 */
class ConnectionManager
{
    private static ?PDO $connection = null;
    private static array $workerConnections = [];
    private static ?string $dbPath = null;
    private static array $config = [];

    /**
     * Configure database connection
     *
     * @param string $dbPath Path to SQLite database file
     * @param array $options Connection options
     * @return void
     */
    public static function configure(string $dbPath, array $options = []): void
    {
        self::$dbPath = $dbPath;
        self::$config = array_merge([
            'readonly' => false,
            'wal_mode' => true,
            'timeout' => 5000,
            'busy_timeout' => 30000,
        ], $options);
    }

    /**
     * Get database connection (creates if needed)
     *
     * @return PDO
     * @throws \RuntimeException
     */
    public static function getConnection(): PDO
    {
        if (self::$dbPath === null) {
            throw new \RuntimeException('Database not configured. Call configureDatabase() first.');
        }

        // Use worker ID for connection pooling in Swoole
        // Fall back to static connection if Swoole not available
        if (!function_exists('Swoole\Coroutine::getCid')) {
            if (self::$connection === null) {
                self::$connection = self::createConnection();
            }
            return self::$connection;
        }

        $workerId = \Swoole\Coroutine::getCid();

        // Return existing worker connection if available
        if (isset(self::$workerConnections[$workerId])) {
            return self::$workerConnections[$workerId];
        }

        // Create new connection for this worker
        $connection = self::createConnection();
        self::$workerConnections[$workerId] = $connection;

        return $connection;
    }

    /**
     * Check if database is configured
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::$dbPath !== null;
    }

    /**
     * Get database path
     *
     * @return string|null
     */
    public static function getDbPath(): ?string
    {
        return self::$dbPath;
    }

    /**
     * Create a new database connection
     *
     * @return PDO
     * @throws \RuntimeException
     */
    private static function createConnection(): PDO
    {
        // Create directory if needed
        $dbDir = dirname(self::$dbPath);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0755, true);
        }

        // Create database file if it doesn't exist
        if (!file_exists(self::$dbPath)) {
            touch(self::$dbPath);
            chmod(self::$dbPath, 0644);
        }

        try {
            $dsn = 'sqlite:' . self::$dbPath;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => self::$config['timeout'] / 1000,
            ];

            // Read-only mode
            if (self::$config['readonly']) {
                $dsn .= '?mode=ro';
            }

            $connection = new PDO($dsn, null, null, $options);

            // Configure WAL mode for better concurrency
            if (self::$config['wal_mode'] && !self::$config['readonly']) {
                $connection->exec('PRAGMA journal_mode=WAL;');
                $connection->exec('PRAGMA busy_timeout=' . self::$config['busy_timeout'] . ';');
                $connection->exec('PRAGMA synchronous=NORMAL;');
            }

            return $connection;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to connect to database: ' . $e->getMessage());
        }
    }

    /**
     * Close all connections
     *
     * @return void
     */
    public static function closeAll(): void
    {
        self::$workerConnections = [];
        self::$connection = null;
    }

    /**
     * Close connection for specific worker
     *
     * @param int $workerId
     * @return void
     */
    public static function closeWorker(int $workerId): void
    {
        unset(self::$workerConnections[$workerId]);
    }
}
