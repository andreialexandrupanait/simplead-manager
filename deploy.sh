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

# ── Step 1b: CI gate — refuse to ship red code (audit T-A2-01) ───────────────
# Verifies the required CI checks passed for the exact commit being deployed.
# Override in an emergency with DEPLOY_SKIP_CI_CHECK=1.

# P1-37: the CI gate is now FAIL-CLOSED and race-safe. The previous version
# failed OPEN on a transient gh error ("continuing") and treated a not-yet-
# registered / still-running merge-commit CI as either a hard "missing" refusal
# or, on API error, a silent pass. New behaviour:
#   pending / missing → WAIT and poll (up to DEPLOY_CI_TIMEOUT), never pass or
#                        refuse just because CI has not finished registering yet;
#   red (failure)     → refuse immediately (fail-closed);
#   transient gh error → retry (DEPLOY_CI_API_RETRIES) then keep polling; only a
#                        FULL timeout of unreachability refuses — it never passes;
#   green (success)   → proceed.
# Emergency override remains DEPLOY_SKIP_CI_CHECK=1.
if [ "${DEPLOY_SKIP_CI_CHECK:-0}" != "1" ]; then
    if command -v gh >/dev/null 2>&1; then
        DEPLOY_SHA=$(git rev-parse HEAD)
        CI_GATE_TIMEOUT="${DEPLOY_CI_TIMEOUT:-1800}"        # max seconds to wait for CI to finish
        CI_POLL_INTERVAL="${DEPLOY_CI_POLL_INTERVAL:-20}"   # seconds between polls while pending
        CI_API_MAX_RETRIES="${DEPLOY_CI_API_RETRIES:-3}"    # transient-error retries per poll
        CI_GATE_START=$(date +%s)

        log "Checking CI status for ${DEPLOY_SHA:0:8} (waits up to ${CI_GATE_TIMEOUT}s for pending CI)..."

        query_ci_status() {
            gh api "repos/{owner}/{repo}/commits/${DEPLOY_SHA}/check-runs" \
                --jq '[.check_runs[]
                       | select(.name == "Pint (code style)"
                             or .name == "PHPStan (static analysis)"
                             or .name == "PHPUnit (pgsql + redis)")
                       | {status: .status, conclusion: .conclusion}]
                      | if length == 0 then "missing"
                        elif any(.[]; .status != "completed") then "pending"
                        elif all(.[]; .conclusion == "success") then "success"
                        else "failure" end'
        }

        while :; do
            # Query with transient-error retry — a single flaky gh/network call
            # must never decide the deploy. Only after CI_API_MAX_RETRIES
            # consecutive failures do we treat the poll as an api-error.
            CI_RESULT="api-error"
            api_attempt=1
            while [ "$api_attempt" -le "$CI_API_MAX_RETRIES" ]; do
                if CI_RESULT=$(query_ci_status 2>/dev/null); then
                    break
                fi
                CI_RESULT="api-error"
                if [ "$api_attempt" -lt "$CI_API_MAX_RETRIES" ]; then sleep 5; fi
                api_attempt=$((api_attempt + 1))
            done

            CI_ELAPSED=$(( $(date +%s) - CI_GATE_START ))

            case "$CI_RESULT" in
                success)
                    log "CI is green for ${DEPLOY_SHA:0:8}."
                    break
                    ;;
                failure)
                    fail "CI is RED for ${DEPLOY_SHA:0:8} — deploy refused (fail-closed). Fix CI or override with DEPLOY_SKIP_CI_CHECK=1."
                    ;;
                pending|missing)
                    if [ "$CI_ELAPSED" -ge "$CI_GATE_TIMEOUT" ]; then
                        fail "CI still '${CI_RESULT}' for ${DEPLOY_SHA:0:8} after ${CI_ELAPSED}s — deploy refused (fail-closed). Wait for CI, or override with DEPLOY_SKIP_CI_CHECK=1."
                    fi
                    warn "CI '${CI_RESULT}' for ${DEPLOY_SHA:0:8} — waiting (${CI_ELAPSED}s/${CI_GATE_TIMEOUT}s), re-checking in ${CI_POLL_INTERVAL}s..."
                    sleep "$CI_POLL_INTERVAL"
                    ;;
                api-error)
                    if [ "$CI_ELAPSED" -ge "$CI_GATE_TIMEOUT" ]; then
                        fail "CI API unreachable for ${DEPLOY_SHA:0:8} after ${CI_ELAPSED}s of retries — deploy refused (fail-closed). Retry later, or override with DEPLOY_SKIP_CI_CHECK=1."
                    fi
                    warn "CI API error for ${DEPLOY_SHA:0:8} (transient) — waiting (${CI_ELAPSED}s/${CI_GATE_TIMEOUT}s), re-checking in ${CI_POLL_INTERVAL}s..."
                    sleep "$CI_POLL_INTERVAL"
                    ;;
                *)
                    fail "Unexpected CI gate state '${CI_RESULT}' for ${DEPLOY_SHA:0:8} — deploy refused (fail-closed)."
                    ;;
            esac
        done
    else
        warn "gh CLI not found — skipping CI gate"
    fi
fi

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

# P1-34: do NOT touch nginx here. nginx keeps serving the OLD (working) app image
# throughout the entire app/horizon/scheduler rebuild below, so there is no hard
# outage while the new image builds and recreates. nginx is swapped LAST, in a
# single `up -d --force-recreate nginx` (Step 11) — that recreate is the ONLY
# downtime window, and it is on the order of a second. Tearing nginx down here
# (the previous behaviour) meant a full outage for the whole rebuild, and a total
# outage if any later step failed and left the deploy aborted.
log "Cleaning up stale app/horizon/scheduler containers (nginx left running)..."
for svc in app horizon scheduler; do
    docker rm -f "simplead-$svc" 2>/dev/null || true
done
# Also remove any prefixed orphan containers (e.g. abc123_simplead-horizon).
# nginx is deliberately excluded so the running proxy is never collected here.
docker ps -a --filter "status=created" --filter "status=exited" --format '{{.Names}}' \
    | grep -E 'simplead-(app|horizon|scheduler)' \
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

# ── Step 7: Run migrations (direct Postgres, bypassing PgBouncer) ────────────
# PgBouncer transaction pooling breaks Laravel's prepared-statement protocol on
# multi-statement DDL (confirmed 2026-07-10: create_tags_tables failed with
# SQLSTATE[25P02]). The pgsql_direct connection (config/database.php) points at
# the pgsql service directly via DB_DIRECT_HOST set in docker-compose.prod.yml.
# Env overrides via `exec -e` do NOT work — config is cached at container start.

log "Running database migrations (direct Postgres)..."
$COMPOSE exec app php artisan migrate --force --database=pgsql_direct

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
