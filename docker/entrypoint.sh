#!/bin/sh
set -e

if [ ! -f /var/www/.env ]; then
    touch /var/www/.env
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force --no-interaction 2>/dev/null || true
    php artisan l5-swagger:generate 2>/dev/null || true
fi

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

touch /tmp/app-ready

exec "$@"
