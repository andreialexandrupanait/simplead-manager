#!/usr/bin/env bash
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
OUT="${1:-/tmp/sampler.csv}"
echo "epoch,wp_tmp_bytes,wp_cpu,wp_mem" > "$OUT"
for n in $(seq 1 "${2:-120}"); do
  tmpb=$($COMPOSE exec -T spike-wp sh -c 'du -sb /tmp/sam_prepared 2>/dev/null | cut -f1' 2>/dev/null); tmpb=${tmpb:-0}
  stats=$(docker stats --no-stream --format '{{.CPUPerc}},{{.MemUsage}}' sam_spike-spike-wp-1 2>/dev/null | tr -d '%' | cut -d',' -f1,2)
  echo "$(date +%s),${tmpb},${stats}" >> "$OUT"
  sleep 1
done
