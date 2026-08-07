#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ] && [ -n "${APP_KEY_FILE:-}" ]; then
    if [ ! -r "$APP_KEY_FILE" ]; then
        echo "APP_KEY_FILE is not readable." >&2
        exit 64
    fi
    APP_KEY="$(php -r '$value = file_get_contents($argv[1]); if (! is_string($value)) { exit(1); } echo rtrim($value, "\r\n");' "$APP_KEY_FILE")"
    export APP_KEY
fi

/usr/local/bin/validate-app-key

OAST_QUEUE_WORKERS="${OAST_QUEUE_WORKERS:-1}"
export OAST_QUEUE_WORKERS
case "$OAST_QUEUE_WORKERS" in
    ''|*[!0-9]*|0) echo "OAST_QUEUE_WORKERS must be a positive integer." >&2; exit 64 ;;
esac
if [ "$OAST_QUEUE_WORKERS" -gt 1 ]; then
    echo "Warning: multiple queue workers increase SQLite write contention." >&2
fi

mkdir -p /var/lib/oast/publications storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
touch /var/lib/oast/database.sqlite
cp -n database/publications/*.json /var/lib/oast/publications/ 2>/dev/null || true
chown -R www-data:www-data /var/lib/oast storage bootstrap/cache
su -s /bin/sh www-data -c 'php artisan config:clear && php artisan migrate --force'
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
