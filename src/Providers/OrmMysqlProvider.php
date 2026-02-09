<?php

declare(strict_types=1);

namespace PHAPI\Providers;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\ConnectionFactory;
use Hyperf\DbConnection\ConnectionResolver;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\Database\Model\Model;
use PHAPI\Contracts\DatabaseInterface;
use PHAPI\Core\Container;
use PHAPI\Core\ServiceProviderInterface;
use PHAPI\Exceptions\ConfigException;
use PHAPI\PHAPI;
use PHAPI\Services\Database;

final class OrmMysqlProvider implements ServiceProviderInterface
{
    public function register(Container $container, PHAPI $app): void
    {
        $config = $app->config();
        $ormConfig = $this->resolveOrmMysqlConfig($config);
        $this->validateOrmMysqlConfig($ormConfig);

        $hyperfConfig = $this->buildHyperfConfig($ormConfig);

        $container->singleton(ConfigInterface::class, static function () use ($hyperfConfig) {
            return new Config($hyperfConfig);
        });

        $container->singleton(PoolFactory::class, static function (Container $container) {
            return new PoolFactory($container);
        });

        $container->singleton(ConnectionFactory::class, static function (Container $container) {
            return new ConnectionFactory($container);
        });

        $container->singleton(ConnectionResolver::class, static function (Container $container) {
            return new ConnectionResolver($container);
        });

        $container->singleton(DatabaseInterface::class, static function (Container $container) use ($config) {
            $resolver = $container->get(ConnectionResolver::class);
            return new Database($resolver, (bool)($config['debug'] ?? false));
        });

        if (($config['enable_coroutine_hooks'] ?? true) === false) {
            error_log('PHAPI ORM MySQL requires coroutine hooks for async I/O; enable_coroutine_hooks is false.');
        }
    }

