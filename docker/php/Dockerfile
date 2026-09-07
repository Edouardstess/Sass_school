# syntax=docker/dockerfile:1
###############################################################################
# SchoolFlow backend image (Laravel 12 / PHP 8.3 FPM)
#
# `development` mounts the source from the host and runs one of three roles
# (app | worker | scheduler) selected with CONTAINER_ROLE.
# `production` bakes the source and a warm autoloader/config cache into a
# self-contained image.
###############################################################################

FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        bash git icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
        postgresql-dev oniguruma-dev linux-headers supervisor \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql bcmath intl zip gd opcache pcntl sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-schoolflow.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

# ---------------------------------------------------------------- development
FROM base AS development

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN apk add --no-cache --virtual .xdebug-deps $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-deps
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/98-xdebug.ini

ENTRYPOINT ["entrypoint"]

# ----------------------------------------------------------------- production
FROM base AS production

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["entrypoint"]
