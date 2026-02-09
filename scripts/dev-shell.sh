#!/bin/bash
#
# Access shell in app container
#

cd "$(dirname "$0")/.."

SERVICE="${1:-app}"

echo "🐚 Opening shell in: $SERVICE"
docker compose exec "$SERVICE" sh