    public function boot(PHAPI $app): void
    {
        $resolver = $app->container()->get(ConnectionResolver::class);
        try {
            Model::setConnectionResolver($resolver);
        } catch (\Throwable $e) {
            error_log('PHAPI ORM MySQL: failed to set model connection resolver: ' . $e->getMessage());
        }
        $config = $app->config();
        $ormConfig = $this->resolveOrmMysqlConfig($config);
        if (($ormConfig['log_queries'] ?? false) === true) {
            $this->attachQueryLogger($resolver);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveOrmMysqlConfig(array $config): array
    {
        $defaults = $this->defaultOrmMysqlConfig();
        /** @var array<string, mixed>|null $orm */
        $orm = $config['orm']['mysql'] ?? null;

        if ($orm === null) {
            /** @var array<string, mixed> $mysql */
            $mysql = $config['mysql'] ?? [];
            $poolSize = isset($mysql['pool_size']) ? (int)$mysql['pool_size'] : (int)$defaults['pool']['max_connections'];
            $poolTimeout = $mysql['pool_timeout'] ?? $defaults['pool']['wait_timeout'];
            $connectTimeout = $mysql['timeout'] ?? $defaults['pool']['connect_timeout'];

            return [
                'host' => $mysql['host'] ?? $defaults['host'],
                'port' => $mysql['port'] ?? $defaults['port'],
                'database' => $mysql['database'] ?? $defaults['database'],
                'username' => $mysql['user'] ?? $defaults['username'],
                'password' => $mysql['password'] ?? $defaults['password'],
                'charset' => $mysql['charset'] ?? $defaults['charset'],
                'collation' => $defaults['collation'],
                'prefix' => $defaults['prefix'],
                'options' => $defaults['options'],
                'pool' => [
                    'min_connections' => max(1, $poolSize),
                    'max_connections' => max(1, $poolSize),
                    'connect_timeout' => (float)$connectTimeout,
                    'wait_timeout' => (float)$poolTimeout,
                    'max_idle_time' => (float)$defaults['pool']['max_idle_time'],
                ],
                'log_queries' => $defaults['log_queries'],
            ];
        }

        return array_replace_recursive($defaults, $orm);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOrmMysqlConfig(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
            'pool' => [
                'min_connections' => 2,
                'max_connections' => 50,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'max_idle_time' => 60.0,
            ],
            'log_queries' => false,
        ];
    }

    /**
     * @param array<string, mixed> $ormConfig
     * @return void
     */
    private function validateOrmMysqlConfig(array $ormConfig): void
    {
        $database = trim((string)($ormConfig['database'] ?? ''));
        if ($database === '') {
            throw new ConfigException('PHAPI ORM MySQL config requires a database name.');
        }

        $username = trim((string)($ormConfig['username'] ?? ''));
        if ($username === '') {
            throw new ConfigException('PHAPI ORM MySQL config requires a username.');
        }

        $host = trim((string)($ormConfig['host'] ?? ''));
        if ($host === '') {
            throw new ConfigException('PHAPI ORM MySQL config requires a host.');
        }

        $pool = $ormConfig['pool'] ?? [];
        $min = $pool['min_connections'] ?? null;
        $max = $pool['max_connections'] ?? null;
        if (!is_numeric($min) || !is_numeric($max)) {
            throw new ConfigException('PHAPI ORM MySQL pool config requires numeric min_connections and max_connections.');
        }

        $minValue = (int)$min;
        $maxValue = (int)$max;
        if ($minValue < 1 || $maxValue < 1 || $maxValue < $minValue) {
            throw new ConfigException('PHAPI ORM MySQL pool config must satisfy max_connections >= min_connections >= 1.');
        }

        foreach (['connect_timeout', 'wait_timeout', 'max_idle_time'] as $key) {
            $value = $pool[$key] ?? null;
            if ($value === null || !is_numeric($value)) {
                throw new ConfigException("PHAPI ORM MySQL pool config requires numeric {$key}.");
            }
        }

        $options = $ormConfig['options'] ?? null;
        if ($options !== null && !is_array($options)) {
            throw new ConfigException('PHAPI ORM MySQL options must be an array.');
        }
    }

    /**
     * @param array<string, mixed> $ormConfig
     * @return array<string, mixed>
     */
    private function buildHyperfConfig(array $ormConfig): array
    {
        return [
            'db' => [
                'default' => 'default',
                'connections' => [
                    'default' => [
                        'driver' => 'mysql',
                        'host' => $ormConfig['host'],
                        'port' => $ormConfig['port'],
                        'database' => $ormConfig['database'],
                        'username' => $ormConfig['username'],
                        'password' => $ormConfig['password'],
                        'charset' => $ormConfig['charset'],
                        'collation' => $ormConfig['collation'],
                        'prefix' => $ormConfig['prefix'],
                        'options' => $ormConfig['options'],
                    ],
                ],
            ],
            'db.pool' => [
                'default' => [
                    'min_connections' => $ormConfig['pool']['min_connections'],
                    'max_connections' => $ormConfig['pool']['max_connections'],
                    'connect_timeout' => $ormConfig['pool']['connect_timeout'],
                    'wait_timeout' => $ormConfig['pool']['wait_timeout'],
                    'max_idle_time' => $ormConfig['pool']['max_idle_time'],
                ],
            ],
        ];
    }

    private function attachQueryLogger(ConnectionResolver $resolver): void
    {
        $connection = $resolver->connection();
        if (!method_exists($connection, 'listen')) {
            error_log('PHAPI ORM MySQL: query logging requested, but connection does not support listen().');
            return;
        }

        $connection->listen(static function ($query): void {
            $sql = '';
            $bindings = [];
            $time = null;

            if (is_object($query)) {
                if (property_exists($query, 'sql')) {
                    $sql = (string)$query->sql;
                }
                if (property_exists($query, 'bindings')) {
                    $bindings = $query->bindings;
                }
                if (property_exists($query, 'time')) {
                    $time = $query->time;
                }
            } elseif (is_array($query)) {
                $sql = (string)($query['sql'] ?? '');
                $bindings = $query['bindings'] ?? [];
                $time = $query['time'] ?? null;
            }

            $timeValue = $time === null ? 'n/a' : $time . 'ms';
            $bindingInfo = $bindings === [] ? '' : ' bindings=' . json_encode($bindings);
            error_log(sprintf('PHAPI ORM query (%s): %s%s', $timeValue, $sql, $bindingInfo));
        });
    }
}
