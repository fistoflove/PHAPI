<?php

declare(strict_types=1);

return [
    'runtime' => 'fpm',
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
    'database' => null,
];
