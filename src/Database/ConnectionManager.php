<?php

declare(strict_types=1);

namespace PHAPI\Database;

use PDO;
use PDOException;

/**
 * Database Connection Manager
 * Handles SQLite and Turso connections with Swoole-optimized pooling
 */
class ConnectionManager
{
    private static ?DatabaseConnectionInterface $connection = null;
    /**
     * @var array<int, DatabaseConnectionInterface>
     */
    private static array $workerConnections = [];
    private static ?string $dbPath = null;
    private static ?string $driver = null;
    private static ?string $tursoUrl = null;
    private static ?string $tursoToken = null;
    /**
     * @var array<string, mixed>
     */
    private static array $config = [];

    /**
     * Configure database connection
     *
     * @param string $dbPath Path to SQLite database file
     * @param array<string, mixed> $options Connection options
     * @return void
     */
    public static function configure(string $dbPath, array $options = []): void
    {
        self::configureSqlite($dbPath, $options);
    }

    /**
     * Configure SQLite driver.
     *
     * @param string $dbPath
     * @param array<string, mixed> $options
     * @return void
     */
    public static function configureSqlite(string $dbPath, array $options = []): void
    {
        self::$driver = 'sqlite';
        self::$dbPath = $dbPath;
        self::$tursoUrl = null;
        self::$tursoToken = null;
        self::$config = array_merge([
            'readonly' => false,
            'wal_mode' => true,
            'timeout' => 5000,
            'busy_timeout' => 30000,
        ], $options);
        self::closeAll();
    }

    /**
     * Configure Turso driver (Swoole only).
     *
     * @param string $url
     * @param string $token
     * @param array<string, mixed> $options
     * @return void
     */
    public static function configureTurso(string $url, string $token, array $options = []): void
    {
        self::$driver = 'turso';
        self::$dbPath = null;
        self::$tursoUrl = $url;
        self::$tursoToken = $token;
        self::$config = $options;
        self::closeAll();
    }

    /**
     * Get database connection (creates if needed)
     *
     * @return DatabaseConnectionInterface
     * @throws \RuntimeException
     */
    public static function getConnection(): DatabaseConnectionInterface
    {
        if (self::$driver === null) {
            throw new \RuntimeException('Database not configured. Call configureDatabase() first.');
        }

        if (self::$driver === 'turso') {
            if (self::$tursoUrl === null || self::$tursoToken === null) {
                throw new \RuntimeException('Turso is not configured.');
            }

            $workerId = extension_loaded('swoole') ? \Swoole\Coroutine::getCid() : 0;
            if (isset(self::$workerConnections[$workerId])) {
                return self::$workerConnections[$workerId];
            }

            $connection = new TursoConnection(self::$tursoUrl, self::$tursoToken);
            self::$workerConnections[$workerId] = $connection;
            return $connection;
        }

        // Use worker ID for connection pooling in Swoole
        // Fall back to static connection if Swoole not available
        if (!extension_loaded('swoole')) {
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
        return self::$driver !== null;
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
     * @return DatabaseConnectionInterface
     * @throws \RuntimeException
     */
    private static function createConnection(): DatabaseConnectionInterface
    {
        if (self::$dbPath === null || self::$dbPath === '') {
            throw new \RuntimeException('Database path is not configured.');
        }

        $dbPath = self::$dbPath;

        // Create directory if needed
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0755, true);
        }

        // Create database file if it doesn't exist
        if (!file_exists($dbPath)) {
            touch($dbPath);
            chmod($dbPath, 0644);
        }

        try {
            $dsn = 'sqlite:' . $dbPath;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => self::$config['timeout'] / 1000,
            ];

            $readonly = (bool)(self::$config['readonly'] ?? false);
            $walMode = (bool)(self::$config['wal_mode'] ?? false);
            $busyTimeout = (int)(self::$config['busy_timeout'] ?? 0);

            // Read-only mode
            if ($readonly) {
                $dsn .= '?mode=ro';
            }

            $connection = new PDO($dsn, null, null, $options);

            // Configure WAL mode for better concurrency
            if ($walMode && !$readonly) {
                $connection->exec('PRAGMA journal_mode=WAL;');
                $connection->exec('PRAGMA busy_timeout=' . $busyTimeout . ';');
                $connection->exec('PRAGMA synchronous=NORMAL;');
            }

            return new SqliteConnection($connection);
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
