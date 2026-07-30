# Spike — state & how to continue

_Last session end: 2026-07-27._ Branch: `spike/simplead-backup-architecture-validation`
(pushed; **no merge, no deploy**).

## Where we are

Spike is **COMPLETE. Verdict = GO** (architecture validated). Every open item is closed with
measured data across three rounds. The final round closed the last unknown — a **single
incompressible file larger than the chunk threshold** — plus **MariaDB 11** and **Multisite**.

Proven, measured:
- No-monolithic-archive; **temp bounded by chunk size** — now confirmed *size-independent
  including the pathological single-big-file case*: a 2 GB incompressible single file → one 2 GB
  chunk, WP `/tmp` peak **2,050 MB** (== that one chunk, not the 3.23 GB source; pull-and-free), and
  **real RSS flat at 137 MB** (no intra-file buffering; no OOM). Restore byte-identical (2 GB file
  sha256 match; oracle 8,001/8,001; composite MATCH).
- Resume after interruption (7 injection classes), verify-before-complete, deterministic
  exclusions, site stays online (0× 5xx), real S3 multipart+abort+presigned-expiry.
- **DB consistency:** connector paged dump tears under load — 342 orphans; 22 on real Woo/HPOS;
  **44 on MariaDB 11 + Multisite** — all fixed to **0** by one `START TRANSACTION WITH CONSISTENT
  SNAPSHOT` (no mysqldump/SUPER/LOCK). Holds across MySQL 8.0 **and** MariaDB 11, HPOS **and**
  Multisite.
- **New finding (fixed in harness):** the empty-chunk contract — `exec` returns `chunk_size=0` and
  deletes the zip, `download` then 404s; a naive "non-empty body" puller stored the 404 JSON as a
  chunk. Production manager must skip `chunk_size==0` and validate downloads. See §3 below.

Full detail: [`SINGLE-BIGFILE-RESULTS.md`](SINGLE-BIGFILE-RESULTS.md) (final round) +
[`ARCHITECTURE-VALIDATION.md`](ARCHITECTURE-VALIDATION.md) +
[`CONTINUATION-RESULTS.md`](CONTINUATION-RESULTS.md) +
[`FINAL-ARCHITECTURE-DECISION.md`](FINAL-ARCHITECTURE-DECISION.md).

## The spike stack

**Stopped for the day (containers paused, volumes kept)** under compose project `sam_spike`.
Reproducible from committed code (seeded fixtures). Nothing in production/Coolify is affected.
Now includes a **`spike-mariadb` (mariadb:11) sidecar** with its own `spike_mariadb_data` volume;
the original mysql:8.0 `spike_db_data` volume is untouched.

- Resume the stack:  `cd spike && docker compose -p sam_spike -f docker-compose.spike.yml start`
- Full teardown (frees volumes): `cd spike && make spike-down`.
- Working data lives in `spike/scratch/` (gitignored, regeneratable).
- Re-run the final round:
  - Files/big-file: generate `--profile=large-single` into `wp-content/uploads/spike`, then
    `orchestrator/backup.sh files <session>` with `harness/bigfile-sampler.sh` running.
  - DB: `harness/test4-mariadb-multisite.sh`.

## No spike work remains — start the production build

Spike questions are answered. **Begin Phase 1** from the production roadmap
(`docs/backup/IMPLEMENTATION-ROADMAP.md`): additive/reversible schema +
`BackupSession`/`RestoreSession` FSM + observability + `backup:reconcile-storage`, all zero-impact.

## Locked design decisions from the spike (carry into the build)

1. **DB dump must run in ONE connection inside `START TRANSACTION WITH CONSISTENT SNAPSHOT`** — the
   chunked-DB-across-HTTP-requests model (new connection per chunk) cannot be consistent. Validated
   on MySQL 8.0 and MariaDB 11. No change-journal for InnoDB; journal only as a
   MyISAM/multi-connection fallback.
2. **Shorten `S3Driver` presigned TTL from `+4h`** to short, per-part just-in-time (multipart
   abort/expiry semantics already validated).
3. **Empty-chunk contract:** manager skips chunks whose `exec` returns `chunk_size==0` (never pulls
   or manifests them), and validates each download is a real chunk (HTTP 200 / valid archive) —
   never trusts a merely-non-empty body. A `chunk_size==0` chunk has its zip deleted server-side and
   its `download` returns 404.
4. **Temp sizing:** WP-host + manager temp must hold the **largest single file** during its chunk
   (temp cap = `max(largest file, 100 MB grouping threshold)`). Add intra-file streaming multipart
   only if customers hold single files larger than available temp — not needed for the common case.
