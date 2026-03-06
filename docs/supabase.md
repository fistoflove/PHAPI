# Supabase Integration

PHAPI ships a native, Swoole-optimized Supabase client covering Auth, Database (PostgREST), Storage, Edge Functions, and Realtime (Postgres Changes, Broadcast, Presence). The API mirrors [supabase-js](https://supabase.com/docs/reference/javascript) so applications can migrate with minimal changes.

## Install

No extra packages required — the Supabase module is included in PHAPI core.

## Configuration

Register `SupabaseProvider` and supply your project credentials:

```php
use PHAPI\Supabase\SupabaseProvider;

$api = PHAPI::builder()
    ->providers([SupabaseProvider::class])
    ->config('supabase', [
        'url'              => getenv('SUPABASE_URL'),
        'anon_key'         => getenv('SUPABASE_ANON_KEY'),
        'service_role_key' => getenv('SUPABASE_SERVICE_ROLE_KEY'),
        'schema'           => 'public',   // optional, default: 'public'
        'timeout'          => 5.0,        // optional, request timeout in seconds
        'buckets'          => [           // optional, declarative bucket provisioning
            'avatars'   => ['public' => true],
            'documents' => ['public' => false, 'file_size_limit' => 10485760],
        ],
    ])
    ->build();
```

The provider:
- Registers `SupabaseConfig`, `SupabaseTransport`, and `SupabaseFactory` as per-worker singletons
- Registers `SupabaseContext` as request-scoped (disposed after each request)
- Registers `supabase.auth` and `supabase.role` named middleware
- Provisions declared buckets in parallel on worker 0 startup (Swoole coroutines)

## Contexts

All Supabase operations happen through a `SupabaseContext`, which holds the user's access token and provides lazy-initialized clients.

```php
// In a route handler (request-scoped, uses the authenticated user's token):
$context = $api->container()->get(\PHAPI\Supabase\SupabaseContext::class);

// Create a context manually (e.g., for server-side operations):
$factory = $api->container()->get(\PHAPI\Supabase\SupabaseFactory::class);
$context = $factory->createContext($accessToken);

// Service-role context (admin privileges, bypasses RLS):
$serviceContext = $factory->createServiceContext();
```

A context exposes four clients:

```php
$context->auth();       // AuthClient
$context->db();         // DatabaseClient
$context->storage();    // StorageClient
$context->functions();  // EdgeFunctionsClient
```

## Auth

### Sign Up

```php
$session = $context->auth()->signUp('user@example.com', 'password', [
    'display_name' => 'Jane Doe',
]);
```

### Sign In

```php
// Email + password
$session = $context->auth()->signInWithPassword('user@example.com', 'password');

// Magic link / OTP
$context->auth()->signInWithOtp('user@example.com');
$session = $context->auth()->verifyOtp('user@example.com', '123456', 'email');

// OAuth (returns redirect URL — redirect the user there)
$result = $context->auth()->signInWithOAuth('google', [
    'redirectTo' => 'https://myapp.com/auth/callback',
    'scopes' => 'email profile',
]);
// $result['url'] => 'https://yourproject.supabase.co/auth/v1/authorize?provider=google&...'

// External ID token (Google, Apple, etc.)
$session = $context->auth()->signInWithIdToken([
    'provider' => 'google',
    'token' => $googleIdToken,
]);
```

### User Management

```php
// Get current user (requires access token)
$user = $context->auth()->user();
$user = $context->auth()->getUser(); // alias

// Update current user
$updated = $context->auth()->updateUser([
    'data' => ['display_name' => 'New Name'],
]);

// Password reset
$context->auth()->resetPasswordForEmail('user@example.com', [
    'redirectTo' => 'https://myapp.com/reset',
]);
```

### Session Management

```php
// Refresh access token
$session = $context->auth()->refreshToken($refreshToken);
$session = $context->auth()->setSession($refreshToken); // alias

// Sign out
$context->auth()->signOut();
```

### Admin Operations

Admin methods use the service role key and bypass RLS.

```php
$admin = $factory->createServiceContext()->auth()->admin();

// User CRUD
$users = $admin->listUsers($page, $perPage);
$user = $admin->getUser($userId);
$user = $admin->createUser([
    'email' => 'new@example.com',
    'password' => 'secret',
    'email_confirm' => true,
    'user_metadata' => ['role' => 'staff'],
]);
$user = $admin->updateUser($userId, ['email' => 'updated@example.com']);
$admin->deleteUser($userId);

// Invite user by email
$admin->inviteUserByEmail('invite@example.com', [
    'redirect_to' => 'https://myapp.com/welcome',
]);

// Generate auth link
$link = $admin->generateLink('signup', 'new@example.com', [
    'redirect_to' => 'https://myapp.com/confirm',
]);
```

## Database (PostgREST)

### Query Builder

All queries return arrays. The builder is immutable — each method returns a new instance.

```php
$db = $context->db();

// SELECT
$posts = $db->from('posts')
    ->select('id,title,created_at')
    ->eq('published', true)
    ->order('created_at', 'desc')
    ->limit(10)
    ->get();

// Single row
$post = $db->from('posts')->eq('id', 1)->single()->get();

// Maybe single (returns [] instead of throwing if not found)
$post = $db->from('posts')->eq('slug', 'hello')->maybeSingle()->get();

// Pagination
$page = $db->from('posts')->range(0, 9)->get(); // rows 0-9

// Row count
$posts = $db->from('posts')->count()->get();
// Count is returned in the Content-Range response header

// CSV output
$csv = $db->from('posts')->csv()->get();
```

### Filters

```php
->eq('column', 'value')           // =
->neq('column', 'value')          // !=
->gt('column', 10)                // >
->gte('column', 10)               // >=
->lt('column', 100)               // <
->lte('column', 100)              // <=
->like('title', '%hello%')        // LIKE
->ilike('title', '%hello%')       // ILIKE (case-insensitive)
->is('deleted_at', null)          // IS NULL / IS TRUE / IS FALSE
->in('status', ['active', 'pending'])
->contains('tags', ['php'])       // @> (array contains)
->containedBy('tags', ['php', 'go']) // <@ (array contained by)
->overlaps('tags', ['php', 'go'])    // && (array overlap)

// Negation
->not('status', 'eq', 'deleted')  // NOT equal

// OR logic
->or('status.eq.active,status.eq.pending')

// Full-text search
->textSearch('body', 'php swoole')                          // plain
->textSearch('body', 'the quick fox', ['type' => 'phrase']) // phrase
->textSearch('body', 'cat OR dog', ['type' => 'websearch']) // websearch
->textSearch('body', 'gato', ['config' => 'spanish'])       // with config

// Match multiple columns
->match(['status' => 'active', 'published' => true])

// Raw filter
->filter('id', 'in', '(1,2,3)')

// Range filters (for PostgreSQL range types)
->rangeGt('during', '[2023-01-01,2023-12-31]')
->rangeGte('during', '[2023-01-01,2023-12-31]')
->rangeLt('during', '[2023-01-01,2023-12-31]')
->rangeLte('during', '[2023-01-01,2023-12-31]')
->rangeAdjacent('during', '[2023-01-01,2023-12-31]')
```

### Insert / Update / Upsert / Delete

```php
// Insert (single or batch)
$rows = $db->from('posts')->insert(['title' => 'Hello']);
$rows = $db->from('posts')->insert([
    ['title' => 'Post A'],
    ['title' => 'Post B'],
]);

// Insert with specific return columns
$rows = $db->from('posts')->select('id,title')->insert(['title' => 'New']);

// Update (with filters)
$rows = $db->from('posts')->eq('id', 1)->update(['title' => 'Updated']);

// Upsert (insert or merge)
$rows = $db->from('posts')->upsert(['id' => 1, 'title' => 'Upserted']);

// Delete (with filters)
$rows = $db->from('posts')->eq('id', 1)->delete();
```

### RPC (Database Functions)

```php
$result = $db->rpc('my_function', ['arg1' => 'value']);
```

## Storage

### Bucket Operations

```php
$storage = $context->storage();

$buckets = $storage->listBuckets();
$bucket = $storage->getBucket('avatars');
$storage->createBucket('uploads', ['public' => true]);
$storage->updateBucket('uploads', ['file_size_limit' => 5242880]);
$storage->emptyBucket('uploads');
$storage->deleteBucket('old-bucket');

// Idempotent create-or-update
$storage->ensureBucket('avatars', ['public' => true]);
```

### File Operations

All file operations require selecting a bucket first with `from()`.

```php
$bucket = $storage->from('avatars');

// Upload
$bucket->upload('users/photo.jpg', $fileContents, 'image/jpeg');

// Download
$content = $bucket->download('users/photo.jpg');

// List files
$files = $bucket->list('users/');

// Copy & Move
$bucket->copy('users/photo.jpg', 'users/photo-backup.jpg');
$bucket->move('users/old.jpg', 'users/new.jpg');

// Delete
$bucket->delete(['users/old.jpg', 'users/temp.jpg']);
$bucket->remove(['users/old.jpg']); // alias matching supabase-js
```

### URL Generation

```php
$bucket = $storage->from('avatars');

// Public URL (no auth required, bucket must be public)
$url = $bucket->publicUrl('users/photo.jpg');
$url = $bucket->getPublicUrl('users/photo.jpg'); // alias

// Signed URL (temporary access, works with private buckets)
$result = $bucket->createSignedUrl('users/photo.jpg', 3600); // 1 hour
// $result['signedURL'] => 'https://...?token=...'

// Batch signed URLs
$results = $bucket->createSignedUrls(['a.jpg', 'b.jpg'], 3600);

// Signed upload URL (for client-side uploads)
$result = $bucket->createSignedUploadUrl('uploads/new-file.txt');

// Upload to a signed URL
$bucket->uploadToSignedUrl($result['url'], $fileContents, 'text/plain');
```

### Declarative Bucket Provisioning

Declare buckets in config and PHAPI provisions them automatically on server start:

```php
'supabase' => [
    'buckets' => [
        'avatars'   => ['public' => true],
        'documents' => ['public' => false, 'file_size_limit' => 10485760],
    ],
],
```

Buckets are provisioned in parallel using Swoole coroutines on worker 0. If a bucket already exists, its settings are updated. Errors are logged but don't block server start.

## Edge Functions

```php
$functions = $context->functions();

// Invoke a function
$result = $functions->invoke('hello', ['name' => 'World']);
// $result['data'] => ['message' => 'Hello World']
// $result['error'] => null (or error object on failure)

// With options
$result = $functions->invoke('process', $body, [
    'headers' => ['X-Custom' => 'value'],
    'region' => 'us-east-1',
    'method' => 'GET',
]);

// Error handling
$result = $functions->invoke('nonexistent');
if ($result['error'] !== null) {
    $status = $result['error']['status'];  // 404
    $message = $result['error']['message'];
}
```

## Realtime

Supabase Realtime provides WebSocket-based Postgres Changes (CDC), Broadcast, and Presence — all over Phoenix Channels. PHAPI's Realtime client uses Swoole's coroutine WebSocket client for non-blocking, persistent connections.

### Architecture

The `RealtimeClient` is a **worker-level singleton** (not request-scoped like other Supabase clients) because it maintains a persistent WebSocket connection. Access it via `SupabaseFactory`:

```php
$factory = $app->container()->get(\PHAPI\Supabase\SupabaseFactory::class);
$realtime = $factory->realtime();
```

### Channels

Create a channel and subscribe:

```php
$channel = $realtime->channel('my-room');
$channel->subscribe(function (string $status) {
    // $status: 'SUBSCRIBED', 'CHANNEL_ERROR', or 'CLOSED'
    echo "Channel status: $status\n";
});
```

### Broadcast

Send and receive real-time messages between connected clients:

```php
$channel = $realtime->channel('cursor-room', [
    'broadcast' => ['self' => true],  // receive own broadcasts
]);

$channel->on('broadcast', ['event' => 'cursor-pos'], function (array $payload) {
    $x = $payload['payload']['x'];
    $y = $payload['payload']['y'];
});

$channel->subscribe();

// Send a broadcast
$channel->send([
    'type' => 'broadcast',
    'event' => 'cursor-pos',
    'payload' => ['x' => 100, 'y' => 200],
]);
```

### Postgres Changes (CDC)

Listen to database INSERT, UPDATE, and DELETE events in real-time:

```php
$channel = $realtime->channel('db-changes');

// Listen to all changes on the posts table
$channel->on('postgres_changes', [
    'event' => '*',           // INSERT, UPDATE, DELETE, or *
    'schema' => 'public',
    'table' => 'posts',
], function (array $data) {
    echo $data['type'];              // INSERT, UPDATE, DELETE
    echo $data['table'];             // posts
    print_r($data['record']);        // new row data
    print_r($data['old_record']);    // previous row data (UPDATE/DELETE)
});

// Listen to specific events with filters
$channel->on('postgres_changes', [
    'event' => 'INSERT',
    'schema' => 'public',
    'table' => 'comments',
    'filter' => 'post_id=eq.42',
], function (array $data) {
    // Only INSERT on comments where post_id = 42
});

$channel->subscribe();
```

> **Note:** The table must be added to the `supabase_realtime` publication:
> ```sql
> ALTER PUBLICATION supabase_realtime ADD TABLE posts;
> ```

### Presence

Track connected users and synchronize shared state:

```php
$channel = $realtime->channel('online-users', [
    'presence' => ['key' => 'user-' . $userId],
]);

$channel->on('presence', ['event' => 'sync'], function (array $payload) use ($channel) {
    $state = $channel->presenceState();
    // $state: ['user-1' => [['phx_ref' => '...', 'name' => 'Alice']], ...]
});

$channel->subscribe();

// Track this user's presence
$channel->track(['name' => 'Alice', 'online_at' => date('c')]);

// Stop tracking
$channel->untrack();
```

### Channel Management

```php
// Remove a specific channel
$realtime->removeChannel($channel);

// Remove all channels
$realtime->removeAllChannels();

// List all channels
$channels = $realtime->getChannels();

// Disconnect entirely
$realtime->disconnect();
```

### Channel Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `broadcast.self` | bool | `false` | Receive own broadcast messages |
| `broadcast.ack` | bool | `false` | Server acknowledges broadcast receipt |
| `presence.key` | string | `""` | Unique key for presence tracking |

## Middleware

### Authentication

`supabase.auth` extracts the Bearer token from the `Authorization` header, validates it against GoTrue, and stores the authenticated `SupabaseContext` in the container.

```php
$api->get('/profile', function () use ($api): Response {
    $context = $api->container()->get(\PHAPI\Supabase\SupabaseContext::class);
    return Response::json($context->auth()->user());
})->middleware('supabase.auth');
```

Custom token resolver (e.g., from a cookie or query param):

```php
'supabase' => [
    'token_resolver' => function (\PHAPI\HTTP\Request $request): ?string {
        return $request->cookie('sb-token');
    },
],
```

### Role-Based Access

`supabase.role` checks the user's GoTrue role (from the JWT `role` claim or `app_metadata.role`).

```php
// Require specific role
$api->get('/admin', fn() => Response::json(['ok' => true]))
    ->middleware('supabase.auth', 'supabase.role:admin');

// GoTrue assigns 'authenticated' role by default
$api->get('/dashboard', fn() => Response::json(['ok' => true]))
    ->middleware('supabase.auth', 'supabase.role:authenticated');
```

## Exceptions

All Supabase errors throw typed exceptions extending `SupabaseException`:

| Exception | When |
|---|---|
| `SupabaseAuthException` | Auth failures (invalid token, wrong password, rate limit) |
| `SupabaseDatabaseException` | PostgREST errors (table not found, constraint violation) |
| `SupabaseStorageException` | Storage errors (bucket not found, upload failed) |
| `SupabaseRealtimeException` | Realtime errors (connection failed, channel not subscribed) |

Each exception carries:
- `httpStatus()` — the HTTP status code from Supabase
- `details()` — detailed error message
- `hint()` — hint from PostgREST (if available)

## supabase-js Migration Guide

| supabase-js | PHAPI |
|---|---|
| `createClient(url, key)` | `SupabaseProvider` + config |
| `supabase.auth.signInWithPassword()` | `$context->auth()->signInWithPassword()` |
| `supabase.auth.signInWithOAuth()` | `$context->auth()->signInWithOAuth()` |
| `supabase.auth.signInWithIdToken()` | `$context->auth()->signInWithIdToken()` |
| `supabase.auth.signUp()` | `$context->auth()->signUp()` |
| `supabase.auth.signOut()` | `$context->auth()->signOut()` |
| `supabase.auth.getUser()` | `$context->auth()->user()` / `getUser()` |
| `supabase.auth.updateUser()` | `$context->auth()->updateUser()` |
| `supabase.auth.resetPasswordForEmail()` | `$context->auth()->resetPasswordForEmail()` |
| `supabase.auth.setSession()` | `$context->auth()->setSession()` |
| `supabase.auth.admin.listUsers()` | `$admin->listUsers()` |
| `supabase.auth.admin.createUser()` | `$admin->createUser()` |
| `supabase.auth.admin.deleteUser()` | `$admin->deleteUser()` |
| `supabase.auth.admin.inviteUserByEmail()` | `$admin->inviteUserByEmail()` |
| `supabase.auth.admin.generateLink()` | `$admin->generateLink()` |
| `supabase.from('table').select()` | `$context->db()->from('table')->select()->get()` |
| `supabase.from('table').insert()` | `$context->db()->from('table')->insert()` |
| `supabase.from('table').update()` | `$context->db()->from('table')->...->update()` |
| `supabase.from('table').upsert()` | `$context->db()->from('table')->upsert()` |
| `supabase.from('table').delete()` | `$context->db()->from('table')->...->delete()` |
| `.eq()`, `.neq()`, `.gt()`, etc. | Same method names |
| `.not('col', 'op', val)` | `->not('col', 'op', $val)` |
| `.or('filter1,filter2')` | `->or('filter1,filter2')` |
| `.textSearch()` | `->textSearch()` |
| `.match({col: val})` | `->match(['col' => $val])` |
| `.order()`, `.limit()`, `.range()` | Same method names |
| `.single()`, `.maybeSingle()` | Same method names |
| `supabase.rpc()` | `$context->db()->rpc()` |
| `supabase.storage.from('b').upload()` | `$context->storage()->from('b')->upload()` |
| `supabase.storage.from('b').download()` | `$context->storage()->from('b')->download()` |
| `supabase.storage.from('b').remove()` | `$context->storage()->from('b')->remove()` |
| `supabase.storage.from('b').getPublicUrl()` | `$context->storage()->from('b')->getPublicUrl()` |
| `supabase.storage.from('b').createSignedUrl()` | `$context->storage()->from('b')->createSignedUrl()` |
| `supabase.storage.from('b').createSignedUrls()` | `$context->storage()->from('b')->createSignedUrls()` |
| `supabase.storage.from('b').createSignedUploadUrl()` | `$context->storage()->from('b')->createSignedUploadUrl()` |
| `supabase.storage.from('b').uploadToSignedUrl()` | `$context->storage()->from('b')->uploadToSignedUrl()` |
| `supabase.functions.invoke()` | `$context->functions()->invoke()` |
| `supabase.channel('name')` | `$factory->realtime()->channel('name')` |
| `channel.on('broadcast', ...)` | `$channel->on('broadcast', [...], $callback)` |
| `channel.on('postgres_changes', ...)` | `$channel->on('postgres_changes', [...], $callback)` |
| `channel.on('presence', ...)` | `$channel->on('presence', [...], $callback)` |
| `channel.subscribe()` | `$channel->subscribe()` |
| `channel.unsubscribe()` | `$channel->unsubscribe()` |
| `channel.send()` | `$channel->send()` |
| `channel.track()` | `$channel->track()` |
| `channel.untrack()` | `$channel->untrack()` |
| `supabase.removeChannel()` | `$realtime->removeChannel()` |
| `supabase.removeAllChannels()` | `$realtime->removeAllChannels()` |

### Not Applicable in PHP

These supabase-js features are client-side only and have no server-side equivalent:

- `onAuthStateChange()` — browser/client event listener
- `getSession()` — PHP is stateless per-request; use `auth()->user()` instead

## Testing

### Unit Tests (no Supabase needed)

```bash
./vendor/bin/phpunit tests/Supabase/ --testdox
```

### Integration Tests (requires Supabase)

```bash
# With env vars pointing to your Supabase project
set -a && source .env.supabase && set +a
./vendor/bin/phpunit --group supabase --testdox
```

The integration tests self-bootstrap the required database schema (tables, functions, grants) via direct PostgreSQL connection when `SUPABASE_DB_URL` is set. They clean up all created test users and buckets after each run.
