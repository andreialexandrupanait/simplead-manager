#!/bin/bash
#
# Stop the development environment
#

set -e

cd "$(dirname "$0")/.."

echo "🛑 Stopping SimpleAD Manager development environment..."

docker compose down

echo "✅ Development environment stopped!"
echo ""
echo "Note: Data volumes are preserved. To remove volumes, run:"
echo "  docker compose down -v"
echo ""
