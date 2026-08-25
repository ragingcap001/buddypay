FROM php:8.3-fpm-alpine

# System dependencies + PHP extensions required by the platform.
RUN apk add --no-cache icu-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql bcmath zip intl pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del icu-dev libzip-dev \
    && apk add --no-cache git

WORKDIR /var/www/html

# Install dependencies (composer.lock is generated on first build).
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress || true

COPY . .

RUN composer install --no-interaction --prefer-dist --no-progress \
    && composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["./docker/entrypoint.sh"]
