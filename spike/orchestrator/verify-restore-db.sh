#!/usr/bin/env bash
# DB restore-oracle for the V2 restore engine: proves SAM_Backup_Restore_Engine's staged DB import
# (sambk_stg_* + atomic multi-table RENAME swap, kept as sambk_old_* until commit/rollback) end to
# end against a REAL MariaDB, by running tests/restore-harness.php INSIDE spike-wp (which has mysqli)
# against a DEDICATED scratch database on spike-db. Never touches the real WP install.
#
# Scenarios proven (all must PASS): A) full DB round-trip, B) selective table restore, C) rollback.
#
# Usage: spike/orchestrator/verify-restore-db.sh
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WP=sam_spike-spike-wp-1
PLUGIN_DST=/var/www/html/wp-content/plugins/simplead-backup

log(){ echo "[restore-db-oracle] $*"; }

log "syncing plugin into spike-wp"
docker exec "$WP" rm -rf "$PLUGIN_DST" >/dev/null 2>&1 || true
docker cp "$REPO/wordpress-plugin/simplead-backup" "$WP:$PLUGIN_DST" >/dev/null

log "running restore harness (spike-wp php → spike-db)"
OUT="$(docker exec "$WP" sh -lc 'php '"$PLUGIN_DST"'/tests/restore-harness.php --host=spike-db --port=3306 --user=root --pass=spikeroot --name=sam_restore_test --work=/tmp/rrh 2>&1')"
echo "$OUT"

if echo "$OUT" | grep -q '"ok":true'; then
  log "DB RESTORE-ORACLE PASS: full round-trip + selective + rollback all green"
else
  log "DB RESTORE-ORACLE FAIL"
  exit 1
fi
