#!/usr/bin/env bash
# Provision the lab for the P2 full-backup E2E (tests/Feature/Backup/V2/BackupRunnerE2ETest.php).
#
# Idempotent. Sets the simplead-backup HMAC key on spike-wp to the lab values the
# client uses, (re)deploys the plugin into the running container, and generates the
# deterministic file fixture. The DB fixture is the WP install's own tables.
#
# Usage: spike/orchestrator/backup-v2-e2e-setup.sh
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WP=sam_spike-spike-wp-1
PLUGIN_DST=/var/www/html/wp-content/plugins/simplead-backup

log(){ echo "[setup] $*"; }

log "setting simplead-backup HMAC key/secret to lab values"
docker exec "$WP" sh -lc '
  wp option update sam_backup_api_key "spikekey12345" --allow-root >/dev/null
  wp option update sam_backup_api_secret "spikesecret67890" --allow-root >/dev/null
  echo "  key set"
'

log "deploying plugin (full sync) into $WP"
# remove-then-copy: docker cp into an existing dir nests it (simplead-backup/simplead-backup).
docker exec "$WP" rm -rf "$PLUGIN_DST" >/dev/null 2>&1 || true
docker cp "$REPO/wordpress-plugin/simplead-backup" "$WP:$PLUGIN_DST" >/dev/null
docker exec "$WP" sh -lc "chown -R www-data:www-data $PLUGIN_DST 2>/dev/null || chown -R 1000:1000 $PLUGIN_DST"

log "generating deterministic file fixture in $WP"
docker cp "$REPO/spike/orchestrator/backup-v2-gen-fixture.php" "$WP:/tmp/gen-fixture.php"
docker exec "$WP" sh -lc 'php /tmp/gen-fixture.php /var/www/html'

log "verifying DB chunk-download + restore routes are registered"
docker exec "$WP" sh -lc 'wp eval "\$s=rest_get_server();\$r=array_keys(\$s->get_routes());foreach([\"/simplead-backup/v1/database/chunk-download\",\"/simplead-backup/v1/restore/prepare\",\"/simplead-backup/v1/restore/stage-chunk\",\"/simplead-backup/v1/restore/apply\",\"/simplead-backup/v1/restore/rollback\"] as \$route){echo (in_array(\$route,\$r)?\"  OK   \":\"  MISS \").\$route.\"\n\";}" --allow-root'

log "done — run: docker compose -p sam_lab -f lab/docker-compose.lab.yml exec -T lab-php sh -lc 'cd /work && php artisan test tests/Feature/Backup/V2'"
