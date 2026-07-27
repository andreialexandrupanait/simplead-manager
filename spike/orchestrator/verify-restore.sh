#!/usr/bin/env bash
# Verify (Level-B) + restore-oracle: download every chunk of a files backup, recompute
# the composite checksum, extract, and diff each restored file's sha256 against the
# ground-truth manifest. Proves "completed => verified" and "restore reproduces content".
set -uo pipefail
PREFIX="${1:?prefix e.g. spikewp.local/files-test2}"
GROUND="${2:?ground-truth jsonl}"          # host path to ground-truth-*.jsonl
UPLOAD_PREFIX="${3:-wp-content/uploads/spike}"  # where fixtures live inside the zip
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
SCRATCH="$(cd "$(dirname "$0")/.." && pwd)/scratch"
R="$SCRATCH/restore-$(basename "$PREFIX")"
rm -rf "$R"; mkdir -p "$R/zips" "$R/tree"

# 1) download manifest + all chunks.
# Iterate the manifest's ACTUAL object indices (not seq 0..N-1): empty chunks are
# skipped by the manager, so indices are non-contiguous (e.g. no 1, no 12).
$COMPOSE exec -T spike-mc mc cp -q "spike/backups/$PREFIX/manifest.json" "/scratch/.m.json" >/dev/null 2>&1
cp "$SCRATCH/.m.json" "$R/manifest.json"; rm -f "$SCRATCH/.m.json"
IDXS=$(python3 -c "import json;print(' '.join(str(o['index']) for o in json.load(open('$R/manifest.json'))['objects']))")
for i in $IDXS; do
  $COMPOSE exec -T spike-mc mc cp -q "spike/backups/$PREFIX/chunks/$i.zip" "/scratch/.c$i.zip" >/dev/null 2>&1
  cp "$SCRATCH/.c$i.zip" "$R/zips/$i.zip"; rm -f "$SCRATCH/.c$i.zip"
done

# 2) recompute composite from downloaded objects, compare to manifest
python3 - "$R" <<'PY'
import json,hashlib,sys,os
R=sys.argv[1]
m=json.load(open(f"{R}/manifest.json"))
parts=[]
for o in m['objects']:
    h=hashlib.sha256(open(f"{R}/zips/{o['index']}.zip","rb").read()).hexdigest()
    parts.append(h)
    assert h==o['sha256'], f"chunk {o['index']} sha mismatch"
comp=hashlib.sha256(''.join(parts).encode()).hexdigest()
print("composite_recomputed",comp,"manifest",m['composite_checksum'],"MATCH" if comp==m['composite_checksum'] else "FAIL")
PY

# 3) extract all chunk zips into one tree (python zipfile; host has no unzip)
python3 - "$R" <<'PY'
import zipfile,sys,glob,os
R=sys.argv[1]
for z in sorted(glob.glob(f"{R}/zips/*.zip")):
    try:
        zipfile.ZipFile(z).extractall(f"{R}/tree")
    except Exception as e:
        print("extract error",z,e)
PY

# 4) restore-oracle: every ground-truth file present with matching sha256
python3 - "$R" "$GROUND" "$UPLOAD_PREFIX" <<'PY'
import json,hashlib,sys,os
R,ground,pref=sys.argv[1],sys.argv[2],sys.argv[3]
ok=miss=bad=0
for line in open(ground):
    e=json.loads(line); p=os.path.join(R,"tree",pref,e['p'])
    if not os.path.isfile(p): miss+=1; continue
    h=hashlib.sha256(open(p,"rb").read()).hexdigest()
    if h==e['sha256']: ok+=1
    else: bad+=1
print(f"restore-oracle: ok={ok} missing={miss} mismatch={bad}")
print("RESULT:", "PASS" if miss==0 and bad==0 else "FAIL")
PY
