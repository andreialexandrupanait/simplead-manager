#!/usr/bin/env bash
set -euo pipefail

# SimpleAD Manager — Production Deployment Script
# All containers (app, horizon, scheduler) share the same image.
# Rebuilds the image and recreates containers for consistent deployment.
#
# Usage: ./deploy.sh

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE_FILE="docker-compose.prod.yml"
COMPOSE="docker compose -f $APP_DIR/$COMPOSE_FILE"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}==>${NC} $1"; }
warn() { echo -e "${YELLOW}==>${NC} $1"; }
fail() { echo -e "${RED}==>${NC} $1"; exit 1; }

cd "$APP_DIR"

# ── Pre-flight checks ────────────────────────────────────────────────────────

[ -f .env ] || fail ".env file not found"
command -v docker >/dev/null 2>&1 || fail "docker not found"
docker compose version >/dev/null 2>&1 || fail "docker compose plugin not found"
command -v node >/dev/null 2>&1 || fail "node not found (needed for Vite build)"

# ── Step 1: Pull latest code ─────────────────────────────────────────────────

log "Pulling latest code..."
git pull --ff-only || fail "git pull failed — resolve conflicts manually"

# ── Step 2: Build frontend assets ────────────────────────────────────────────

log "Installing NPM dependencies and building assets..."
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

# ── Step 3: Build Docker image ───────────────────────────────────────────────

log "Building new Docker image..."
$COMPOSE build app nginx

# ── Step 4: Gracefully stop Horizon ──────────────────────────────────────────

log "Terminating Horizon gracefully..."
$COMPOSE exec app php artisan horizon:terminate 2>/dev/null || warn "Horizon not running (skipped)"
sleep 5

# ── Step 5: Maintenance mode ─────────────────────────────────────────────────

log "Entering maintenance mode..."
$COMPOSE exec app php artisan down 2>/dev/null || true

# ── Step 6: Recreate containers with new image ───────────────────────────────

log "Recreating containers with new image..."
$COMPOSE up -d app horizon scheduler

log "Waiting for app container to be healthy..."
TIMEOUT=90
ELAPSED=0
until $COMPOSE exec app php -r 'echo "ok";' 2>/dev/null | grep -q ok; do
    sleep 3
    ELAPSED=$((ELAPSED + 3))
    if [ $ELAPSED -ge $TIMEOUT ]; then
        fail "App container did not become healthy within ${TIMEOUT}s"
    fi
done
log "App container is ready."

# ── Step 7: Run migrations ───────────────────────────────────────────────────

log "Running database migrations..."
$COMPOSE exec app php artisan migrate --force

# ── Step 8: Cache configuration ──────────────────────────────────────────────
# Skipped — containers use read_only: true, so bootstrap/cache is immutable.
# Config is read from .env at runtime; performance impact is negligible.

# ── Step 9: Restart queue workers ────────────────────────────────────────────

log "Restarting queue workers..."
$COMPOSE exec app php artisan queue:restart

# ── Step 10: Leave maintenance mode ──────────────────────────────────────────

log "Leaving maintenance mode..."
$COMPOSE exec app php artisan up

# ── Step 11: Recreate Nginx with new image ─────────────────────────────────

log "Recreating Nginx with new image..."
$COMPOSE up -d nginx

# ── Verify ────────────────────────────────────────────────────────────────────

echo ""
log "Deploy complete!"
echo "    Verify: $COMPOSE ps"
echo "    Logs:   $COMPOSE logs --tail=20 app horizon scheduler"
