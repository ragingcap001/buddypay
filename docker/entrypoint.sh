#!/bin/sh
set -e

cd /var/www/html

# When a command is passed (e.g. `php artisan horizon` in the worker
# service), run it directly — no migrations, no php-fpm.
if [ $# -gt 0 ]; then
    exec "$@"
fi

# First-boot setup (idempotent) for the default php-fpm service.
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
