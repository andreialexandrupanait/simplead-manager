#!/usr/bin/env bash
# ============================================================================================
# simplead-backup — FILE inventory + exclusions + chunking proof (P2, files half).
#
# Drives the PRODUCTION engine classes (SAM_Backup_Exclusions / SAM_Backup_Inventory /
# SAM_Backup_File_Chunker) via tests/files-harness.php inside spike-wp — the same code the REST
# endpoints call. Validates, on an INCOMPRESSIBLE fixture (gen-files.php small profile + one big
# file over the chunk threshold):
#
#   1. Exclusions: injected excluded files (cache/*.tmp, debug.log, node_modules, .git, error_log,
#      custom folder) never enter the inventory/plan; exclusion_policy_hash is deterministic.
#   2. Inventory: count/bytes correct and identical across two runs (determinism).
#   3. Chunking: the big file (> threshold) becomes its OWN chunk; every chunk is non-empty;
#      temp (/tmp/sam_backup session files dir) never exceeds the largest single chunk because
#      each chunk is pulled-and-freed before the next; each chunk carries a sha256.
#   4. Benchmark: STORE vs DEFLATE on the incompressible big file (informs DECISION-LOG D-004).
#   5. Restore-oracle: extract all pulled chunks and re-check every file's sha256 vs ground truth
#      (incl. the big file) → 0 missing / 0 mismatch, and 0 excluded files leaked.
#
# PASS requires: deterministic exclusions, big-file = own chunk, temp bounded, oracle 0/0.
# No shell_exec anywhere in the plugin; this test script is lab tooling only.
# ============================================================================================
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
PLUGIN_SRC="$REPO/wordpress-plugin/simplead-backup"
GENFILES_SRC="$REPO/spike/fixtures/gen-files.php"
WP="sam_spike-spike-wp-1"

echo "### syncing plugin + fixture generator into spike-wp ###"
docker exec "$WP" rm -rf /var/www/html/wp-content/plugins/simplead-backup >/dev/null 2>&1
docker cp "$PLUGIN_SRC" "$WP:/var/www/html/wp-content/plugins/simplead-backup" >/dev/null
docker cp "$GENFILES_SRC" "$WP:/tmp/gen-files.php" >/dev/null

# ------------------------------------------------------------------------------------------
# Everything else runs INSIDE the container (has php, sha256sum, dd, du; no python/unzip).
# ------------------------------------------------------------------------------------------
docker exec -i "$WP" bash -s <<'INSIDE'
set -uo pipefail

PLUG=/var/www/html/wp-content/plugins/simplead-backup
HARNESS="$PLUG/tests/files-harness.php"
ORACLE="$PLUG/tests/files-oracle.php"
FIX=/var/www/html/wp-content/uploads/sb-test
GT=/tmp/sb-test-gt.jsonl
RULES=/tmp/sb-rules.json
SESS=/tmp/sam_backup/sessions/filestest
FILES_DIR="$SESS/files"
PULLED=/tmp/sb-pulled
RESTORE=/tmp/sb-restore
THRESHOLD=$((64*1024*1024))      # 64 MiB chunk threshold
BIGSIZE=$((300*1024*1024))       # 300 MB single incompressible file (> threshold → own chunk)

FAIL=0
jget(){ php -r '$d=json_decode(file_get_contents($argv[1]),true);$k=$argv[2];echo (is_array($d)&&array_key_exists($k,$d))?(is_scalar($d[$k])?$d[$k]:json_encode($d[$k])):"";' "$1" "$2"; }

echo ""
echo "=== 1. GENERATE INCOMPRESSIBLE FIXTURE (gen-files.php small profile) ==="
rm -rf "$FIX" "$SESS" "$PULLED" "$RESTORE" "$GT" /tmp/store.zip /tmp/deflate.zip
mkdir -p "$FIX" "$PULLED" "$RESTORE"
php /tmp/gen-files.php --profile=small --seed=42 --root="$FIX" --out="$GT"

echo "--- add one big incompressible file (${BIGSIZE} bytes, > threshold) ---"
mkdir -p "$FIX/uploads/2025/01"
dd if=/dev/urandom of="$FIX/uploads/2025/01/huge_big.bin" bs=1048576 count=$((BIGSIZE/1048576)) status=none
BIG_SHA=$(sha256sum "$FIX/uploads/2025/01/huge_big.bin" | cut -d' ' -f1)
BIG_ACTUAL=$(stat -c%s "$FIX/uploads/2025/01/huge_big.bin")
# append big file to ground truth (rel path under fixture root)
php -r '$p="uploads/2025/01/huge_big.bin";$s=(int)$argv[1];$h=$argv[2];echo json_encode(["p"=>$p,"s"=>$s,"sha256"=>$h])."\n";' "$BIG_ACTUAL" "$BIG_SHA" >> "$GT"
GT_COUNT=$(wc -l < "$GT")
echo "    fixture files (ground truth entries) = $GT_COUNT ; big_sha=$BIG_SHA"

