#!/bin/sh
set -e

cd /var/www/html

# First-boot setup (idempotent).
if [ ! -f .env ]; then
    cp .env.example .env
fi
php artisan key:generate --force 2>/dev/null || true

# Run migrations; seed only when explicitly requested.
php artisan migrate --force

if [ "${ASE_SEED:-0}" = "1" ]; then
    php artisan db:seed --force
fi

exec php-fpm
