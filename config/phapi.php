<?php

declare(strict_types=1);

return [
    'runtime' => 'swoole',
    'debug' => false,
    'host' => '0.0.0.0',
    'port' => 9501,
    'enable_websockets' => false,
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
    ],
];