echo ""
echo "=== 2. INJECT FILES THAT MUST BE EXCLUDED (not in ground truth) ==="
mkdir -p "$FIX/cache" "$FIX/node_modules/pkg" "$FIX/.git/objects/ab" "$FIX/private"
echo "tmpjunk"      > "$FIX/cache/app.tmp"              # default: ext tmp
echo "debug noise"  > "$FIX/debug.log"                 # default: ext log + **/debug.log
echo "module"       > "$FIX/node_modules/pkg/index.js" # default: **/node_modules/**
echo "gitobj"       > "$FIX/.git/objects/ab/cdef"      # default: **/.git/**
echo "phperr"       > "$FIX/error_log"                 # default: **/error_log
echo "secret"       > "$FIX/private/secret.txt"        # custom rule: folder=private
FORBIDDEN="cache/app.tmp,debug.log,node_modules/pkg/index.js,.git/objects/ab/cdef,error_log,private/secret.txt"
EXPECT_EXCLUDED=6
# custom rule set (defaults auto-merged by the engine)
echo '[{"type":"folder","value":"private"}]' > "$RULES"

echo ""
echo "=== 3. INVENTORY x2 (determinism) + EXCLUSION CHECK ==="
S1=/tmp/sb-plan1; S2=/tmp/sb-plan2
rm -rf "$S1" "$S2"
php "$HARNESS" --op=plan --root="$FIX" --rules="$RULES" --include-defaults=1 --threshold="$THRESHOLD" --compression=store --session="$S1" > /tmp/inv1.json
php "$HARNESS" --op=plan --root="$FIX" --rules="$RULES" --include-defaults=1 --threshold="$THRESHOLD" --compression=store --session="$S2" > /tmp/inv2.json

H1=$(jget /tmp/inv1.json exclusion_policy_hash); H2=$(jget /tmp/inv2.json exclusion_policy_hash)
TF1=$(jget /tmp/inv1.json total_files);          TF2=$(jget /tmp/inv2.json total_files)
TB1=$(jget /tmp/inv1.json total_bytes);          TB2=$(jget /tmp/inv2.json total_bytes)
EX1=$(jget /tmp/inv1.json excluded_files)
CHUNK_COUNT=$(jget /tmp/inv1.json chunk_count)
LARGEST=$(jget /tmp/inv1.json largest_chunk)
echo "    run1: files=$TF1 bytes=$TB1 excluded=$EX1 chunks=$CHUNK_COUNT largest_chunk=$LARGEST hash=$H1"
echo "    run2: files=$TF2 bytes=$TB2 hash=$H2"

[ "$H1" = "$H2" ] && [ -n "$H1" ]        || { echo "   !! exclusion_policy_hash NOT deterministic"; FAIL=1; }
[ "$TF1" = "$TF2" ] && [ "$TB1" = "$TB2" ] || { echo "   !! inventory NOT deterministic"; FAIL=1; }
[ "$TF1" = "$GT_COUNT" ]                  || { echo "   !! total_files($TF1) != ground truth($GT_COUNT)"; FAIL=1; }
[ "$EX1" = "$EXPECT_EXCLUDED" ]           || { echo "   !! excluded_files($EX1) != expected($EXPECT_EXCLUDED)"; FAIL=1; }

# excluded paths must NOT appear anywhere in the persisted plan
LEAK=0
for p in cache/app.tmp debug.log node_modules/pkg/index.js .git/objects/ab/cdef error_log private/secret.txt; do
  if grep -qF "\"$p\"" "$S1/files/plan.json"; then echo "   !! excluded path leaked into plan: $p"; LEAK=1; fi
done
[ "$LEAK" = "0" ] && echo "    exclusions OK: 0 excluded paths in plan; excluded_files=$EX1 hash stable" || FAIL=1

# every chunk non-empty (empty-chunk contract at plan level) + exactly one oversize (the big file)
NONEMPTY_OK=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);$e=0;$o=0;foreach($d["chunks"] as $c){if($c["file_count"]<1)$e++;if($c["oversize"])$o++;}echo "$e:$o";' /tmp/inv1.json)
echo "    plan check (empty_chunks:oversize_chunks) = $NONEMPTY_OK (expect 0:1)"
[ "$NONEMPTY_OK" = "0:1" ] || { echo "   !! expected 0 empty chunks and exactly 1 oversize chunk"; FAIL=1; }

