# Test 3 — Database consistency (the key REVISE finding)

**Question:** Does the connector's current DB export (pure-PHP, table-by-table, offset-paged,
**no transaction**) produce a torn/inconsistent snapshot under concurrent writes? Is a
**change-journal** required? *(Do not assume — measure.)*

## Setup

`spike-db` (mysql:8.0, InnoDB). Schema modelling a WooCommerce-style FK relationship:
`orders(id PK, total, created_at)` + `order_items(id PK, order_id, product, qty)`. Seeded
**20,000 orders / 60,000 items**. A **writer** committed `(order + 3 items)` transactionally at
high rate during each dump. Detector: **orphan items** = `order_items.order_id` with no matching
`orders.id` in the dump (a broken FK relation → a WooCommerce order line pointing at a
non-existent order).

## Three methods, measured under identical concurrent load

| Method | How | Orphan items |
|---|---|---|
| **A — connector (current)** | table-by-table, `LIMIT offset,500` paging, **no transaction** | **342** |
| **B — oracle** | `mysqldump --single-transaction` | **0** |
| **A′ — proposed fix** | all paged reads inside **one** `START TRANSACTION WITH CONSISTENT SNAPSHOT` (REPEATABLE READ), single connection, **no mysqldump / no SUPER / no LOCK TABLES** | **0** |

Method-A dump summary: [`data/test3-dumpA-summary.txt`](data/test3-dumpA-summary.txt) —
`orders_captured=20059 items_captured=60519 orphan_items=342`; orphan order_ids start at 20060
(orders committed *after* the orders-table paging finished, whose items were captured by the
later items-table paging → dangling FK).

Method A′ under a fresh 20 s writer load: `orders=20224 items=60672 orphan_items=0` while the live
table grew to `20389/61167` — i.e. a **consistent point-in-time snapshot** was captured despite
concurrent commits.

## Findings

1. **The connector's current DB dump is torn under load** — 342 broken FK rows in a single trial.
   On WooCommerce this means a restored site could have order lines referencing missing orders (or
   orders missing their lines). This is a **real correctness defect**, reproducible, and it scales
   with write rate.
2. **A change-journal is NOT required for InnoDB.** A single `START TRANSACTION WITH CONSISTENT
   SNAPSHOT` on one connection yields a perfectly consistent dump — and crucially it needs **no
   `mysqldump`, no `SUPER`, no `LOCK TABLES`**, so it works on locked-down shared hosting (exactly
   where the connector reports `mysqldump:false`).
3. **Required REVISE to the chunked-DB design.** A consistent snapshot only holds **within one DB
   connection/transaction**. The connector's chunked-DB path executes each chunk in a **separate
   HTTP request → separate DB connection → separate snapshot**, so chunking the DB across requests
   **cannot** be consistent. Confirmed structurally: `db-test1` produced 4 db chunks across 4
   requests. **The DB must be dumped within a single connection/transaction** (streamed to one or
   more objects from that one process), not reassembled from independently-connected chunk requests.

## Recommendation (results-driven)

- **Primary (InnoDB, the overwhelming majority):** dump the whole database inside **one persistent
  connection** wrapped in `START TRANSACTION WITH CONSISTENT SNAPSHOT` (`REPEATABLE READ`), streaming
  the output to gzip → S3 (chunk the *output stream* by bytes if needed, but keep it one
  transaction). No change-journal.
- **Fallback (MyISAM / mixed-engine / multi-connection hosts):** MyISAM ignores transactions, so a
  consistent snapshot is not guaranteed. Options in order of preference: `mysqldump --single-transaction`
  where available; else a short `LOCK TABLES ... READ` per table group where permitted; else a
  reconciliation pass over rows changed during the dump (an `updated_at`/journal delta) — i.e. a
  change-journal **only here**, as a last resort.
- Detect engine mix via `information_schema.TABLES.ENGINE` and pick the strategy per host
  (`connector_capabilities`).

## Acceptance mapping

| Criterion | Result |
|---|---|
| Consistent DB under concurrent writes | ✅ achievable (A′, 0 orphans) — ❌ current method (342) |
| WooCommerce loses no orders / no broken relations | ✅ with A′; ❌ with current method |
| Works on shared hosting (no mysqldump/SUPER/LOCK) | ✅ (A′ uses none) |
| Change-journal needed? | **No for InnoDB**; only as MyISAM/multi-connection fallback |

## Limitations

Synthetic 2-table FK schema (not full Woo/HPOS with `wc_orders`/`wc_order_stats`/`order_product_lookup`).
The torn-read mechanism is engine-level and generalizes, but the exact multi-table Woo desync and a
Multisite (`wp_N_*`) run remain to be executed before production. `mariadb:11` not yet run (MariaDB
also supports consistent-snapshot on InnoDB/XtraDB; to be confirmed).
