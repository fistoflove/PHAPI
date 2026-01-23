PHAPI Example App

What it demonstrates
- Providers and DI/autowiring.
- Class-based middleware.
- Jobs and task runner.
- HTTP client usage.
- WebSocket subscriptions.

How to run

Native Swoole:
  APP_RUNTIME=swoole php examples/multi-runtime/app.php

Portable Swoole:
  APP_RUNTIME=portable_swoole php bin/phapi-run examples/multi-runtime/app.php

Routes
- GET /            -> basic status
- GET /status      -> runtime info + time (controller + DI)
- GET /tasks       -> task runner sample
- GET /fetch       -> HTTP client request
- GET /broadcast   -> realtime broadcast
- GET /redis       -> Redis coroutine sample
- GET /mysql       -> MySQL coroutine sample
- GET /jobs        -> job logs

WebSocket
- Connect to ws://127.0.0.1:9503
- Send: {"action":"subscribe","channel":"updates"}

Background Process
- Started via `spawnProcess()` before `run()`.

Jobs
- Jobs run automatically under Swoole.
