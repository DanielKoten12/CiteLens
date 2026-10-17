# syntax=docker/dockerfile:1
#
# Laravel API + queue worker. One image serves both roles: the `dev` target keeps
# development dependencies and trusts the bind-mounted sources from compose.yaml;
# the `prod` target installs `--no-dev`, bakes the application in and is meant to
# run behind docker/nginx/Dockerfile.
#
# Build context is the repository root (see compose.yaml), so paths below are
# repository-relative.

ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-fpm-bookworm AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        gosu \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Development: dev dependencies, sources arrive via bind mount in compose.yaml.
# ---------------------------------------------------------------------------
FROM base AS dev

COPY backend/composer.json backend/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --prefer-dist --no-progress --no-scripts --no-autoloader

COPY backend/ ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer dump-autoload --optimize --no-scripts \
    && php artisan package:discover --ansi

RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# ---------------------------------------------------------------------------
# Production: no dev dependencies, optimized autoloader, sources baked in.
# ---------------------------------------------------------------------------
FROM base AS prod

COPY backend/composer.json backend/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --prefer-dist --no-progress --no-scripts --no-autoloader --no-dev

COPY backend/ ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer dump-autoload --optimize --no-scripts --no-dev \
    && php artisan package:discover --ansi

RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
