# Production image: PHP-FPM + nginx (serversideup/php), unprivileged, listening on 8080.
# One image runs all three roles — web, queue worker and scheduler — picked by the compose `command`.

# --- PHP dependencies (no dev packages, no scripts: artisan isn't there yet) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# --- Front-end build (Tailwind scans vendor/ for the pagination views, so it comes after vendor) ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN npm run build

# --- Runtime ---
FROM serversideup/php:8.4-fpm-nginx AS runtime

USER root
RUN install-php-extensions intl bcmath
USER www-data

WORKDIR /var/www/html

COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

ENV PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/up \
    LOG_CHANNEL=stderr
