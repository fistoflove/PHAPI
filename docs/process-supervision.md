# Process Supervision

PHAPI should be run under a supervisor so it restarts on crashes or machine reboots.
Below are minimal, production-friendly templates.

## systemd

Create `/etc/systemd/system/phapi.service`:

```ini
[Unit]
Description=PHAPI Swoole Server
After=network.target

[Service]
WorkingDirectory=/path/to/phapi
ExecStart=/usr/bin/env APP_RUNTIME=swoole /usr/bin/php example.php
Restart=always
RestartSec=2
User=www-data
Group=www-data
Environment=APP_RUNTIME=swoole
Environment=APP_PORT=9503

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable phapi
sudo systemctl start phapi
```

## supervisord

Add to `/etc/supervisor/conf.d/phapi.conf`:

```ini
[program:phapi]
directory=/path/to/phapi
command=/usr/bin/env APP_RUNTIME=swoole /usr/bin/php example.php
autostart=true
autorestart=true
stopsignal=TERM
stdout_logfile=/var/log/phapi/stdout.log
stderr_logfile=/var/log/phapi/stderr.log
user=www-data
```

Reload supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
```

## Docker

`docker-compose.yml` example:

```yaml
services:
  phapi:
    image: php:8.3-cli
    working_dir: /app
    volumes:
      - .:/app
    command: ["php", "example.php"]
    environment:
      APP_RUNTIME: swoole
      APP_PORT: 9503
    restart: unless-stopped
```

## PM2

```bash
pm2 start php --name phapi -- bin/phapi-run example.php
pm2 save
```
