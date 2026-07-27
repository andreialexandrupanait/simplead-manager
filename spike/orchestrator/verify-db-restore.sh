#!/usr/bin/env bash
# DB restore-oracle for the P2 full backup: run a real BackupRunner into MinIO, pull
# the DB segments back, and import them into a FRESH MySQL database on spike-db —
# proving a 0-error restore. lab-php has no MySQL driver, so the actual import runs
# on the spike stack (spike-mc for object access, spike-db for MySQL).
#
# Usage: spike/orchestrator/verify-db-restore.sh
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
LAB="docker compose -p sam_lab -f $REPO/lab/docker-compose.lab.yml"
SQL_OUT="$(mktemp)"
trap 'rm -f "$SQL_OUT"' EXIT

log(){ echo "[db-oracle] $*"; }

log "running a real full backup into MinIO (db-oracle/ prefix)"
META="$($LAB exec -T lab-php sh -lc 'cd /work && php spike/orchestrator/backup-v2-run-oracle-backup.php' 2>/dev/null | sed -n '/^{/,$p')"
echo "$META"

mapfile -t DB_KEYS < <(echo "$META" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("\n".join(d["db_keys"]))')
SRC_TABLES="$(echo "$META" | python3 -c 'import json,sys; print(json.load(sys.stdin)["source_table_count"])')"
SRC_ROWS="$(echo "$META" | python3 -c 'import json,sys; print(json.load(sys.stdin)["source_total_rows"])')"

log "pulling ${#DB_KEYS[@]} DB segment(s) from MinIO and gunzipping"
: > "$SQL_OUT"
for key in "${DB_KEYS[@]}"; do
  docker exec sam_spike-spike-mc-1 mc cat "spike/backups/${key}" | gunzip >> "$SQL_OUT"
  echo "  + ${key}"
done
log "assembled SQL: $(wc -c < "$SQL_OUT") bytes"

log "importing into a FRESH database verify_restore on spike-db (capturing errors)"
docker exec -i sam_spike-spike-db-1 mysql -uroot -pspikeroot \
  -e "DROP DATABASE IF EXISTS verify_restore; CREATE DATABASE verify_restore CHARACTER SET utf8mb4;" 2>/dev/null

set +e
IMPORT_RAW="$(docker exec -i sam_spike-spike-db-1 mysql -uroot -pspikeroot --show-warnings verify_restore < "$SQL_OUT" 2>&1 1>/dev/null)"
IMPORT_RC=$?
set -e
# Strip the benign "password on the command line" notice; anything left is a real error.
IMPORT_ERR="$(echo "$IMPORT_RAW" | grep -viE 'Using a password on the command line' || true)"

log "import exit code: $IMPORT_RC"
if [ -n "$IMPORT_ERR" ]; then
  echo "---- import stderr/warnings ----"
  echo "$IMPORT_ERR"
  echo "--------------------------------"
fi

DST_TABLES="$(docker exec sam_spike-spike-db-1 mysql -uroot -pspikeroot -N \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='verify_restore' AND table_type='BASE TABLE';" 2>/dev/null)"

log "RESULT: import_rc=$IMPORT_RC errors='${IMPORT_ERR:-none}' source_tables=$SRC_TABLES restored_tables=$DST_TABLES source_rows=$SRC_ROWS"

if [ "$IMPORT_RC" -eq 0 ] && [ -z "$IMPORT_ERR" ] && [ "$DST_TABLES" -ge "$SRC_TABLES" ]; then
  log "DB RESTORE-ORACLE PASS: 0 import errors, $DST_TABLES tables restored"
else
  log "DB RESTORE-ORACLE FAIL"
  exit 1
fi
