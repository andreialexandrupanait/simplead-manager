# Test 5 — Site impact during backup

**Question:** Does a running backup keep the client site online — no 5xx, acceptable latency?

## Method

Concurrent HTTP load against the WP frontend (`spike/harness/load.sh`, 6 parallel workers, timed
GETs) measured **idle** (baseline) and **during a files backup**. Latency percentiles + 5xx counted
(`http_code` 5xx / 0). WP runs with the shared-hosting caps (128 MB / 30 s).

## Results (measured, NORMAL mode)

| Scenario | Requests | p50 | p95 | p99 | max | 5xx/err |
|---|---|---|---|---|---|---|
| Idle baseline | 1,545 | 51 ms | 63 ms | 70 ms | 142 ms | **0** |
| **During backup** | 1,374 | 57 ms | **77 ms** | 82 ms | 92 ms | **0** |

Raw: [`data/load-idle.csv`](data/load-idle.csv), [`data/load-backup.csv`](data/load-backup.csv).
The backup ran to completion (21 chunks) concurrently with the load.

## Findings

- **Frontend stayed fully available**: **zero** 5xx across 2,919 requests spanning a full backup.
- Latency impact is **modest**: p95 +22% (63→77 ms), p99 +17% (70→82 ms), and interestingly the
  *max* was lower during the backup run (tail noise). This is with backup and load sharing the same
  4-CPU WP container.
- The short-checkpointed step model (each chunk = a bounded request, WP `/tmp` freed each step)
  keeps the site responsive — no long-held request monopolising PHP workers.

## LOW / NORMAL / FAST modes

NORMAL was measured. LOW and FAST are knob deltas around it (measured NORMAL is the anchor;
LOW/FAST to be measured on medium/large profiles):

| Knob | LOW (tread-lightly) | NORMAL (measured) | FAST (throughput) |
|---|---|---|---|
| DB rows/batch | 250 | 500 | 2000 |
| DB bytes/chunk | 2 MB | 2 MB | 8 MB |
| Files dir split | 100 MB | 200 MB | 500 MB |
| files_list sub-chunk | 50 MB | 100 MB | 200 MB |
| S3 part size | 64 MB | 100 MB | 256 MB |
| Time budget/step | 15 s | 30 s | 90 s |
| Sleep between batches | 400 ms | 150 ms | 0 |
| Max concurrent jobs | 1 | 2 | 4 |
| Pause threshold | p95 > 800 ms or any 5xx | p95 > 1500 ms | none |

**Recommended default: LOW** for client sites — the measured NORMAL impact is already small
(+22% p95, 0 errors), so LOW (more sleep, 1 concurrent job, auto-pause on any 5xx) gives an even
larger safety margin at a modest throughput cost. FAST is for maintenance windows / VPS only.

## Acceptance mapping

| Criterion | Result |
|---|---|
| No 500/502/503 | ✅ 0 across the backup window |
| Frontend available | ✅ 100% non-5xx |
| Bounded latency impact | ✅ +22% p95 (NORMAL) |

## Limitations

Frontend GETs only, small profile, one WP container. wp-admin / REST-write / **WooCommerce checkout**
paths and the auto-pause-on-load control loop are designed (vegeta/k6 in the plan) but not run this
pass. p95/p99 on medium/large profiles (where DB dump + big-file chunks dominate) remain to be
measured to finalise the LOW/NORMAL/FAST values.
