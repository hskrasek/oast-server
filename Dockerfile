# Dockerfile
# --- assets: build Tailwind/Vite bundle with Bun + Vite Plus ---
FROM oven/bun:1 AS assets
WORKDIR /build
COPY package.json bun.lock .npmrc vite.config.js ./
RUN bun install --frozen-lockfile
COPY resources ./resources
RUN bun run build

# --- vendor: production composer deps ---
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev

# --- runtime ---
FROM dunglas/frankenphp:1-php8.5 AS runtime
WORKDIR /app
COPY --from=vendor /build /app
COPY --from=assets /build/public/build /app/public/build
RUN php artisan config:clear \
 && mkdir -p storage/framework/{cache,sessions,views} \
 && chown -R www-data:www-data storage bootstrap/cache
ENV SERVER_NAME=:8080
# Stateless runtime defaults: no local DB/queue/session state, logs to stderr for
# CloudWatch. Nothing in this image writes anything that needs to survive a restart.
# Deployment envs (ECS task def) may still override any of these.
ENV LOG_CHANNEL=stderr
ENV SESSION_DRIVER=cookie
ENV CACHE_STORE=array
ENV QUEUE_CONNECTION=sync
ENV DB_CONNECTION=sqlite
ENV DB_DATABASE=/tmp/database.sqlite
EXPOSE 8080
USER www-data
ENTRYPOINT ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8080"]
