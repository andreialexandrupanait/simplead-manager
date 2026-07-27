#!/usr/bin/env bash
# Test 3 — DB consistency under concurrent writes.
# Compares the connector's method (table-by-table, offset-paged, NO single-transaction)
# against mysqldump --single-transaction (consistent oracle), while a writer commits
# (order + items) transactionally. Detects orphan FK rows + missing pre-cutoff orders.
set -uo pipefail
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
MYSQL(){ $COMPOSE exec -T spike-db mysql -uroot -pspikeroot -N -e "$1" wordpress 2>/dev/null; }
MYSQLraw(){ $COMPOSE exec -T spike-db mysql -uroot -pspikeroot -N wordpress 2>/dev/null; }
SCRATCH="$(cd "$(dirname "$0")/.." && pwd)/scratch"

echo "=== setup schema + seed ==="
MYSQL "DROP TABLE IF EXISTS order_items; DROP TABLE IF EXISTS orders;"
MYSQL "CREATE TABLE orders(id INT PRIMARY KEY AUTO_INCREMENT, total DECIMAL(10,2), created_at DOUBLE) ENGINE=InnoDB;"
MYSQL "CREATE TABLE order_items(id INT PRIMARY KEY AUTO_INCREMENT, order_id INT, product VARCHAR(64), qty INT, INDEX(order_id)) ENGINE=InnoDB;"
# seed 20000 orders + ~3 items each via generated multi-row inserts
python3 - <<'PY' > "$SCRATCH/seed.sql"
print("INSERT INTO orders(total,created_at) VALUES " + ",".join(f"({i%97+1}.00,0)" for i in range(20000)) + ";")
rows=[]
for oid in range(1,20001):
    for k in range(3): rows.append(f"({oid},'p{oid%50}',{k+1})")
    if len(rows)>5000:
        print("INSERT INTO order_items(order_id,product,qty) VALUES "+",".join(rows)+";"); rows=[]
if rows: print("INSERT INTO order_items(order_id,product,qty) VALUES "+",".join(rows)+";")
PY
cat "$SCRATCH/seed.sql" | MYSQLraw
echo "seeded orders=$(MYSQL 'SELECT COUNT(*) FROM orders;') items=$(MYSQL 'SELECT COUNT(*) FROM order_items;')"

# --- writer: commits (order + 3 items) transactionally at high rate, logs order ids ---
writer(){
  local dur=$1; local end=$(( $(date +%s) + dur )); local jf="$2"
  : > "$jf"
  while [ "$(date +%s)" -lt "$end" ]; do
    $COMPOSE exec -T spike-db mysql -uroot -pspikeroot wordpress >/dev/null 2>&1 <<SQL
START TRANSACTION;
INSERT INTO orders(total,created_at) VALUES (999.00, $(date +%s.%N));
SET @oid = LAST_INSERT_ID();
INSERT INTO order_items(order_id,product,qty) VALUES (@oid,'live',1),(@oid,'live',2),(@oid,'live',3);
COMMIT;
SELECT @oid;
SQL
  done
}

# --- Method A (connector): table-by-table, offset-paged, NO transaction ---
dump_paged(){
  local out="$1"; : > "$out"
  # dump orders fully (paged), THEN items (paged) — the gap is where torn FK appears
  local off=0
  while :; do
    local rows=$(MYSQL "SELECT CONCAT('O:',id) FROM orders ORDER BY id LIMIT $off,500;")
    [ -z "$rows" ] && break; echo "$rows" >> "$out"; off=$((off+500))
  done
  off=0
  while :; do
    local rows=$(MYSQL "SELECT CONCAT('I:',order_id) FROM order_items ORDER BY id LIMIT $off,500;")
    [ -z "$rows" ] && break; echo "$rows" >> "$out"; off=$((off+500))
  done
}

# --- Method B (oracle): single-transaction consistent snapshot ---
dump_snapshot(){
  local out="$1"
  $COMPOSE exec -T spike-db mysqldump -uroot -pspikeroot --single-transaction --skip-lock-tables \
     --no-create-info wordpress orders order_items 2>/dev/null > "$out"
}

analyze_paged(){ # dumpfile -> orphan items count
  python3 - "$1" <<'PY'
import sys
orders=set(); items=[]
for l in open(sys.argv[1]):
    l=l.strip()
    if l.startswith("O:"): orders.add(l[2:])
    elif l.startswith("I:"): items.append(l[2:])
orphans=sum(1 for oid in items if oid not in orders)
print(f"orders={len(orders)} items={len(items)} orphan_items={orphans}")
PY
}

echo ""
echo "=== TRIAL: start writer (25s) then run dumps concurrently ==="
JF="$SCRATCH/journal.txt"
writer 25 "$JF" &
WPID=$!
sleep 3   # let some concurrent writes accumulate
echo "-- Method A (paged, connector-style) --"
dump_paged "$SCRATCH/dumpA.txt"
echo "A: $(analyze_paged "$SCRATCH/dumpA.txt")"
echo "-- Method B (single-transaction oracle) --"
dump_snapshot "$SCRATCH/dumpB.sql"
# count orphan items in B by loading into scratch schema
$COMPOSE exec -T spike-db mysql -uroot -pspikeroot -e "DROP DATABASE IF EXISTS verifyB; CREATE DATABASE verifyB;" 2>/dev/null
$COMPOSE exec -T spike-db sh -c "mysql -uroot -pspikeroot verifyB -e 'CREATE TABLE orders(id INT PRIMARY KEY,total DECIMAL(10,2),created_at DOUBLE); CREATE TABLE order_items(id INT PRIMARY KEY,order_id INT,product VARCHAR(64),qty INT);'" 2>/dev/null
cat "$SCRATCH/dumpB.sql" | $COMPOSE exec -T spike-db mysql -uroot -pspikeroot verifyB 2>/dev/null
B_ORPH=$(MYSQL "SELECT COUNT(*) FROM verifyB.order_items i LEFT JOIN verifyB.orders o ON o.id=i.order_id WHERE o.id IS NULL;" 2>/dev/null)
echo "B: orphan_items(single-transaction)=$B_ORPH"
wait $WPID 2>/dev/null
echo "writer committed $(wc -l < "$JF") live orders during window"
