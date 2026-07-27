#!/usr/bin/env bash
# ============================================================================================
# simplead-backup — DB consistency proof for the NEW consistent dumper.
#
# Reproduces the spike finding (docs/backup/spike/DATABASE-CONSISTENCY.md) for the NEW code,
# on BOTH MySQL 8 and MariaDB 11, over a Multisite (wp_2_*, wp_3_*) + WooCommerce/HPOS schema
# (wp_wc_orders, wp_woocommerce_order_items) that also contains a binary/BLOB table (wp_2_media).
#
# For each engine, under a concurrent transactional writer:
#   A) PAGED, NO-TRANSACTION dump (per-page fresh connection) — the connector's torn model.
#      Oracle: orphan postmeta (meta.post_id with no post) + orphan order_items. Expect > 0.
#   B) The NEW SAM_Backup_Consistent_Dumper (single connection, START TRANSACTION WITH
#      CONSISTENT SNAPSHOT) — driven via tests/dump-harness.php inside spike-wp. The restorable
#      SQL is imported into a verify DB and the SAME orphan oracle is run. Target: 0 orphans.
#      Also verifies binary/BLOB bytes round-trip exactly (CRC32 over wp_2_media).
#
# PASS requires: consistent dump = 0 orphans on BOTH engines. (Paged > 0 is the contrast.)
# ============================================================================================
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
COMPOSE="docker compose -p sam_spike -f $REPO/spike/docker-compose.spike.yml"
PLUGIN_SRC="$REPO/wordpress-plugin/simplead-backup"
WP_CONTAINER="sam_spike-spike-wp-1"
SEED_POSTS=5000
SEED_ORDERS=5000
WRITE_SECS=20

OVERALL_FAIL=0

echo "### syncing plugin into spike-wp ###"
# remove-then-copy: docker cp into an existing dir nests it (simplead-backup/simplead-backup)
docker exec "$WP_CONTAINER" rm -rf /var/www/html/wp-content/plugins/simplead-backup >/dev/null 2>&1
docker cp "$PLUGIN_SRC" "$WP_CONTAINER:/var/www/html/wp-content/plugins/simplead-backup" >/dev/null

# ---- python oracle for the PAGED marker file --------------------------------------------
analyze_paged() {
  python3 - "$1" <<'PY'
import sys
posts=set(); meta=[]; orders=set(); items=[]
for l in open(sys.argv[1]):
    l=l.strip()
    if not l: continue
    t=l[0]
    if t=='O': posts.add(l[2:])        # O:site:ID
    elif t=='P': meta.append(l[2:])    # P:site:post_id
    elif t=='C': orders.add(l[2:])     # C:order_id
    elif t=='L': items.append(l[2:])   # L:order_id
om=sum(1 for x in meta if x not in posts)
oi=sum(1 for x in items if x not in orders)
print(f"posts={len(posts)} postmeta={len(meta)} orphan_meta={om} orders={len(orders)} items={len(items)} orphan_items={oi} orphans_total={om+oi}")
PY
}

run_engine() {
  local SVC="$1" CLIENT="$2" LABEL="$3"
  echo ""
  echo "============================================================"
  echo "===  ENGINE: $LABEL  ($SVC / $CLIENT)"
  echo "============================================================"

  # fresh connection per call (this is what makes the paged method torn)
  SQL(){  $COMPOSE exec -T "$SVC" "$CLIENT" -uroot -pspikeroot -N -e "$1" wordpress 2>/dev/null; }
  SCAL(){ $COMPOSE exec -T "$SVC" "$CLIENT" -uroot -pspikeroot -N -s -e "$1" 2>/dev/null; }
  PIPE(){ $COMPOSE exec -T "$SVC" "$CLIENT" -uroot -pspikeroot wordpress 2>/dev/null; }

  echo "--- server: $(SCAL 'SELECT VERSION();')"

  echo "--- (re)create Multisite + Woo/HPOS + binary schema ---"
  PIPE <<'SQL'
DROP TABLE IF EXISTS wp_2_postmeta; DROP TABLE IF EXISTS wp_2_posts; DROP TABLE IF EXISTS wp_2_media;
DROP TABLE IF EXISTS wp_3_postmeta; DROP TABLE IF EXISTS wp_3_posts;
DROP TABLE IF EXISTS wp_woocommerce_order_items; DROP TABLE IF EXISTS wp_wc_orders;
CREATE TABLE wp_2_posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title VARCHAR(255) NOT NULL DEFAULT '', post_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE wp_2_postmeta (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) DEFAULT NULL, meta_value LONGTEXT, KEY post_id (post_id)) ENGINE=InnoDB;
CREATE TABLE wp_3_posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title VARCHAR(255) NOT NULL DEFAULT '', post_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE wp_3_postmeta (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) DEFAULT NULL, meta_value LONGTEXT, KEY post_id (post_id)) ENGINE=InnoDB;
CREATE TABLE wp_2_media (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL DEFAULT '', data VARBINARY(255) NOT NULL, blob_data BLOB NULL) ENGINE=InnoDB;
CREATE TABLE wp_wc_orders (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20) NOT NULL DEFAULT 'processing', total DECIMAL(12,2) NOT NULL DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE wp_woocommerce_order_items (order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL DEFAULT 0, order_item_name VARCHAR(255) NOT NULL DEFAULT '', KEY order_id (order_id)) ENGINE=InnoDB;
SQL

  echo "--- seed baseline (posts=$SEED_POSTS/site, orders=$SEED_ORDERS, +binary media) ---"
  python3 - "$SEED_POSTS" "$SEED_ORDERS" <<'PY' | PIPE
