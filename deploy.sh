#!/bin/bash
set -e

# SimpleAD Manager — Production Deployment Script
# All containers (app, horizon, scheduler) share the same image.
# Rebuilds the image and recreates containers for consistent deployment.

COMPOSE_FILE="docker-compose.prod.yml"

echo "==> Pulling latest code..."
git pull origin main

echo "==> Installing NPM dependencies and building assets..."
npm ci
npm run build
rm -rf node_modules

echo "==> Building new Docker image..."
docker compose -f "$COMPOSE_FILE" build app

echo "==> Terminating Horizon gracefully..."
docker compose -f "$COMPOSE_FILE" exec app php artisan horizon:terminate 2>/dev/null || true
sleep 5

echo "==> Recreating containers with new image..."
docker compose -f "$COMPOSE_FILE" up -d app horizon scheduler

echo "==> Waiting for app container to be healthy..."
timeout=60
elapsed=0
until docker compose -f "$COMPOSE_FILE" exec app php -r 'echo "ok";' 2>/dev/null | grep -q ok; do
    sleep 2
    elapsed=$((elapsed + 2))
    if [ $elapsed -ge $timeout ]; then
        echo "ERROR: App container did not become healthy within ${timeout}s"
        exit 1
    fi
done
echo "    App container is ready."

echo "==> Running database migrations..."
docker compose -f "$COMPOSE_FILE" exec app php artisan migrate --force

echo "==> Caching configuration..."
docker compose -f "$COMPOSE_FILE" exec app php artisan config:cache
docker compose -f "$COMPOSE_FILE" exec app php artisan route:cache
docker compose -f "$COMPOSE_FILE" exec app php artisan view:cache
docker compose -f "$COMPOSE_FILE" exec app php artisan event:cache

echo "==> Restarting Horizon..."
docker compose -f "$COMPOSE_FILE" exec app php artisan horizon:terminate 2>/dev/null || true

echo "==> Restarting queue workers..."
docker compose -f "$COMPOSE_FILE" exec app php artisan queue:restart

echo ""
echo "==> Deploy complete!"
echo "    Verify: docker compose -f $COMPOSE_FILE ps"
