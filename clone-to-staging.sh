#!/usr/bin/env bash
set -euo pipefail

# SimpleAD Manager — Clone Production DB to Staging
#
# Dumps the production database and restores it into the staging database.
# Runs entirely inside the production PostgreSQL container.
#
# WARNING: Encrypted fields (api_key, api_secret on sites) will NOT be
# decryptable in staging because it uses a different APP_KEY. This is
# intentional — it prevents staging from accidentally communicating with
# real WordPress sites.
#
# Usage: ./clone-to-staging.sh

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PROD_COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"

PROD_DB="simplead_manager"
STAGING_DB="simplead_staging"
DB_USER="simplead"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[clone]=>${NC} $1"; }
warn() { echo -e "${YELLOW}[clone]=>${NC} $1"; }
fail() { echo -e "${RED}[clone]=>${NC} $1"; exit 1; }

# ── Pre-flight checks ────────────────────────────────────────────────────────

$PROD_COMPOSE ps pgsql --format '{{.State}}' 2>/dev/null | grep -q running \
    || fail "Production PostgreSQL container (simplead-pgsql) is not running"

# Check staging DB exists
PSQL_CMD='PGPASSWORD=$POSTGRES_PASSWORD psql -U $POSTGRES_USER'
DB_EXISTS=$($PROD_COMPOSE exec -T pgsql sh -c "$PSQL_CMD -d \$POSTGRES_DB -tAc \"SELECT 1 FROM pg_database WHERE datname = '$STAGING_DB'\"" 2>/dev/null || true)

if [ "$DB_EXISTS" != "1" ]; then
    fail "Staging database '$STAGING_DB' does not exist. Run ./deploy-staging.sh first."
fi

# ── Confirmation ──────────────────────────────────────────────────────────────

echo ""
warn "This will REPLACE ALL DATA in '$STAGING_DB' with a copy from '$PROD_DB'."
echo ""
warn "Important:"
warn "  - Encrypted fields (api_key, api_secret) will NOT be decryptable in staging"
warn "  - Staging uses a different APP_KEY, so WP site communication will fail"
warn "  - This is intentional — it prevents accidental actions on real sites"
echo ""
read -rp "Type 'clone' to proceed: " CONFIRM

if [ "$CONFIRM" != "clone" ]; then
    echo "Aborted."
    exit 0
fi

# ── Stop staging Horizon (prevent jobs running during clone) ──────────────────

STAGING_COMPOSE="docker compose -f $APP_DIR/docker-compose.staging.yml"
if $STAGING_COMPOSE ps -q horizon 2>/dev/null | grep -q .; then
    log "Stopping staging Horizon..."
    $STAGING_COMPOSE exec -T app php artisan horizon:terminate 2>/dev/null || true
    sleep 3
fi

# ── Drop and recreate staging database ────────────────────────────────────────

log "Dropping staging database..."
$PROD_COMPOSE exec -T pgsql sh -c \
    "$PSQL_CMD -d postgres -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$STAGING_DB' AND pid <> pg_backend_pid();\"" \
    >/dev/null 2>&1 || true

$PROD_COMPOSE exec -T pgsql sh -c \
    "$PSQL_CMD -d postgres -c \"DROP DATABASE IF EXISTS $STAGING_DB;\""

log "Creating fresh staging database..."
$PROD_COMPOSE exec -T pgsql sh -c \
    "$PSQL_CMD -d postgres -c \"CREATE DATABASE $STAGING_DB OWNER \$POSTGRES_USER;\""

# ── Dump and restore ─────────────────────────────────────────────────────────

log "Cloning '$PROD_DB' -> '$STAGING_DB' (this may take a moment)..."
$PROD_COMPOSE exec -T pgsql sh -c \
    "PGPASSWORD=\$POSTGRES_PASSWORD pg_dump -U \$POSTGRES_USER -Fc $PROD_DB | PGPASSWORD=\$POSTGRES_PASSWORD pg_restore -U \$POSTGRES_USER -d $STAGING_DB --no-owner --no-acl" \
    || fail "Database clone failed"

# ── Restart staging ───────────────────────────────────────────────────────────

if $STAGING_COMPOSE ps -q app 2>/dev/null | grep -q .; then
    log "Restarting staging Horizon..."
    $STAGING_COMPOSE exec -T app php artisan queue:restart 2>/dev/null || true
fi

echo ""
log "Clone complete! Staging database now mirrors production data."
warn "Remember: encrypted fields are NOT decryptable in staging (different APP_KEY)."
echo ""
