#!/bin/bash
#
# View development logs
#

cd "$(dirname "$0")/.."

SERVICE="${1:-app}"

if [ "$SERVICE" = "all" ]; then
    echo "📋 Viewing all service logs..."
    docker compose logs -f
else
    echo "📋 Viewing logs for: $SERVICE"
    docker compose logs -f "$SERVICE"
fi
