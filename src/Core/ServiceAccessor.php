<?php

declare(strict_types=1);

namespace PHAPI\Core;

use PHAPI\Auth\GoogleIdTokenVerifier;
use PHAPI\Contracts\DatabaseInterface;
use PHAPI\Services\HttpClient;
use PHAPI\Services\MySqlPool;
use PHAPI\Services\OpenFgaClient;
use PHAPI\Services\OpenFgaHttpClient;
use PHAPI\Services\Realtime;
use PHAPI\Services\RedisClient;
use PHAPI\Services\TaskRunner;

/**
 * Centralized lazy-instantiation of service clients.
 *
 * This delegate is independently testable and holds the config-based
 * factory logic for MySQL, Redis, OpenFGA, and container-resolved services.
 */
class ServiceAccessor
{
    private Container $container;
    /**
     * @var array<string, mixed>
     */
    private array $config;

    private ?RedisClient $redisClient = null;
    private ?MySqlPool $mysqlPool = null;
    private ?OpenFgaClient $openFgaClient = null;
    /** @var array<string, object> */
    private array $singletons = [];

    /**
     * Create a new ServiceAccessor.
     *
     * @param Container $container
     * @param array<string, mixed> $config
     */
    public function __construct(Container $container, array $config)
    {
        $this->container = $container;
        $this->config = $config;
    }

    /**
     * Get the MySQL connection pool (lazy-initialized).
     *
     * @return MySqlPool
     */
    public function mysql(): MySqlPool
    {
        if ($this->mysqlPool === null) {
            $config = $this->config['mysql'] ?? [];
            $dsnParts = self::parseMySqlDsn(isset($config['dsn']) ? (string) $config['dsn'] : '');
            $this->mysqlPool = new MySqlPool([
                'host' => (string)($dsnParts['host'] ?? $config['host'] ?? '127.0.0.1'),
                'port' => (int)($dsnParts['port'] ?? $config['port'] ?? 3306),
                'user' => (string)($config['user'] ?? 'root'),
                'password' => (string)($config['password'] ?? ''),
                'database' => (string)($dsnParts['database'] ?? $config['database'] ?? ''),
                'charset' => (string)($dsnParts['charset'] ?? $config['charset'] ?? 'utf8mb4'),
                'timeout' => isset($config['timeout']) ? (float)$config['timeout'] : 1.0,
                'pool_size' => (int)($config['pool_size'] ?? 5),
                'pool_timeout' => (float)($config['pool_timeout'] ?? 1.0),
            ]);
        }

        return $this->mysqlPool;
    }

    /**
     * Get the Redis client (lazy-initialized).
     *
     * @return RedisClient
     */
    public function redis(): RedisClient
    {
        if ($this->redisClient === null) {
            $config = $this->config['redis'] ?? [];
            $this->redisClient = new RedisClient([
                'host' => (string)($config['host'] ?? '127.0.0.1'),
                'port' => (int)($config['port'] ?? 6379),
                'auth' => isset($config['auth']) && $config['auth'] !== '' ? (string)$config['auth'] : null,
                'db' => isset($config['db']) ? (int)$config['db'] : null,
                'timeout' => isset($config['timeout']) ? (float)$config['timeout'] : 1.0,
            ]);
        }

        return $this->redisClient;
    }

    /**
     * Get the OpenFGA authorization client (lazy-initialized).
     *
     * @return OpenFgaClient
     */
    public function openfga(): OpenFgaClient
    {
        if ($this->openFgaClient === null) {
            $config = $this->config['openfga'] ?? [];
            $this->openFgaClient = new OpenFgaHttpClient($config, $this->http());
        }

        return $this->openFgaClient;
    }

    /**
     * Get a Google ID token verifier for the given audience.
     */
    public function googleIdTokenVerifier(string $expectedAudience): GoogleIdTokenVerifier
    {
        $key = 'google_id_token_verifier_' . $expectedAudience;
        if (!isset($this->singletons[$key])) {
            $config = $this->config['google_oidc'] ?? [];
            $certsUrl = (string) ($config['certs_url'] ?? 'https://www.googleapis.com/oauth2/v3/certs');
            $this->singletons[$key] = new GoogleIdTokenVerifier($certsUrl, $this->http());
        }

        /** @var GoogleIdTokenVerifier */
        return $this->singletons[$key];
    }

    /**
     * Get the HTTP client service.
     *
     * @return HttpClient
     */
    public function http(): HttpClient
    {
        return $this->container->get(HttpClient::class);
    }

    /**
     * Get the ORM database service.
     *
     * @return DatabaseInterface
     */
    public function database(): DatabaseInterface
    {
        return $this->container->get(DatabaseInterface::class);
    }

    /**
     * Get the task runner service.
     *
     * @return TaskRunner
     */
    public function tasks(): TaskRunner
    {
        return $this->container->get(TaskRunner::class);
    }

    /**
     * Get the realtime service.
     *
     * @return Realtime
     */
    public function realtime(): Realtime
    {
        return $this->container->get(Realtime::class);
    }

    /**
     * Parse a MySQL DSN string into config parts.
     *
     * @param string $dsn
     * @return array{host?: string, port?: int, database?: string, charset?: string}
     */
    public static function parseMySqlDsn(string $dsn): array
    {
        $dsn = trim($dsn);
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            return [];
        }

        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $pair = explode('=', $segment, 2);
            if (count($pair) !== 2) {
                continue;
            }

            $key = strtolower(trim($pair[0]));
            $value = trim($pair[1]);
            if ($key === '') {
                continue;
            }

            $parts[$key] = $value;
        }

        $parsed = [];
        if (isset($parts['host']) && $parts['host'] !== '') {
            $parsed['host'] = $parts['host'];
        }
        if (isset($parts['port']) && is_numeric($parts['port'])) {
            $parsed['port'] = max(1, (int) $parts['port']);
        }
        if (isset($parts['dbname']) && $parts['dbname'] !== '') {
            $parsed['database'] = $parts['dbname'];
        }
        if (isset($parts['charset']) && $parts['charset'] !== '') {
            $parsed['charset'] = $parts['charset'];
        }

        return $parsed;
    }
}
