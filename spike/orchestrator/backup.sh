#!/usr/bin/env bash
# Spike orchestrator: drives the connector's chunked endpoints, pulls each chunk
# with delete=1 (pull-and-free so WP /tmp stays bounded), streams it to MinIO, and
# maintains a JSON cursor so the whole thing is resumable + idempotent.
#
# Reuses the REAL connector REST contract (prepare-init / prepare-chunk-exec /
# prepare-chunk-download) and the BackupManifestV3-style layout
#   {domain}/{type}-{ts}/{database/chunk_N.sql.gz | chunks/N.zip} + manifest.json + _COMPLETE
#
# Usage: backup.sh <type: db|files> <session_id> [resume]
set -uo pipefail

WP="${WP_URL:-http://127.0.0.1:18080}"
KEY="${SAM_KEY:-spikekey12345}"
SECRET="${SAM_SECRET:-spikesecret67890}"
BUCKET="${BUCKET:-spike/backups}"
DOMAIN="${DOMAIN:-spikewp.local}"
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
SCRATCH="$(cd "$(dirname "$0")/.." && pwd)/scratch"

TYPE="${1:?type db|files}"
SESSION="${2:?session_id}"
RESUME="${3:-}"

PREFIX="${DOMAIN}/${TYPE}-${SESSION}"
CURSOR="${SCRATCH}/cursor-${SESSION}-${TYPE}.json"
WORK="${SCRATCH}/${SESSION}-${TYPE}"
mkdir -p "$WORK"

log(){ echo "[$(date -u +%H:%M:%S)] $*" >&2; }
mc(){ $COMPOSE exec -T spike-mc mc "$@"; }

# --- HMAC-signed POST to the connector ---
sign_call(){ # route  body  [outfile] [dumpheaders]
  local route="$1" body="$2" outfile="${3:-}" hdr="${4:-}"
  local ts nonce sts sig
  ts=$(date +%s); nonce=$(openssl rand -hex 8)
  sts="POST|${route}|${ts}|${nonce}|${body}"
  sig=$(printf '%s' "$sts" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)
  local args=(-s -X POST "${WP}/wp-json${route}"
    -H "X-SAM-Key: ${KEY}" -H "X-SAM-Timestamp: ${ts}"
    -H "X-SAM-Nonce: ${nonce}" -H "X-SAM-Signature: ${sig}"
    -H "Content-Type: application/json" --data-binary "${body}")
  [ -n "$hdr" ] && args+=(-D "$hdr")
  if [ -n "$outfile" ]; then curl "${args[@]}" -o "$outfile"; else curl "${args[@]}"; fi
}

# --- cursor helpers (JSON) ---
[ -f "$CURSOR" ] && [ -n "$RESUME" ] || echo '{"prefix":"'"$PREFIX"'","type":"'"$TYPE"'","confirmed":{}}' > "$CURSOR"
is_confirmed(){ python3 -c "import json,sys;d=json.load(open('$CURSOR'));print('1' if '$1' in d['confirmed'] else '0')"; }
record(){ # index s3key sha256 size
  python3 -c "
import json
d=json.load(open('$CURSOR'))
d['confirmed']['$1']={'s3key':'$2','sha256':'$3','size':int('$4')}
json.dump(d,open('$CURSOR','w'))
"
}

# 1) plan chunks
log "prepare-init type=$TYPE"
INIT=$(sign_call "/simplead/v1/backup/prepare-init" "{\"type\":\"${TYPE}\"}")
TOKEN=$(echo "$INIT" | python3 -c "import json,sys;print(json.load(sys.stdin).get('token',''))")
TOTAL=$(echo "$INIT" | python3 -c "import json,sys;print(json.load(sys.stdin).get('total_chunks',0))")
if [ -z "$TOKEN" ] || [ "$TOTAL" = "0" ]; then log "init failed: $INIT"; exit 1; fi
log "token=${TOKEN:0:12}.. total_chunks=$TOTAL"
echo "$INIT" > "$WORK/chunks-plan.json"

ext="zip"; sub="chunks"; [ "$TYPE" = "db" ] && { ext="sql.gz"; sub="database"; }

