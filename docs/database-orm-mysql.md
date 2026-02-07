# MySQL ORM (Hyperc DB stack)

PHAPI ships an optional MySQL ORM integration based on Hyperf's database stack. It provides
query builder access via `$api->database()` or `PHAPI::db()` and an Eloquent-style base
model via `PHAPI\Database\PhapiModel`.

## Install

```bash
composer require hyperf/db-connection hyperf/database hyperf/config
```

Requirements:

- PHP `ext-pdo_mysql`
- Swoole runtime (native or portable)
- Coroutine hooks enabled (`enable_coroutine_hooks` = true)

## Configuration

Enable the provider and supply `orm.mysql` config:

```php
use PHAPI\PHAPI;
use PHAPI\Providers\OrmMysqlProvider;

$api = new PHAPI([
    'providers' => [OrmMysqlProvider::class],
    'orm' => [
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'app',
            'username' => 'root',
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
]);
```

### Compatibility aliasing

If `orm.mysql` is not set, PHAPI derives it from the existing `mysql` config block:

- `mysql.host` → `orm.mysql.host`
- `mysql.port` → `orm.mysql.port`
- `mysql.user` → `orm.mysql.username`
- `mysql.password` → `orm.mysql.password`
- `mysql.database` → `orm.mysql.database`
- `mysql.charset` → `orm.mysql.charset`
- `mysql.timeout` → `orm.mysql.pool.connect_timeout`
- `mysql.pool_size` → `orm.mysql.pool.min_connections` + `max_connections`
- `mysql.pool_timeout` → `orm.mysql.pool.wait_timeout`

## Usage

### Query builder

```php
$users = $api->database()->table('users')
    ->where('active', 1)
    ->orderByDesc('id')
    ->get();
```

### Models

```php
use PHAPI\Database\PhapiModel;

final class User extends PhapiModel
{
    protected ?string $table = 'users';
}

$active = User::query()->where('active', 1)->get();
```

### Transactions

```php
$api->database()->transaction(function () use ($api) {
    $api->database()->statement('UPDATE wallets SET balance = balance - 1 WHERE id = ?', [1]);
    $api->database()->statement('UPDATE wallets SET balance = balance + 1 WHERE id = ?', [2]);
});
```

## Parallelism

Use the task runner or jobs to execute database work in parallel coroutines:

```php
$results = $api->tasks()->parallel([
    fn () => $api->database()->table('users')->count(),
    fn () => $api->database()->table('orders')->count(),
]);
```

## Pitfalls

- Pool sizing matters for long-running workloads. Ensure `max_connections` matches expected
  concurrency to avoid pool wait timeouts.
- Coroutine hooks must be enabled for non-blocking PDO I/O. PHAPI logs a warning when
  `enable_coroutine_hooks` is `false`.
- Query logging depends on the Hyperf connection `listen()` hook. If unavailable, PHAPI logs
  a warning and skips logging.
- Long-running workers should avoid holding onto model instances across requests.

## Troubleshooting

| Issue | Guidance |
| --- | --- |
| Pool wait timeout | Increase `orm.mysql.pool.wait_timeout` or `max_connections`. |
| Hooks disabled warning | Set `enable_coroutine_hooks` to `true` in config. |
| Connection errors | Verify credentials, host/port, and that `ext-pdo_mysql` is enabled. |
