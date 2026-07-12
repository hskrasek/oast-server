# oast self-host image

Generate and retain one real key with `php artisan key:generate --show`. Start on host loopback:

```bash
docker volume create oast-data
docker run -d --name oast -p 127.0.0.1:8080:8080 \
  -e APP_KEY='base64:replace-with-output-from-key-generate' \
  -e OAST_BOOTSTRAP_SECRET='replace-with-a-high-entropy-secret' \
  -v oast-data:/var/lib/oast oast-server:latest
```

The strings `01234567890123456789012345678901`, `00000000000000000000000000000000`, `base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=`, and `base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=` are rejected sentinels, not usable examples. A Docker secret may instead be mounted with `APP_KEY_FILE=/run/secrets/app_key`; trailing CR/LF is removed safely. Missing, malformed, and sentinel raw/base64 keys exit 64. Startup runs `php artisan migrate --force` before either supervised child starts; migration failure exits non-zero.

`/var/lib/oast` contains `database.sqlite`, its WAL/SHM files, and `publications/`. Keep the volume across replacement containers. Database-backed queue, cache, and sessions are defaults. One queue listener is supported by default. `OAST_QUEUE_WORKERS=2` or greater enables more listeners but increases SQLite write contention.

The worker uses `--timeout=900`. Keep `DB_QUEUE_RETRY_AFTER=960` (or another value strictly greater than 900) so a still-running job cannot be redelivered before the worker timeout.

## Reverse proxy and TLS

Remote exposure requires TLS and secure cookies. Keep Docker published on `127.0.0.1` and put the TLS proxy on the host. Nginx's SSE location must include:

```nginx
location /app/reviews/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 600s;
}
```

The application also emits `X-Accel-Buffering: no`. Other proxies must disable response buffering and allow streams for at least 600 seconds.

## Health

`GET /up` verifies database connectivity and zero pending migrations. `/usr/local/bin/oast-worker-health` separately verifies exactly `OAST_QUEUE_WORKERS` queue processes are `RUNNING`. Docker combines both checks.

## Backup and restore

Stop the container so SQLite and the worker are quiescent: `docker stop oast`. Copy the complete volume, including database/WAL/SHM and publications. Restore the complete directory into a fresh volume, then start a replacement container with the same stable `APP_KEY`. Upgrade by pulling the image and recreating the container with the same volume and key; migrations run automatically.
