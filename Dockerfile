FROM oven/bun:1 AS assets
WORKDIR /build
COPY package.json bun.lock .npmrc vite.config.js ./
RUN bun install --frozen-lockfile
COPY resources ./resources
RUN bun run build

FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM dunglas/frankenphp:1-php8.5 AS runtime
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends supervisor curl \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=vendor /build /app
COPY --from=assets /build/public/build /app/public/build
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/oast-entrypoint
COPY docker/validate-app-key /usr/local/bin/validate-app-key
COPY docker/oast-worker-health /usr/local/bin/oast-worker-health
RUN chmod 0755 /usr/local/bin/oast-entrypoint /usr/local/bin/validate-app-key /usr/local/bin/oast-worker-health \
 && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache /var/lib/oast/publications \
 && chown -R www-data:www-data storage bootstrap/cache /var/lib/oast
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/lib/oast/database.sqlite \
    DB_JOURNAL_MODE=WAL \
    DB_BUSY_TIMEOUT=5000 \
    DB_TRANSACTION_MODE=IMMEDIATE \
    DB_QUEUE_RETRY_AFTER=960 \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    SITE_PUBLICATIONS_PATH=/var/lib/oast/publications \
    OAST_QUEUE_WORKERS=1 \
    SERVER_NAME=:8080
VOLUME ["/var/lib/oast"]
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl --fail --silent http://127.0.0.1:8080/up >/dev/null && /usr/local/bin/oast-worker-health
ENTRYPOINT ["/usr/local/bin/oast-entrypoint"]
