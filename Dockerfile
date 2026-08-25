# Composer is not part of the official php-alpine image — pull it from
# the official composer image.
FROM composer:2 AS composer

FROM php:8.3-fpm-alpine

ENV COMPOSER_ALLOW_SUPERUSER=1

# System dependencies + PHP extensions required by the platform.
#
# Order matters: `pecl install redis` must run BEFORE
# `docker-php-ext-install`, because that helper removes its build deps
# (the `$PHPIZE_DEPS` packages, installed as a `.phpize-deps` virtual)
# when it finishes — so a naive
#   docker-php-ext-install ... && pecl install redis
# fails with `pecl: not found`.
#
# postgresql-dev provides the libpq headers for compiling pdo_pgsql;
# icu-dev for intl; libzip-dev for zip. All are removed afterwards.
RUN apk add --no-cache icu-dev libzip-dev postgresql-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-install pdo_pgsql bcmath zip intl pcntl opcache \
    && docker-php-ext-enable redis \
    && apk del icu-dev libzip-dev postgresql-dev \
    && apk add --no-cache git

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies. composer.lock is not committed yet, so deps are
# resolved at build time; once composer.lock is committed, switch to
# `COPY composer.json composer.lock ./` and plain `composer install` for
# reproducible builds. --no-scripts: no artisan should run during the
# image build (Laravel auto-generates the package manifest on first boot).
COPY composer.json ./
RUN composer update --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .

RUN composer dump-autoload --optimize --no-scripts \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["./docker/entrypoint.sh"]
