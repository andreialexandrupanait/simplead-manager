#!/bin/sh
set -e

# Cache config, routes, and views on container startup.
# bootstrap/cache is a tmpfs mount, so this runs fresh on every deploy.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Execute the main command (php-fpm, horizon, or schedule:work)
exec "$@"
