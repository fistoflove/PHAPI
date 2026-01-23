PHAPI Multi-Runtime Example

What it demonstrates
- Providers and DI/autowiring.
- Class-based middleware.
- Jobs and task runner.
- HTTP client usage.
- WebSocket subscriptions (when running Swoole).

How to run

FPM (built-in server):
  APP_RUNTIME=fpm php -S 127.0.0.1:9503 examples/multi-runtime/app.php

AMPHP (built-in server):
  APP_RUNTIME=amphp php -S 127.0.0.1:9503 examples/multi-runtime/app.php

Swoole:
  APP_RUNTIME=swoole php examples/multi-runtime/app.php

Routes
- GET /            -> basic status
- GET /status      -> runtime info + time (controller + DI)
- GET /tasks       -> task runner sample
- GET /fetch       -> HTTP client request
- GET /broadcast   -> realtime broadcast
- GET /jobs        -> job logs

WebSocket
- Connect to ws://127.0.0.1:9503
- Send: {"action":"subscribe","channel":"updates"}

Background Process
- Started via `spawnProcess()` before `run()` when running Swoole.

Jobs
- In FPM/AMPHP, run jobs with:
  php bin/phapi-jobs examples/multi-runtime/app.php
