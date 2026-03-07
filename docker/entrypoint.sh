#!/bin/sh
set -e

if [ ! -f /var/www/.env ]; then
    touch /var/www/.env
fi

if [ ! -d /var/www/vendor ]; then
    mkdir -p /var/www/vendor
    COMPOSER_MEMORY_LIMIT=-1 composer install --no-scripts --no-interaction --prefer-dist
    composer dump-autoload --optimize
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force --no-interaction 2>/dev/null || true
    php artisan l5-swagger:generate 2>/dev/null || true
fi

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

touch /tmp/app-ready

exec "$@"
