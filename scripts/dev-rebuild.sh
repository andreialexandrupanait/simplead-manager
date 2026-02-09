#!/bin/bash
#
# Rebuild development images (use when Dockerfile or dependencies change)
#

set -e

cd "$(dirname "$0")/.."

echo "🔨 Rebuilding SimpleAD Manager development images..."

# Stop containers
docker compose down

# Rebuild without cache
docker compose build --no-cache

# Start services
docker compose up -d

echo "✅ Development images rebuilt and services restarted!"
echo ""
echo "View logs with: docker compose logs -f"
echo ""
