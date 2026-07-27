#!/usr/bin/env bash
# Concurrent HTTP load probe: WORKERS parallel loops of timed GETs for DUR seconds.
URL="${URL:-http://127.0.0.1:18080/}"; DUR="${1:-20}"; WORKERS="${2:-6}"; OUT="${3:-/tmp/load.csv}"
: > "$OUT"
worker(){ local end=$(( $(date +%s)+DUR )); while [ "$(date +%s)" -lt "$end" ]; do
  read code t < <(curl -s -o /dev/null -w '%{http_code} %{time_total}\n' "$URL"); echo "$code,$t" >> "$OUT"; done; }
for w in $(seq 1 "$WORKERS"); do worker & done; wait