# 2) per-chunk: exec -> pull(delete) -> upload -> verify -> confirm
for i in $(seq 0 $((TOTAL-1))); do
  if [ "$(is_confirmed "$i")" = "1" ]; then log "chunk $i already confirmed (resume skip)"; continue; fi
  body="{\"token\":\"${TOKEN}\",\"chunk_index\":${i}}"
  EXEC=$(sign_call "/simplead/v1/backup/prepare-chunk-exec" "$body")
  ok=$(echo "$EXEC" | python3 -c "import json,sys;print(json.load(sys.stdin).get('success',False))" 2>/dev/null)
  if [ "$ok" != "True" ]; then log "chunk $i exec FAILED: $EXEC"; exit 2; fi

  # Empty-chunk contract: connector returns chunk_size=0 (and deletes the zip) for a
  # chunk that matched no files (e.g. empty wp-content/upgrade). There is nothing to
  # download — prepare-chunk-download would 404. Skip it: do NOT pull, upload, or add
  # it to the manifest. (Production manager must do the same; a naive "non-empty body"
  # puller would otherwise store the 404 error JSON as if it were chunk data.)
  csize=$(echo "$EXEC" | python3 -c "import json,sys;print(json.load(sys.stdin).get('chunk_size',-1))" 2>/dev/null)
  if [ "$csize" = "0" ]; then log "chunk $i empty (0 files) — skip, not in manifest"; continue; fi

  local_file="$WORK/chunk_${i}.${ext}"
  dbody="{\"token\":\"${TOKEN}\",\"chunk_index\":${i},\"delete\":true}"
  sign_call "/simplead/v1/backup/prepare-chunk-download" "$dbody" "$local_file" "$WORK/hdr_${i}.txt"
  if [ ! -s "$local_file" ]; then log "chunk $i download empty"; exit 3; fi
  # Reject a non-2xx body masquerading as chunk data (defence in depth vs the 404-as-chunk trap).
  hstatus=$(awk 'toupper($1) ~ /^HTTP/ {code=$2} END{print code}' "$WORK/hdr_${i}.txt" 2>/dev/null)
  if [ -n "$hstatus" ] && [ "$hstatus" != "200" ]; then log "chunk $i download HTTP $hstatus (not chunk data)"; exit 3; fi
  sha=$(sha256sum "$local_file" | cut -d' ' -f1)
  sz=$(stat -c%s "$local_file")

  s3key="${PREFIX}/${sub}/${i}.${ext}"
  cp "$local_file" "$SCRATCH/.up.${i}.${ext}"          # into shared scratch for mc
  mc cp -q "/scratch/.up.${i}.${ext}" "${BUCKET}/${s3key}" >/dev/null 2>&1
  rm -f "$SCRATCH/.up.${i}.${ext}"
  # verify object exists with matching size (S3 = truth)
  rsz=$(mc stat --json "${BUCKET}/${s3key}" 2>/dev/null | python3 -c "import json,sys
try: print(json.load(sys.stdin).get('size',-1))
except: print(-1)")
  if [ "$rsz" != "$sz" ]; then log "chunk $i S3 size mismatch local=$sz remote=$rsz"; exit 4; fi
  record "$i" "$s3key" "$sha" "$sz"
  rm -f "$local_file"                                   # pull-and-free on manager side too
  log "chunk $i OK size=$sz sha=${sha:0:10}.. -> $s3key"
done

# 3) manifest + composite + completion marker  (verify BEFORE marking complete)
python3 - <<PY
import json,hashlib
d=json.load(open("$CURSOR"))
conf=d['confirmed']
idx=sorted(conf, key=lambda x:int(x))
parts=[conf[i]['sha256'] for i in idx]
composite=hashlib.sha256(''.join(parts).encode()).hexdigest()
manifest={
 'format':'multipart-v3-spike','type':'$TYPE','prefix':'$PREFIX',
 'total_chunks':len(idx),
 'objects':[{'index':int(i),'key':conf[i]['s3key'],'sha256':conf[i]['sha256'],'size':conf[i]['size']} for i in idx],
 'composite_checksum':composite,
}
open("$WORK/manifest.json","w").write(json.dumps(manifest,indent=0))
print("composite",composite,"objects",len(idx))
PY
cp "$WORK/manifest.json" "$SCRATCH/.manifest.${SESSION}.${TYPE}.json"
mc cp -q "/scratch/.manifest.${SESSION}.${TYPE}.json" "${BUCKET}/${PREFIX}/manifest.json" >/dev/null 2>&1
rm -f "$SCRATCH/.manifest.${SESSION}.${TYPE}.json"
printf 'complete' > "$SCRATCH/.complete"; mc cp -q "/scratch/.complete" "${BUCKET}/${PREFIX}/_COMPLETE" >/dev/null 2>&1; rm -f "$SCRATCH/.complete"
log "DONE type=$TYPE prefix=$PREFIX"
echo "$PREFIX"
