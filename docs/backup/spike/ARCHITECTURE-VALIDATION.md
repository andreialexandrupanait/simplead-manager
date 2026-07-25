# Spike — Architecture Validation (index & method)

> Technical spike validating the `simplead-backup` architecture **empirically**, in an
> isolated local stack, before committing to a production design. Every claim below is backed
> by a measured artifact under [`data/`](data/). **No production, no client sites, no real
> credentials, no production S3 were touched.**

## Environment (measured)

- **Host:** 12 vCPU, 62 GB RAM (54 GB free), 383 GB free disk, Docker Compose v5.3.1.
- **Isolated stack** (`spike/docker-compose.spike.yml`, project `sam_spike`, own network/volumes,
  ports 127.0.0.1:19000/19001/13306/18080): `spike-minio` (MinIO S3 emulator), `spike-db`
  (mysql:8.0), `spike-wp` (**WordPress 6.9.4 / PHP 8.3.31**, connector mounted), `spike-mc`.
- **Deliberately shared-hosting-like limits** on WP: `memory_limit=128M`, `max_execution_time=30`
  (confirmed live via `/backup/capabilities`) so the chunking/resume paths actually engage.
- **Connector under test:** the real SimpleAd Manager Connector v2.19.0, driven over HMAC-signed
  REST (`METHOD|PATH|TIMESTAMP|NONCE|BODY`). Capabilities reported: `mysqldump:false, tar:false`
  (pure-PHP), chunked_prepare/incremental/manifest all true.

## What was built (spike code, on branch)

- `spike/docker-compose.spike.yml` + `spike/config/php-spike.ini` + `spike/Makefile`.
- `spike/fixtures/gen-files.php` — seeded file-tree generator writing a ground-truth sha256
  manifest (restore oracle).
- `spike/orchestrator/backup.sh` — drives connector chunked endpoints
  (`prepare-init`→`prepare-chunk-exec`→`prepare-chunk-download?delete=1`), streams each chunk to
  MinIO, maintains a **JSON cursor** (resume/idempotency), writes a `BackupManifestV3`-style
  layout + composite checksum + `_COMPLETE`, **verifying before completing**.
- `spike/orchestrator/verify-restore.sh` — Level-B verify (recompute composite) + restore oracle.
- `spike/harness/test3-db-consistency.sh`, `exclusion-match.py`, `load.sh`, `sampler.sh`.

## Test results at a glance

| Test | Question | Result | Verdict |
|---|---|---|---|
| 1 Full backup, no monolith | Can we back up without a monolithic archive + bounded temp? | 22 discrete objects (20 file chunks + manifest + `_COMPLETE`); WP `/tmp` peak ≈ largest single chunk (~14 MB) ≪ 750 MB source | **PASS** |
| 2 Resume | Resume after interruption without restart-from-zero / dups? | Killed after chunks [0,1] → resumed, 20 chunks, **0 duplicates**, composite **byte-identical** to uninterrupted run; restore reproduced 5000/5000 files | **PASS** |
| 3 DB consistency | Torn reads under load? Change-journal needed? | Connector paged dump (no txn) → **342 orphan FK rows**; single consistent-snapshot txn (no mysqldump/SUPER/LOCK) → **0** | **PASS w/ required REVISE** |
| 4 Exclusions | Deterministic, all rule types? | 5639 files → 153 excluded, **identical across 2 runs**, all rule types matched | **PASS** |
| 5 Site impact | Frontend stays available during backup? | p95 63→77 ms (+22%), p99 70→82 ms, **0× 5xx**, site online (NORMAL mode) | **PASS** |

## Per-test documents

- [`FULL-BACKUP-RESULTS.md`](FULL-BACKUP-RESULTS.md) — Test 1
- [`RESUME-FAILURE-RESULTS.md`](RESUME-FAILURE-RESULTS.md) — Test 2
- [`DATABASE-CONSISTENCY.md`](DATABASE-CONSISTENCY.md) — Test 3 (the key REVISE finding)
- [`EXCLUSIONS-RESULTS.md`](EXCLUSIONS-RESULTS.md) — Test 4
- [`PERFORMANCE-IMPACT.md`](PERFORMANCE-IMPACT.md) — Test 5
- [`FINAL-ARCHITECTURE-DECISION.md`](FINAL-ARCHITECTURE-DECISION.md) — GO/REVISE/NO-GO + reuse/rewrite

## Honest scope & limitations

- **Executed:** small profile (5,000 files / 750 MB) + a 61k-row InnoDB dataset, on one WP+DB.
  This proves the **mechanics and architecture**; absolute throughput numbers are small-scale.
- **Designed but NOT executed this pass** (time-bounded): medium/large profiles (100k/500k files,
  2/10 GB DB, 1–5 GB files — the generator supports `--profile` + `--sparse`); full WooCommerce/HPOS
  and Multisite variants; and 8 of the 11 failure injections. Injections **empirically run**:
  kill-orchestrator-mid-backup (resume), duplicate `chunk-exec` (idempotency), nonce replay.
- **Upload path:** chunks streamed to MinIO via `mc` (S3 multipart under the hood). The manager's
  `S3Driver` presigned-multipart-per-part + abort-dangling-upload semantics were **not** exercised
  in this run (designed in the plan; flagged for the next pass).
- Fixture content is partially compressible, so compression ratios are not representative (does
  not affect the no-monolith / resume / consistency conclusions).

These limitations are quantified per-test and do not change any verdict; they define the
"complete before production" checklist in the final decision.
