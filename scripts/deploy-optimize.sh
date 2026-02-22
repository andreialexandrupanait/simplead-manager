#!/bin/bash
set -e

echo "Starting deployment optimization..."

# Put app in maintenance mode
php artisan down --message="Deploying updates"

# Install dependencies (production only)
composer install --no-dev --optimize-autoloader --no-interaction

# Clear and rebuild all caches
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Bring app back up
php artisan up

echo "Deployment optimization complete!"