import sys, os
P=int(sys.argv[1]); O=int(sys.argv[2])
for N in (2,3):
    vals=",".join("('post %d')"%i for i in range(1,P+1))
    print(f"INSERT INTO wp_{N}_posts(post_title) VALUES {vals};")
    print(f"INSERT INTO wp_{N}_postmeta(post_id,meta_key,meta_value) SELECT ID,'_k',CONCAT('v',ID) FROM wp_{N}_posts;")
    print(f"INSERT INTO wp_{N}_postmeta(post_id,meta_key,meta_value) SELECT ID,'_k2',CONCAT('w',ID) FROM wp_{N}_posts;")
ovals=",".join("('processing',%d.50)"%(i%97+1) for i in range(1,O+1))
print(f"INSERT INTO wp_wc_orders(status,total) VALUES {ovals};")
print("INSERT INTO wp_woocommerce_order_items(order_id,order_item_name) SELECT id,'lineA' FROM wp_wc_orders;")
print("INSERT INTO wp_woocommerce_order_items(order_id,order_item_name) SELECT id,'lineB' FROM wp_wc_orders;")
# binary media: nasty bytes (NUL, 0xFF, quote, dquote, backslash) + random -> proves hex round-trip
for i in range(1,201):
    b = bytes([0x00,0xFF,0x27,0x22,0x5C]) + os.urandom(27)
    print(f"INSERT INTO wp_2_media(name,data,blob_data) VALUES ('m{i}',0x{b.hex()},0x{os.urandom(40).hex()});")
