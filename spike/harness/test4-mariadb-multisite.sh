#!/usr/bin/env bash
# Test 4 (MariaDB 11 + WordPress Multisite): re-validate the DB consistency finding on
# MariaDB 11 with a Multisite (wp_N_*) schema. Invariant: every wp_N_postmeta.post_id
# must reference an existing wp_N_posts.ID. A backup that dumps posts-then-postmeta paged
# across SEPARATE connections (the connector's chunked-DB-over-HTTP model) tears under
# concurrent post creation -> orphan postmeta. ONE connection inside
# START TRANSACTION WITH CONSISTENT SNAPSHOT -> 0 orphans.
set -uo pipefail
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
DB=spike-mariadb
# each invocation = a fresh connection (mimics new-connection-per-chunk over HTTP)
SQL(){ $COMPOSE exec -T "$DB" mariadb -uroot -pspikeroot -N -e "$1" wordpress 2>/dev/null; }
SCRATCH="$(cd "$(dirname "$0")/.." && pwd)/scratch"
SITES="2 3"   # two sub-sites -> wp_2_*, wp_3_*

echo "=== MariaDB version ==="; SQL "SELECT VERSION();"

echo "=== (re)create Multisite schema + seed 5000 posts/site ==="
for N in $SITES; do
  SQL "DROP TABLE IF EXISTS wp_${N}_postmeta; DROP TABLE IF EXISTS wp_${N}_posts;"
  SQL "CREATE TABLE wp_${N}_posts (
        ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_title VARCHAR(255) NOT NULL DEFAULT '',
        post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
        post_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB;"
  SQL "CREATE TABLE wp_${N}_postmeta (
        meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta_key VARCHAR(255) DEFAULT NULL,
        meta_value LONGTEXT,
        KEY post_id (post_id)
      ) ENGINE=InnoDB;"
  # seed 5000 posts + 2 meta each
  python3 - "$N" > "$SCRATCH/ms-seed-$N.sql" <<'PY'
import sys
N=sys.argv[1]
posts=",".join("('post %d')"%i for i in range(1,5001))
print(f"INSERT INTO wp_{N}_posts(post_title) VALUES {posts};")
print(f"INSERT INTO wp_{N}_postmeta(post_id,meta_key,meta_value) SELECT ID,'_k',CONCAT('v',ID) FROM wp_{N}_posts;")
print(f"INSERT INTO wp_{N}_postmeta(post_id,meta_key,meta_value) SELECT ID,'_k2',CONCAT('w',ID) FROM wp_{N}_posts;")
PY
  cat "$SCRATCH/ms-seed-$N.sql" | $COMPOSE exec -T "$DB" mariadb -uroot -pspikeroot wordpress 2>/dev/null
  echo "  site $N: posts=$(SQL "SELECT COUNT(*) FROM wp_${N}_posts;") meta=$(SQL "SELECT COUNT(*) FROM wp_${N}_postmeta;")"
done

# writer: create posts the WP-multisite way (post + its meta) transactionally, both sites
writer(){ local end=$(( $(date +%s)+${1:-20} ))
  while [ "$(date +%s)" -lt "$end" ]; do
    for N in $SITES; do
      $COMPOSE exec -T "$DB" mariadb -uroot -pspikeroot wordpress >/dev/null 2>&1 <<S
START TRANSACTION;
INSERT INTO wp_${N}_posts(post_title) VALUES ('live');
SET @pid = LAST_INSERT_ID();
INSERT INTO wp_${N}_postmeta(post_id,meta_key,meta_value) VALUES (@pid,'_k','x'),(@pid,'_k2','y');
COMMIT;
S
    done
  done; }

# connector-style paged dump (no txn): ALL posts (both sites) THEN all postmeta, paged,
# each page a fresh connection.
dump_paged(){ local out="$1"; : > "$out"
  for N in $SITES; do local off=0
    while :; do r=$(SQL "SELECT CONCAT('O:${N}:',ID) FROM wp_${N}_posts ORDER BY ID LIMIT $off,1000;"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+1000)); done
  done
  for N in $SITES; do local off=0
    while :; do r=$(SQL "SELECT CONCAT('P:${N}:',post_id) FROM wp_${N}_postmeta ORDER BY meta_id LIMIT $off,1000;"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+1000)); done
  done
}
# consistent-snapshot: identical reads, ONE connection, one transaction
dump_snapshot(){ $COMPOSE exec -T "$DB" mariadb -uroot -pspikeroot -N wordpress 2>/dev/null > "$1" <<'S'
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
START TRANSACTION WITH CONSISTENT SNAPSHOT;
SELECT CONCAT('O:2:',ID) FROM wp_2_posts ORDER BY ID;
SELECT CONCAT('O:3:',ID) FROM wp_3_posts ORDER BY ID;
SELECT CONCAT('P:2:',post_id) FROM wp_2_postmeta ORDER BY meta_id;
SELECT CONCAT('P:3:',post_id) FROM wp_3_postmeta ORDER BY meta_id;
COMMIT;
S
}
analyze(){ python3 - "$1" <<'PY'
import sys
posts=set(); meta=[]
for l in open(sys.argv[1]):
  l=l.strip()
  if l.startswith("O:"): posts.add(l[2:])           # site:ID
  elif l.startswith("P:"): meta.append(l[2:])        # site:post_id
orph=sum(1 for x in meta if x not in posts)
print(f"posts={len(posts)} postmeta={len(meta)} orphan_meta={orph}")
PY
}

echo ""; echo "=== A) connector paged (NO txn, per-page connection) under concurrent writes ==="
writer 22 & W=$!; sleep 2
dump_paged "$SCRATCH/ms_paged.txt"
echo "A  (paged):    $(analyze "$SCRATCH/ms_paged.txt")"
wait $W 2>/dev/null

echo ""; echo "=== B) consistent-snapshot (ONE connection) under concurrent writes ==="
writer 22 & W=$!; sleep 2
dump_snapshot "$SCRATCH/ms_snap.txt"
echo "A' (snapshot): $(analyze "$SCRATCH/ms_snap.txt")"
wait $W 2>/dev/null
for N in $SITES; do echo "final site $N posts=$(SQL "SELECT COUNT(*) FROM wp_${N}_posts;")"; done
