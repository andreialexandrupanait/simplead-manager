#!/bin/bash
#
# Fresh start: rebuild images and reset database
#

set -e

cd "$(dirname "$0")/.."

echo "⚠️  WARNING: This will reset your development database!"
read -p "Are you sure you want to continue? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 1
fi

echo "🔄 Starting fresh development environment..."

# Stop containers
docker compose down

# Rebuild images
docker compose build --no-cache

# Start services
docker compose up -d

# Wait for database to be ready
echo "⏳ Waiting for database to be ready..."
sleep 5

# Run migrations
echo "📊 Running migrations..."
docker compose exec app php artisan migrate:fresh --seed

# Clear all caches
echo "🧹 Clearing caches..."
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan route:clear

echo "✅ Fresh development environment ready!"
echo ""
echo "View logs with: docker compose logs -f"
echo ""
