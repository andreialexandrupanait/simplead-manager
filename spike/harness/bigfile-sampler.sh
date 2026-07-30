#!/usr/bin/env bash
# High-resolution sampler for the single-big-file round. Samples INSIDE the WP
# container so we separate *real PHP RSS* from reclaimable page cache (docker stats
# MemUsage counts the 2 GB file's page cache and would wildly overstate memory).
#
# Columns:
#   epoch              wall clock
#   tmp_total_bytes    du of /tmp/sam_prepared  (the temp-cap metric)
#   tmp_biggest_bytes  largest single file under /tmp/sam_prepared (current chunk)
#   php_rss_kb         max RSS across apache2/php workers (the "does it buffer?" metric)
#   cg_anon_bytes      cgroup anon memory (real RSS, excludes file cache)
#   cg_file_bytes      cgroup file cache (reclaimable — expected to track the big file)
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
OUT="${1:-/tmp/bigfile-sampler.csv}"
N="${2:-4000}"
DONE_MARKER="${3:-/tmp/bigfile-backup-done}"
echo "epoch,tmp_total_bytes,tmp_biggest_bytes,php_rss_kb,cg_anon_bytes,cg_file_bytes" > "$OUT"
for i in $(seq 1 "$N"); do
  line=$($COMPOSE exec -T spike-wp sh -c '
    T=$(du -sb /tmp/sam_prepared 2>/dev/null | cut -f1); T=${T:-0}
    B=$(find /tmp/sam_prepared -type f -printf "%s\n" 2>/dev/null | sort -rn | head -1); B=${B:-0}
    R=$(ps -eo rss,comm 2>/dev/null | awk "/apache2|php/ {if(\$1>m)m=\$1} END{print m+0}")
    ANON=$(awk "/^anon /{print \$2}" /sys/fs/cgroup/memory.stat 2>/dev/null); ANON=${ANON:-0}
    FILE=$(awk "/^file /{print \$2}" /sys/fs/cgroup/memory.stat 2>/dev/null); FILE=${FILE:-0}
    echo "$T,$B,$R,$ANON,$FILE"
  ' 2>/dev/null)
  echo "$(date +%s),${line:-0,0,0,0,0}" >> "$OUT"
  [ -f "$DONE_MARKER" ] && break
done
