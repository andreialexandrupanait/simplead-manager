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

log "deploying plugin files into $WP"
docker cp "$REPO/wordpress-plugin/simplead-backup/simplead-backup.php" "$WP:$PLUGIN_DST/simplead-backup.php"
docker cp "$REPO/wordpress-plugin/simplead-backup/includes/endpoints/class-database-endpoint.php" \
          "$WP:$PLUGIN_DST/includes/endpoints/class-database-endpoint.php"
docker exec "$WP" sh -lc "chown -R 1000:1000 $PLUGIN_DST"

log "generating deterministic file fixture in $WP"
docker cp "$REPO/spike/orchestrator/backup-v2-gen-fixture.php" "$WP:/tmp/gen-fixture.php"
docker exec "$WP" sh -lc 'php /tmp/gen-fixture.php /var/www/html'

log "verifying the new DB chunk-download route is registered"
docker exec "$WP" sh -lc 'wp eval "\$s=rest_get_server();echo in_array(\"/simplead-backup/v1/database/chunk-download\", array_keys(\$s->get_routes()))?\"  route OK\n\":\"  route MISSING\n\";" --allow-root'

log "done — run: docker compose -p sam_lab -f lab/docker-compose.lab.yml exec -T lab-php sh -lc 'cd /work && php artisan test tests/Feature/Backup/V2'"
