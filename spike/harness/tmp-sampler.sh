#!/usr/bin/env bash
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
OUT="$1"; N="${2:-2000}"; echo "epoch,wp_tmp_bytes" > "$OUT"
for i in $(seq 1 "$N"); do
  b=$($COMPOSE exec -T spike-wp sh -c 'du -sb /tmp/sam_prepared 2>/dev/null | cut -f1' 2>/dev/null); b=${b:-0}
  echo "$(date +%s.%N),$b" >> "$OUT"
  # stop when marker file appears
  [ -f /tmp/med-backup-done ] && break
done
