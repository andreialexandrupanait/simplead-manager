#!/bin/bash
set -e

APP_CONTAINER="simplead-app"
APP_DIR="/var/www/html"

echo "==> Pulling latest code..."
git pull origin main

echo "==> Installing Composer dependencies..."
docker exec "$APP_CONTAINER" composer install --no-dev --optimize-autoloader --working-dir="$APP_DIR"

echo "==> Installing NPM dependencies and building assets..."
npm ci
npm run build

echo "==> Cleaning up build dependencies..."
rm -rf node_modules

echo "==> Running database migrations..."
docker exec "$APP_CONTAINER" php artisan migrate --force

echo "==> Caching configuration..."
docker exec "$APP_CONTAINER" php artisan config:cache
docker exec "$APP_CONTAINER" php artisan route:cache
docker exec "$APP_CONTAINER" php artisan view:cache
docker exec "$APP_CONTAINER" php artisan event:cache

echo "==> Restarting Horizon..."
docker exec "$APP_CONTAINER" php artisan horizon:terminate

echo "==> Restarting queue workers..."
docker exec "$APP_CONTAINER" php artisan queue:restart

echo "==> Running optimize..."
docker exec "$APP_CONTAINER" php artisan optimize

echo "==> Deploy complete!"
