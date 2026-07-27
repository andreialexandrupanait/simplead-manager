#!/usr/bin/env bash
# Test 3 (real WooCommerce HPOS): torn-read across wp_wc_orders / wp_wc_order_product_lookup
# under concurrent order creation, connector-paged (no txn) vs consistent-snapshot.
set -uo pipefail
COMPOSE="docker compose -p sam_spike -f $(dirname "$0")/../docker-compose.spike.yml"
SQL(){ $COMPOSE exec -T spike-db mysql -uroot -pspikeroot -N -e "$1" wordpress 2>/dev/null; }
SCRATCH="$(cd "$(dirname "$0")/.." && pwd)/scratch"

echo "=== seed baseline HPOS orders ==="
SQL "DELETE FROM wp_wc_order_product_lookup; DELETE FROM wp_wc_order_stats; DELETE FROM wp_wc_orders WHERE id>0;"
# seed 5000 orders + line items via generated multi-row inserts
python3 - <<'PY' > "$SCRATCH/woo-seed.sql"
# HPOS id has no auto_increment (Woo allocates from posts id space) -> supply explicit ids
rows=",".join("(%d,'wc-completed','shop_order','USD',%d.00,NOW(),NOW())"%(i, i%90+10) for i in range(1,5001))
print("INSERT INTO wp_wc_orders(id,status,type,currency,total_amount,date_created_gmt,date_updated_gmt) VALUES "+rows+";")
PY
cat "$SCRATCH/woo-seed.sql" | $COMPOSE exec -T spike-db mysql -uroot -pspikeroot wordpress 2>/dev/null
# line items + stats for the seeded orders
SQL "INSERT INTO wp_wc_order_product_lookup(order_item_id,order_id,product_id,variation_id,product_qty)
     SELECT id*10, id, 100+(id%50), 0, 1+(id%3) FROM wp_wc_orders;"
SQL "INSERT INTO wp_wc_order_stats(order_id,status,customer_id) SELECT id,'wc-completed',id%200 FROM wp_wc_orders;"
echo "seeded orders=$(SQL 'SELECT COUNT(*) FROM wp_wc_orders;') items=$(SQL 'SELECT COUNT(*) FROM wp_wc_order_product_lookup;')"

# writer: create orders the HPOS way (order + line item + stats) transactionally
writer(){ local end=$(( $(date +%s)+${1:-20} ))
  while [ "$(date +%s)" -lt "$end" ]; do
  $COMPOSE exec -T spike-db mysql -uroot -pspikeroot wordpress >/dev/null 2>&1 <<'S'
START TRANSACTION;
SELECT COALESCE(MAX(id),0)+1 INTO @oid FROM wp_wc_orders FOR UPDATE;
INSERT INTO wp_wc_orders(id,status,type,currency,total_amount,date_created_gmt,date_updated_gmt)
  VALUES(@oid,'wc-processing','shop_order','USD',77.00,NOW(),NOW());
INSERT INTO wp_wc_order_product_lookup(order_item_id,order_id,product_id,variation_id,product_qty)
  VALUES(@oid*10+1,@oid,555,0,1),(@oid*10+2,@oid,556,0,2);
INSERT INTO wp_wc_order_stats(order_id,status,customer_id) VALUES(@oid,'wc-processing',999);
COMMIT;
S
  done; }

# connector-style paged dump (no transaction): orders THEN product_lookup
dump_paged(){ local out="$1"; : > "$out"; local off=0
  while :; do r=$(SQL "SELECT CONCAT('O:',id) FROM wp_wc_orders ORDER BY id LIMIT $off,500;"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+500)); done
  off=0
  while :; do r=$(SQL "SELECT CONCAT('P:',order_id) FROM wp_wc_order_product_lookup ORDER BY order_item_id LIMIT $off,500;"); [ -z "$r" ] && break; echo "$r">>"$out"; off=$((off+500)); done
}
# consistent-snapshot: same reads in ONE transaction, one connection
dump_snapshot(){ $COMPOSE exec -T spike-db mysql -uroot -pspikeroot -N wordpress 2>/dev/null <<'S' > "$1"
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
START TRANSACTION WITH CONSISTENT SNAPSHOT;
SELECT CONCAT('O:',id) FROM wp_wc_orders ORDER BY id;
SELECT CONCAT('P:',order_id) FROM wp_wc_order_product_lookup ORDER BY order_item_id;
COMMIT;
S
}
analyze(){ python3 - "$1" <<'PY'
import sys
o=set();p=[]
for l in open(sys.argv[1]):
  l=l.strip()
  if l.startswith("O:"):o.add(l[2:])
  elif l.startswith("P:"):p.append(l[2:])
orph=sum(1 for x in p if x not in o)
print(f"orders={len(o)} product_lines={len(p)} orphan_lines={orph}")
PY
}

echo ""; echo "=== connector paged (no txn) under concurrent order creation ==="
writer 22 & W=$!; sleep 2
dump_paged "$SCRATCH/woo_paged.txt"
echo "A (paged):        $(analyze "$SCRATCH/woo_paged.txt")"
wait $W 2>/dev/null
echo ""; echo "=== consistent-snapshot under concurrent order creation ==="
writer 22 & W=$!; sleep 2
dump_snapshot "$SCRATCH/woo_snap.txt"
echo "A' (snapshot):    $(analyze "$SCRATCH/woo_snap.txt")"
wait $W 2>/dev/null
echo "final orders=$(SQL 'SELECT COUNT(*) FROM wp_wc_orders;')"