PY

  local base2 base3 baseO
  base2=$(SCAL "SELECT COUNT(*) FROM wordpress.wp_2_posts;")
  baseO=$(SCAL "SELECT COUNT(*) FROM wordpress.wp_wc_orders;")
  echo "    baseline: wp_2_posts=$base2 wp_wc_orders=$baseO media=$(SCAL 'SELECT COUNT(*) FROM wordpress.wp_2_media;')"

  # concurrent writer: post+2meta on both sites + order+2items, all transactional
  writer(){ local end=$(( $(date +%s)+${1} ))
    while [ "$(date +%s)" -lt "$end" ]; do
      $COMPOSE exec -T "$SVC" "$CLIENT" -uroot -pspikeroot wordpress >/dev/null 2>&1 <<S
START TRANSACTION;
INSERT INTO wp_2_posts(post_title) VALUES('live'); SET @p2=LAST_INSERT_ID();
INSERT INTO wp_2_postmeta(post_id,meta_key,meta_value) VALUES(@p2,'_k','x'),(@p2,'_k2','y');
INSERT INTO wp_3_posts(post_title) VALUES('live'); SET @p3=LAST_INSERT_ID();
INSERT INTO wp_3_postmeta(post_id,meta_key,meta_value) VALUES(@p3,'_k','x'),(@p3,'_k2','y');
INSERT INTO wp_wc_orders(status,total) VALUES('processing',9.90); SET @o=LAST_INSERT_ID();
INSERT INTO wp_woocommerce_order_items(order_id,order_item_name) VALUES(@o,'lineA'),(@o,'lineB');
COMMIT;
S
    done; }

  # ---- A) PAGED, NO-TXN (per-page fresh connection) --------------------------------------
  dump_paged(){ local out="$1"; : > "$out"
    local N off r
    for N in 2 3; do off=0
      while :; do r=$(SQL "SELECT CONCAT('O:${N}:',ID) FROM wp_${N}_posts ORDER BY ID LIMIT $off,2000"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+2000)); done
    done
    for N in 2 3; do off=0
      while :; do r=$(SQL "SELECT CONCAT('P:${N}:',post_id) FROM wp_${N}_postmeta ORDER BY meta_id LIMIT $off,2000"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+2000)); done
    done
    off=0; while :; do r=$(SQL "SELECT CONCAT('C:',id) FROM wp_wc_orders ORDER BY id LIMIT $off,2000"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+2000)); done
    off=0; while :; do r=$(SQL "SELECT CONCAT('L:',order_id) FROM wp_woocommerce_order_items ORDER BY order_item_id LIMIT $off,2000"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+2000)); done
  }

  echo ""
  echo "--- A) PAGED no-transaction dump under concurrent writes (connector model / contrast) ---"
  local PAGED="${TMPDIR:-/tmp}/sam-backup-$SVC-paged.txt"
  writer "$WRITE_SECS" & local WP=$!
  sleep 2
  dump_paged "$PAGED"
  local A_RES; A_RES=$(analyze_paged "$PAGED")
  echo "A (paged):     $A_RES"
  wait $WP 2>/dev/null

  # ---- B) NEW consistent dumper (single connection, consistent snapshot) ------------------
  echo ""
  echo "--- B) NEW consistent dumper under concurrent writes (target: 0 orphans) ---"
  local OUTDIR="/tmp/sam_backup_test/$SVC/db"
  local RESTORE="/tmp/sam_backup_test/$SVC/restore.sql"
  writer "$WRITE_SECS" & WP=$!
  sleep 2
  local MANIFEST
  MANIFEST=$($COMPOSE exec -T spike-wp bash -lc "rm -rf /tmp/sam_backup_test/$SVC; mkdir -p $OUTDIR; php /var/www/html/wp-content/plugins/simplead-backup/tests/dump-harness.php --host=$SVC --port=3306 --user=root --pass=spikeroot --name=wordpress --out=$OUTDIR --restore-out=$RESTORE --budget=300 --segment=262144 2>/tmp/sam_backup_test_err")
  local HARNESS_ERR; HARNESS_ERR=$($COMPOSE exec -T spike-wp cat /tmp/sam_backup_test_err 2>/dev/null)
  wait $WP 2>/dev/null

  if [ -z "$MANIFEST" ]; then
    echo "!! harness produced no manifest. stderr: $HARNESS_ERR"
    OVERALL_FAIL=1; return
  fi
  echo "    dumper manifest (trimmed):"
  echo "$MANIFEST" | python3 -c 'import sys,json; d=json.load(sys.stdin); print("      done=%s consistent=%s engine=%s server=%s tables=%s rows=%s segments=%s elapsed=%ss"%(d["done"],d["consistent"],d["engine"],d["server_version"],d["table_count"],d["total_rows"],d["segment_count"],d["elapsed"]))'
  local CONSISTENT; CONSISTENT=$(echo "$MANIFEST" | python3 -c 'import sys,json; print(json.load(sys.stdin)["consistent"])')

  echo "    importing restorable SQL into verify_snap and running orphan oracle ..."
  # Reconstruct from the REAL gzip segment artifacts inside spike-wp, decompressed in NUMERIC
  # order (chunk_0..chunk_10 — lexicographic would misorder, so sort -V), then move the file
  # INTO the DB container and import from a LOCAL file. NB: piping between two `docker exec`
  # processes (exec cat | exec mysql) intermittently truncates large streams via the daemon,
  # which corrupts the import; a container-local file import is byte-exact and reliable.
  $COMPOSE exec -T spike-wp bash -lc "ls $OUTDIR/chunk_*.sql.gz | sort -V | xargs gunzip -c > /tmp/sam_recon_$SVC.sql; wc -c < /tmp/sam_recon_$SVC.sql" >/dev/null
  local HOSTTMP DBC
  HOSTTMP="$(mktemp)"
  DBC="sam_spike-${SVC}-1"
  docker cp "$WP_CONTAINER:/tmp/sam_recon_$SVC.sql" "$HOSTTMP" >/dev/null
  docker cp "$HOSTTMP" "$DBC:/tmp/sam_recon.sql" >/dev/null
  rm -f "$HOSTTMP"
  $COMPOSE exec -T "$SVC" "$CLIENT" -uroot -pspikeroot -e "DROP DATABASE IF EXISTS verify_snap; CREATE DATABASE verify_snap;" 2>/dev/null
  $COMPOSE exec -T "$SVC" bash -lc "$CLIENT -uroot -pspikeroot verify_snap < /tmp/sam_recon.sql" 2>/tmp/import_err_$SVC
  local IMPORT_ERR; IMPORT_ERR=$(cat /tmp/import_err_$SVC 2>/dev/null | grep -vi 'insecure\|password' | head -5)

  local B_ORPH_META B_ORPH_ITEMS
  B_ORPH_META=$(SCAL "SELECT (SELECT COUNT(*) FROM verify_snap.wp_2_postmeta m LEFT JOIN verify_snap.wp_2_posts p ON p.ID=m.post_id WHERE p.ID IS NULL) + (SELECT COUNT(*) FROM verify_snap.wp_3_postmeta m LEFT JOIN verify_snap.wp_3_posts p ON p.ID=m.post_id WHERE p.ID IS NULL);")
  B_ORPH_ITEMS=$(SCAL "SELECT COUNT(*) FROM verify_snap.wp_woocommerce_order_items i LEFT JOIN verify_snap.wp_wc_orders o ON o.id=i.order_id WHERE o.id IS NULL;")
  local B_ROWS2 B_ROWSO
  B_ROWS2=$(SCAL "SELECT COUNT(*) FROM verify_snap.wp_2_posts;")
  B_ROWSO=$(SCAL "SELECT COUNT(*) FROM verify_snap.wp_wc_orders;")
  [ -n "$IMPORT_ERR" ] && echo "    (import notices: $IMPORT_ERR)"
  echo "B (consistent): captured wp_2_posts=$B_ROWS2 wp_wc_orders=$B_ROWSO  orphan_meta=$B_ORPH_META orphan_items=$B_ORPH_ITEMS"

  # binary round-trip: media untouched by writer -> source and dumped copy must match exactly
  local SRC_BIN DMP_BIN
  SRC_BIN=$(SCAL "SELECT CONCAT(COUNT(*),':',COALESCE(SUM(CRC32(data)),0),':',COALESCE(SUM(CRC32(COALESCE(blob_data,''))),0)) FROM wordpress.wp_2_media;")
  DMP_BIN=$(SCAL "SELECT CONCAT(COUNT(*),':',COALESCE(SUM(CRC32(data)),0),':',COALESCE(SUM(CRC32(COALESCE(blob_data,''))),0)) FROM verify_snap.wp_2_media;")
  local BIN_OK="FAIL"; [ "$SRC_BIN" = "$DMP_BIN" ] && [ -n "$SRC_BIN" ] && BIN_OK="OK"
  echo "    binary/BLOB round-trip (CRC32): source=$SRC_BIN dump=$DMP_BIN -> $BIN_OK"

  # live growth proof (writer committed rows the snapshot correctly excluded)
  echo "    live table now: wp_2_posts=$(SCAL 'SELECT COUNT(*) FROM wordpress.wp_2_posts;') wp_wc_orders=$(SCAL 'SELECT COUNT(*) FROM wordpress.wp_wc_orders;')"

  # ---- verdict for this engine -----------------------------------------------------------
  local FAIL=0
  [ -z "$IMPORT_ERR" ] || { echo "   !! restorable SQL failed to import cleanly: $IMPORT_ERR"; FAIL=1; }
  [ "$CONSISTENT" = "True" ] || { echo "   !! dumper did not report consistent/complete"; FAIL=1; }
  [ "$B_ORPH_META" = "0" ] || { echo "   !! consistent dump has orphan postmeta ($B_ORPH_META)"; FAIL=1; }
  [ "$B_ORPH_ITEMS" = "0" ] || { echo "   !! consistent dump has orphan order_items ($B_ORPH_ITEMS)"; FAIL=1; }
  [ "$BIN_OK" = "OK" ] || { echo "   !! binary round-trip mismatch"; FAIL=1; }
  if [ "$FAIL" = "0" ]; then
    echo ">>> $LABEL: PASS  (consistent dump 0 orphans, binary intact; paged contrast: $A_RES)"
  else
    echo ">>> $LABEL: FAIL"
    OVERALL_FAIL=1
  fi
}

run_engine spike-db      mysql   "MySQL 8"
run_engine spike-mariadb mariadb "MariaDB 11"

echo ""
echo "============================================================"
if [ "$OVERALL_FAIL" = "0" ]; then
  echo "OVERALL: PASS — consistent dumper = 0 orphans on MySQL 8 AND MariaDB 11"
  exit 0
else
  echo "OVERALL: FAIL"
  exit 1
fi
