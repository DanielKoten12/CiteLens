#!/usr/bin/env bash
#
# Shared entrypoint for the Laravel image (php-fpm and queue:work).
#
# Responsibilities, in order:
#   1. make the framework directories writable (named volumes start root-owned),
#   2. resolve APP_KEY (environment first, otherwise a key persisted on the
#      storage volume so `backend` and `worker` always share one),
#   3. install Composer dependencies when the vendor volume is empty,
#   4. wait for PostgreSQL,
#   5. run migrations and warm caches on the service that is allowed to,
#   6. run the container command (php-fpm keeps its root master; everything else
#      runs as www-data).
#
# Every step is idempotent and safe to run on every start.

set -euo pipefail

cd /var/www/html

log() {
    printf '[entrypoint] %s\n' "$*"
}

# ---------------------------------------------------------------------------
# 1. Writable framework directories
# ---------------------------------------------------------------------------
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Package/provider manifests are rebuilt from the current image on every boot.
# A manifest left behind by a different image (switching dev <-> prod, or a
# composer dependency change) can reference a class that is not installed and
# makes every artisan command fail before it can fix anything.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

if [ "$(id -u)" = "0" ]; then
    chown www-data:www-data storage bootstrap/cache
    chown -R www-data:www-data storage/framework storage/logs bootstrap/cache 2>/dev/null || true
    chown -R www-data:www-data storage/app 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# 2. Application key
# ---------------------------------------------------------------------------
# A pinned APP_KEY always wins. Without one (container development), a key is
# generated once and stored on the shared storage volume; both the API and the
# queue worker then encrypt with the same key across restarts.
if [ -z "${APP_KEY:-}" ]; then
    key_file="storage/app/private/.docker-app-key"

    if [ -s "$key_file" ]; then
        APP_KEY="$(cat "$key_file")"
    else
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        printf '%s' "$APP_KEY" > "$key_file"
        log "APP_KEY was empty; generated a development key and stored it on the storage volume"
    fi

    export APP_KEY
fi

# ---------------------------------------------------------------------------
# 3. Composer dependencies
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
    log "vendor/autoload.php is missing; running composer install"
    composer install --no-interaction --prefer-dist --no-progress
fi

# ---------------------------------------------------------------------------
# 4. Database
# ---------------------------------------------------------------------------
wait_for_database() {
    case "${DB_CONNECTION:-pgsql}" in
        pgsql)
            until php -r 'new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
                log "waiting for PostgreSQL at ${DB_HOST:-db}:${DB_PORT:-5432}"
                sleep 2
            done
            ;;
        mysql)
            until php -r 'new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
                log "waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306}"
                sleep 2
            done
            ;;
    esac
}

wait_for_database

# ---------------------------------------------------------------------------
# 5. Migrations and caches (single writer: RUN_MIGRATIONS=true)
# ---------------------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    log "running database migrations"
    php artisan migrate --force
    php artisan storage:link >/dev/null 2>&1 || true
fi

# `route:cache` is intentionally not used: routes/web.php defines a closure route.
if [ "${RUN_CACHING:-false}" = "true" ]; then
    log "warming the Laravel caches"
    php artisan config:cache
    php artisan view:cache
fi

# ---------------------------------------------------------------------------
# 6. Run
# ---------------------------------------------------------------------------
# php-fpm's master process must stay root: it opens its error log on the inherited
# stderr and drops its pool workers to www-data itself (official image design).
case "${1:-}" in
    php-fpm|php-fpm*)
        exec "$@"
        ;;
esac

# Everything else (queue workers, artisan commands) runs as the application user.
if [ "$(id -u)" = "0" ]; then
    exec gosu www-data "$@"
fi

exec "$@"
