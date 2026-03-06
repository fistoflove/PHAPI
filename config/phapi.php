<?php

declare(strict_types=1);

return [
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
    'http_timeout' => 5.0,
    'task_timeout' => null,
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => null,
        'db' => null,
        'timeout' => 1.0,
    ],
    // Single MySQL config used by both $api->services()->mysql() and the ORM
    // provider. The ORM reads from 'orm.mysql' first and falls back to this
    // 'mysql' key automatically (see OrmMysqlProvider).
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
    'openfga' => [
        'api_url' => 'http://localhost:8080',
        'store_id' => '',
        'model_id' => '',
        'api_token' => '',
    ],
    'supabase' => [
        'url' => '',          // e.g. 'https://yourproject.supabase.co'
        'anon_key' => '',
        'service_role_key' => '',
        'schema' => 'public',
        'timeout' => 5.0,
        'retries' => 0,
        // Declarative bucket provisioning — auto-created on worker start (worker 0).
        // Each bucket is ensured in parallel via Swoole coroutines.
        // 'buckets' => [
        //     'avatars' => ['public' => true],
        //     'documents' => ['public' => false, 'file_size_limit' => 10485760],
        // ],
        'buckets' => [],
    ],
    'google_oidc' => [
        'certs_url' => 'https://www.googleapis.com/oauth2/v3/certs',
        'cache_ttl' => 300,
    ],
    // Override 'orm.mysql' only if you need ORM-specific pool tuning or
    // collation/prefix settings beyond the shared 'mysql' config above.
    // When omitted or empty, OrmMysqlProvider falls back to 'mysql'.
    'orm' => [
        'mysql' => [],
    ],
];
