# Production image: serversideup/php (nginx + PHP-FPM, unprivileged, port 8080)
# with Composer dependencies and the Vite build baked in.
#
#   docker build -f docker/Dockerfile -t postcrossing-tracker:local .
#
# Runtime config comes only from environment variables (see docker/env.example);
# no .env file is copied into the image.

# --- front-end assets ---------------------------------------------------------
# Base images pinned by registry index digest (looked up 2026-11-19)
FROM node:22-bookworm-slim@sha256:e9f565d32859f0cbfb52125eafe8be39390f24dac302dc3cdf566e8ba3ab21ee AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
RUN npm run build

# --- PHP dependencies -----------------------------------------------------------
FROM composer:2@sha256:0c8e663f14c94d07b3c45b175c51f5a14718508f21fe4dd7e54f09488dd0ed71 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- runtime ----------------------------------------------------------------------
FROM serversideup/php:8.4-fpm-nginx@sha256:8fd9da030f78d98147490efdc93ca39de6ea69b48e16a1a0694d2c9d716501c8
WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /app /var/www/html
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build
RUN php artisan package:discover --ansi

# Run migrations, storage:link and Laravel caches on start (one app container only)
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION_TIMEOUT=60 \
    LOG_CHANNEL=stderr
