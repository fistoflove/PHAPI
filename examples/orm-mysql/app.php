<?php

require __DIR__ . '/../vendor/autoload.php';

use PHAPI\Database\PhapiModel;
use PHAPI\HTTP\Response;
use PHAPI\PHAPI;
use PHAPI\Providers\OrmMysqlProvider;

final class User extends PhapiModel
{
    protected ?string $table = 'users';
}

$api = new PHAPI([
    'runtime' => getenv('APP_RUNTIME') ?: 'swoole',
    'host' => '0.0.0.0',
    'port' => 9504,
    'debug' => true,
    'providers' => [OrmMysqlProvider::class],
    'orm' => [
        'mysql' => [
            'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('MYSQL_PORT') ?: 3306),
            'database' => getenv('MYSQL_DATABASE') ?: 'app',
            'username' => getenv('MYSQL_USER') ?: 'root',
            'password' => getenv('MYSQL_PASSWORD') ?: '',
        ],
    ],
]);

$api->get('/users', function () use ($api): Response {
    $users = $api->database()->table('users')->limit(10)->get();
    return Response::json(['users' => $users]);
});

$api->get('/users/model', function (): Response {
    $users = User::query()->limit(10)->get();
    return Response::json(['users' => $users]);
});

$api->get('/tx', function () use ($api): Response {
    $api->database()->transaction(function () use ($api): void {
        $api->database()->statement('UPDATE widgets SET counter = counter + 1 WHERE id = ?', [1]);
    });

    return Response::json(['ok' => true]);
});

$api->run();
