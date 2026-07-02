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

# ── Step 1: Pull latest code ─────────────────────────────────────────────────

log "Pulling latest code..."
git pull --ff-only || fail "git pull failed — resolve conflicts manually"

# ── Step 2: Build Docker image (includes frontend assets via multi-stage) ────

log "Building new Docker image..."
DOCKER_BUILDKIT=1 $COMPOSE build app nginx

# ── Step 4: Gracefully stop Horizon (drain in-flight jobs) ───────────────────
# horizon:terminate signals the master (via Redis) to finish current jobs and
# exit. We then `stop` with a BOUNDED grace so the deploy can never hang: this
# host's Horizon does not always exit promptly on SIGTERM even when idle, so an
# unbounded `-t 3660` blocks the whole deploy for up to 61 minutes. 120s is
# enough for a normal job to finish; a rare long restore interrupted here is
# recovered by RestoreBackup::failed() (see remediation item 1.3), not by
# stalling every deploy. Do not deploy while a restore is knowingly running.
DRAIN_TIMEOUT="${DEPLOY_DRAIN_TIMEOUT:-120}"

log "Signalling Horizon to finish in-flight jobs..."
$COMPOSE exec app php artisan horizon:terminate 2>/dev/null || warn "Horizon not running (skipped)"

log "Stopping Horizon and scheduler gracefully (waits up to ${DRAIN_TIMEOUT}s, then forces)..."
$COMPOSE stop -t "$DRAIN_TIMEOUT" horizon scheduler

# ── Step 5: Maintenance mode ─────────────────────────────────────────────────

log "Entering maintenance mode..."
$COMPOSE exec app php artisan down 2>/dev/null || true

# ── Step 6: Recreate containers with new image ───────────────────────────────

log "Cleaning up stale containers..."
for svc in app horizon scheduler nginx; do
    docker rm -f "simplead-$svc" 2>/dev/null || true
done
# Also remove any prefixed orphan containers (e.g. abc123_simplead-horizon)
docker ps -a --filter "status=created" --filter "status=exited" --format '{{.Names}}' \
    | grep -E 'simplead-(app|horizon|scheduler|nginx)' \
    | xargs -r docker rm -f 2>/dev/null || true

log "Recreating containers with new image..."
$COMPOSE up -d --force-recreate --remove-orphans app horizon scheduler

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

# ── Step 7b: Restart PgBouncer after DDL migrations ──────────────────────────
# PgBouncer (transaction pooling) caches prepared statements; after an ALTER
# TABLE clients get "cached plan must not change result type" 500s until it is
# restarted. This bit production before — see docs/audit/26-infrastructure-docker.md.
log "Restarting PgBouncer (clears cached query plans after DDL)..."
$COMPOSE restart pgbouncer

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
$COMPOSE up -d --force-recreate nginx

# ── Verify ────────────────────────────────────────────────────────────────────

echo ""
log "Deploy complete!"
echo "    Verify: $COMPOSE ps"
echo "    Logs:   $COMPOSE logs --tail=20 app horizon scheduler"