echo ""
echo "=== 4. BENCHMARK: STORE vs DEFLATE on the incompressible big file ==="
php -r '
$f=$argv[1];
foreach(["STORE"=>ZipArchive::CM_STORE,"DEFLATE"=>ZipArchive::CM_DEFLATE] as $name=>$cm){
  $out="/tmp/".strtolower($name).".zip";
  $t=microtime(true);$z=new ZipArchive();$z->open($out,ZipArchive::CREATE|ZipArchive::OVERWRITE);
  $z->addFile($f,"b");$z->setCompressionName("b",$cm);$z->close();
  $dt=microtime(true)-$t;$sz=filesize($out);$src=filesize($f);
  printf("    %-8s time=%6.2fs out=%d bytes ratio=%.4f\n",$name,$dt,$sz,$sz/$src);
}' "$FIX/uploads/2025/01/huge_big.bin"

echo ""
echo "=== 5. CHUNK EXEC + PULL-AND-FREE (temp bound) ==="
rm -rf "$FILES_DIR"; mkdir -p "$FILES_DIR"
# use plan session S1 for exec
cp "$S1/files/plan.json" "$FILES_DIR/plan.json"
TEMP_PEAK=0
BIG_CHUNK_SIZE=0
i=0
while [ "$i" -lt "$CHUNK_COUNT" ]; do
  php "$HARNESS" --op=exec --session="$SESS" --chunk="$i" > /tmp/exec_$i.json 2>/tmp/exec_$i.err || { echo "   !! exec chunk $i failed: $(cat /tmp/exec_$i.err)"; FAIL=1; break; }
  CSZ=$(jget /tmp/exec_$i.json size); CEMPTY=$(jget /tmp/exec_$i.json empty); CSHA=$(jget /tmp/exec_$i.json sha256)
  # temp peak = du of files_dir right after exec (holds this chunk's zip + tiny fragments)
  DU=$(du -sb "$FILES_DIR" 2>/dev/null | cut -f1)
  [ "$DU" -gt "$TEMP_PEAK" ] && TEMP_PEAK=$DU
  [ -z "$CSHA" ] || [ "$CSHA" = "null" ] && { : ; }
  if [ "$CEMPTY" = "1" ] || [ "$CEMPTY" = "true" ]; then
    echo "   !! chunk $i unexpectedly empty"; FAIL=1
  else
    [ -n "$CSHA" ] && [ "$CSHA" != "null" ] || { echo "   !! chunk $i has no sha256"; FAIL=1; }
    [ "$CSZ" -gt "$BIG_CHUNK_SIZE" ] && BIG_CHUNK_SIZE=$CSZ
    # pull-and-free: move the zip out of session temp (simulates chunk-download delete=1)
    mv "$FILES_DIR/chunk_$i.zip" "$PULLED/chunk_$i.zip"
  fi
  i=$((i+1))
done
echo "    chunks executed=$CHUNK_COUNT temp_peak=$TEMP_PEAK bytes largest_chunk_zip=$BIG_CHUNK_SIZE plan_largest=$LARGEST"
# temp must be bounded by the largest single chunk (+ small slack for manifest fragments)
SLACK=$((2*1024*1024))
if [ "$TEMP_PEAK" -le $((BIG_CHUNK_SIZE + SLACK)) ]; then
  echo "    temp bounded OK: peak($TEMP_PEAK) <= largest_chunk($BIG_CHUNK_SIZE) + slack"
else
  echo "   !! temp NOT bounded: peak($TEMP_PEAK) > largest_chunk($BIG_CHUNK_SIZE)+slack"; FAIL=1
fi
# big file must be its own chunk ~ its size (STORE → ≈ file size)
if [ "$BIG_CHUNK_SIZE" -ge "$BIG_ACTUAL" ]; then
  echo "    big-file=own-chunk OK: chunk_zip($BIG_CHUNK_SIZE) >= big_file($BIG_ACTUAL)"
else
  echo "   !! big file chunk smaller than the file — unexpected"; FAIL=1
fi

echo ""
echo "=== 6. RESTORE-ORACLE (extract pulled chunks, re-check every sha256 vs ground truth) ==="
php "$ORACLE" --zips="$PULLED" --ground="$GT" --extract="$RESTORE" --forbidden="$FORBIDDEN" > /tmp/oracle.json
ORES=$?
cat /tmp/oracle.json
[ "$ORES" = "0" ] || { echo "   !! restore-oracle FAILED"; FAIL=1; }

echo ""
echo "============================================================"
if [ "$FAIL" = "0" ]; then
  echo "OVERALL: PASS — deterministic exclusions, big-file=own-chunk, temp bounded, oracle 0/0"
  exit 0
else
  echo "OVERALL: FAIL"
  exit 1
fi
INSIDE
RC=$?
echo ""
echo "### files-test.sh exit=$RC ###"
exit $RC
