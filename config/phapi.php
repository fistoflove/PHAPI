<?php

declare(strict_types=1);

return [
    'runtime' => 'swoole',
    'debug' => false,
    'host' => '0.0.0.0',
    'port' => 9501,
    'enable_websockets' => false,
    // Passed directly to Swoole\Server::set().
    'swoole_settings' => [
        // 'worker_num' => 2,
        // 'task_worker_num' => 4,
    ],
    'enable_coroutine_hooks' => true,
    'default_endpoints' => [
        'monitor' => true,
    ],
    'providers' => [],
    'jobs_log_dir' => getcwd() . '/var/jobs',
    'jobs_log_limit' => 200,
    'jobs_log_rotate_bytes' => 1048576,
    'jobs_log_rotate_keep' => 5,
    'task_timeout' => null,
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => null,
        'db' => null,
        'timeout' => 1.0,
    ],
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'root',
        'password' => '',
        'database' => '',
        'charset' => 'utf8mb4',
        'timeout' => 1.0,
        'pool_size' => 5,
        'pool_timeout' => 1.0,
    ],
    'orm' => [
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
            'pool' => [
                'min_connections' => 2,
                'max_connections' => 50,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'max_idle_time' => 60.0,
            ],
            'log_queries' => false,
        ],
    ],
];
