# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.5 AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# gmp is no longer required -- php-atproto-identity decompresses signing keys
# through OpenSSL -- but it is kept as a safety net. If this image's OpenSSL
# ever refuses a compressed SubjectPublicKeyInfo, the package falls back to
# doing the arithmetic in PHP, which costs ~1.5ms with gmp loaded and ~1.5s
# without. Drop it once that fallback is confirmed unreachable here.
RUN install-php-extensions gmp pdo_sqlite intl pcntl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader


FROM dunglas/frankenphp:1-php8.5

RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions gmp pdo_sqlite intl pcntl opcache

WORKDIR /app

# Tempest reads composer.json at boot to work out where to discover classes,
# so it has to be in the runtime image, not just the build stage.
COPY composer.json composer.lock ./
COPY --from=vendor /app/vendor ./vendor
COPY app ./app
COPY public ./public
COPY tempest ./tempest

COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint /app/tempest

# Production wants a full discovery cache, and without one on disk Tempest
# falls back to reflecting over every class on every request: ~200ms of CPU
# per getFeedSkeleton instead of ~20ms. Built here because the image is
# rebuilt on every change to app/, so the cache can never go stale.
RUN ENVIRONMENT=production php /app/tempest discovery:generate

# The database lives here unless FEEDGEN_SQLITE_LOCATION points elsewhere.
# Mount a volume over it in production: an unmounted path is wiped on every
# deploy, taking the index and the subscription cursor with it.
RUN mkdir -p /app/var && chown -R www-data:www-data /app/var

ENV ENVIRONMENT=production
ENV FEEDGEN_SQLITE_LOCATION=/app/var/database.sqlite
ENV FEEDGEN_LOG_PATH=php://stdout
ENV SERVER_NAME=:80

EXPOSE 80

ENTRYPOINT ["entrypoint"]
