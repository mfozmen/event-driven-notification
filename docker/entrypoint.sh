#!/bin/sh
set -e

# Copy .env if not present
if [ ! -f /var/www/.env ]; then
    cp /var/www/.env.example /var/www/.env
fi

# Generate app key if not set
php artisan key:generate --no-interaction --force 2>/dev/null || true

# Run migrations
php artisan migrate --force --no-interaction 2>/dev/null || true

exec "$@"
