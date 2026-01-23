<?php

declare(strict_types=1);

// Support both Composer and a custom bootstrap file when copying this example.
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../bootstrap.php')) {
    require __DIR__ . '/../../bootstrap.php';
}

$config = [
    'runtime' => getenv('APP_RUNTIME') ?: 'swoole',
    'host' => '0.0.0.0',
    'port' => 9503,
    'debug' => true,
    'max_body_bytes' => 1024 * 1024,
    'access_logger' => function ($request, $response, array $meta) {
        $line = sprintf(
            '[%s] %s %s %d %sms %s',
            date('c'),
            $request->method(),
            $request->path(),
            $response->status(),
            $meta['duration_ms'],
            $meta['request_id']
        );
        error_log($line);
    },
    'auth' => [
        'default' => 'token',
        'token_resolver' => function (string $token) {
            if ($token === 'test-token') {
                return ['id' => 1, 'roles' => ['admin']];
            }
            return null;
        },
        'session_key' => 'user',
    ],
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        'auth' => getenv('REDIS_AUTH') ?: null,
        'db' => getenv('REDIS_DB') !== false ? (int)getenv('REDIS_DB') : null,
        'timeout' => getenv('REDIS_TIMEOUT') !== false ? (float)getenv('REDIS_TIMEOUT') : 1.0,
    ],
    'mysql' => [
        'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MYSQL_PORT') ?: 3306),
        'user' => getenv('MYSQL_USER') ?: 'root',
        'password' => getenv('MYSQL_PASSWORD') ?: '',
        'database' => getenv('MYSQL_DATABASE') ?: '',
        'charset' => getenv('MYSQL_CHARSET') ?: 'utf8mb4',
        'timeout' => getenv('MYSQL_TIMEOUT') !== false ? (float)getenv('MYSQL_TIMEOUT') : 1.0,
    ],
];

$api = new PHAPI($config);
$api->enableCORS();
$api->enableSecurityHeaders();

require __DIR__ . '/app.php';
$api->run();
