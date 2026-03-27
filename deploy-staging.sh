#!/usr/bin/env bash
set -euo pipefail

# SimpleAD Manager — Staging Deployment Script
#
# Usage:
#   ./deploy-staging.sh              # Full build + deploy
#   ./deploy-staging.sh --skip-build # Reuse existing :staging images
#   ./deploy-staging.sh --seed       # Run db:seed after migrations
#   ./deploy-staging.sh --fresh      # Fresh migrate (drops all tables)

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE_FILE="docker-compose.staging.yml"
COMPOSE="docker compose -f $APP_DIR/$COMPOSE_FILE"
PROD_COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

log()  { echo -e "${GREEN}[staging]=>${NC} $1"; }
warn() { echo -e "${YELLOW}[staging]=>${NC} $1"; }
fail() { echo -e "${RED}[staging]=>${NC} $1"; exit 1; }
info() { echo -e "${CYAN}[staging]=>${NC} $1"; }

SKIP_BUILD=false
SEED=false
FRESH=false

for arg in "$@"; do
    case $arg in
        --skip-build) SKIP_BUILD=true ;;
        --seed)       SEED=true ;;
        --fresh)      FRESH=true ;;
        *)            warn "Unknown argument: $arg" ;;
    esac
done

cd "$APP_DIR"

# ── Pre-flight checks ────────────────────────────────────────────────────────

[ -f .env.staging ] || fail ".env.staging not found — copy from .env and adjust values"
command -v docker >/dev/null 2>&1 || fail "docker not found"
docker compose version >/dev/null 2>&1 || fail "docker compose plugin not found"

# Verify production PostgreSQL is running
$PROD_COMPOSE ps pgsql --format '{{.State}}' 2>/dev/null | grep -q running \
    || fail "Production PostgreSQL container (simplead-pgsql) is not running"

# ── Step 1: Build images (unless --skip-build) ───────────────────────────────

if [ "$SKIP_BUILD" = false ]; then
    log "Building Docker images with :staging tag..."

    # Build frontend assets if node is available
    if command -v node >/dev/null 2>&1; then
        log "Installing NPM dependencies and building assets..."
        npm ci --no-audit --no-fund
        npm run build
        rm -rf node_modules
    else
        warn "node not found — skipping frontend build (using existing assets)"
    fi

    $COMPOSE build app nginx
else
    info "Skipping image build (--skip-build)"
fi

# ── Step 2: Create staging database if needed ─────────────────────────────────

log "Ensuring staging database exists..."
PSQL_CMD='PGPASSWORD=$POSTGRES_PASSWORD psql -U $POSTGRES_USER'
DB_EXISTS=$($PROD_COMPOSE exec -T pgsql sh -c "$PSQL_CMD -d \$POSTGRES_DB -tAc \"SELECT 1 FROM pg_database WHERE datname = 'simplead_staging'\"" 2>/dev/null || true)

if [ "$DB_EXISTS" != "1" ]; then
    log "Creating database 'simplead_staging'..."
    $PROD_COMPOSE exec -T pgsql sh -c "$PSQL_CMD -d \$POSTGRES_DB -c \"CREATE DATABASE simplead_staging OWNER \$POSTGRES_USER;\"" \
        || fail "Failed to create staging database"
    log "Database 'simplead_staging' created."
else
    info "Database 'simplead_staging' already exists."
fi

# ── Step 3: Create volume if needed ───────────────────────────────────────────

if ! docker volume inspect simplead-staging_app-storage >/dev/null 2>&1; then
    log "Creating volume simplead-staging_app-storage..."
    docker volume create simplead-staging_app-storage
fi

# ── Step 4: Stop existing staging containers ──────────────────────────────────

if $COMPOSE ps -q 2>/dev/null | grep -q .; then
    log "Stopping existing staging containers..."
    $COMPOSE exec app php artisan horizon:terminate 2>/dev/null || true
    sleep 3
fi

# ── Step 5: Start staging stack ───────────────────────────────────────────────

log "Starting staging containers..."
$COMPOSE up -d

log "Waiting for app container to be healthy..."
TIMEOUT=90
ELAPSED=0
until $COMPOSE exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q ok; do
    sleep 3
    ELAPSED=$((ELAPSED + 3))
    if [ $ELAPSED -ge $TIMEOUT ]; then
        fail "Staging app container did not become healthy within ${TIMEOUT}s"
    fi
done
log "Staging app container is ready."

# ── Step 6: Initialize storage directories ────────────────────────────────────

log "Ensuring storage directory structure..."
$COMPOSE exec -T app php artisan storage:link 2>/dev/null || true

# ── Step 7: Run migrations ────────────────────────────────────────────────────
# Run migrations via a temporary container connected directly to PostgreSQL,
# bypassing PgBouncer (which breaks server-side prepared statements in
# transaction pooling mode).

MIGRATE_CMD="docker run --rm --network simplead-manager_simplead \
    --env-file $APP_DIR/.env.staging \
    -e DB_HOST=simplead-pgsql \
    -v simplead-staging_app-storage:/var/www/html/storage \
    simplead-app:staging"

if [ "$FRESH" = true ]; then
    warn "Running fresh migration (dropping all tables)..."
    $MIGRATE_CMD php artisan migrate:fresh --force
else
    log "Running database migrations..."
    $MIGRATE_CMD php artisan migrate --force
fi

# ── Step 8: Seed database (optional) ──────────────────────────────────────────

if [ "$SEED" = true ]; then
    log "Seeding database..."
    $COMPOSE exec -T app php artisan db:seed --force
fi

# ── Step 9: Restart queue workers ─────────────────────────────────────────────

log "Restarting queue workers..."
$COMPOSE exec -T app php artisan queue:restart

# ── Step 10: Ensure app is live ─────────────────────────────────────────────

$COMPOSE exec -T app php artisan up 2>/dev/null || true

# ── Verify ────────────────────────────────────────────────────────────────────

echo ""
log "Staging deploy complete!"
echo ""
info "  URL:      https://staging.simplead.ro"
info "  Status:   $COMPOSE ps"
info "  Logs:     $COMPOSE logs --tail=20 app horizon scheduler"
info "  Stop:     $COMPOSE down"
echo ""
