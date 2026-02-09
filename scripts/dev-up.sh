#!/bin/bash
#
# Start the development environment
#

set -e

cd "$(dirname "$0")/.."

echo "🚀 Starting SimpleAD Manager development environment..."

# Build images if they don't exist
if ! docker images | grep -q "simplead-app.*dev"; then
    echo "📦 Building development images (first time only)..."
    docker compose build
fi

# Start all services
docker compose up -d

echo "✅ Development environment started!"
echo ""
echo "Services:"
echo "  - Application: http://localhost"
echo "  - Database: localhost:5432"
echo ""
echo "Useful commands:"
echo "  - View logs:        docker compose logs -f app"
echo "  - View all logs:    docker compose logs -f"
echo "  - Run artisan:      docker compose exec app php artisan <command>"
echo "  - Access shell:     docker compose exec app bash"
echo "  - Stop services:    docker compose down"
echo ""
