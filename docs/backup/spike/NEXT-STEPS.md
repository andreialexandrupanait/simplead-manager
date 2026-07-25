# Spike — state & how to continue

_Last session end: 2026-07-25._ Branch: `spike/simplead-backup-architecture-validation`
(pushed; **no merge, no deploy**). Latest commit: `650cdb8`.

## Where we are

Spike verdict = **GO** (with a short pre-production checklist). Proven with measured data:
no-monolithic-archive, temp-cap (bounded by chunk size), resume after interruption (7 injection
classes), verify-before-complete, restore reproduces content (5000/5000 and 56,624/56,624 files),
deterministic exclusions, site stays online (0× 5xx), real S3 multipart+abort+presigned-expiry, and
the **key DB finding**: connector paged dump tears under load (342 orphans; 22 on real Woo/HPOS),
fixed to **0** by a single `START TRANSACTION WITH CONSISTENT SNAPSHOT` (no mysqldump/SUPER/LOCK).

Full detail: [`ARCHITECTURE-VALIDATION.md`](ARCHITECTURE-VALIDATION.md) +
[`CONTINUATION-RESULTS.md`](CONTINUATION-RESULTS.md) + [`FINAL-ARCHITECTURE-DECISION.md`](FINAL-ARCHITECTURE-DECISION.md).

## The spike stack

**Stopped for the day (containers paused, volumes kept)** under compose project `sam_spike`.
Everything is reproducible from committed code (seeded fixtures). Nothing in production/Coolify is
affected.

- Resume the stack:  `cd spike && docker compose -p sam_spike -f docker-compose.spike.yml start`
  (or `make spike-up` if starting fresh; then `make wp-install`).
- Full teardown (frees ~9 GB + volumes): `cd spike && make spike-down`.
- Working data lives in `spike/scratch/` (gitignored, regeneratable).

## Recommended next step (one more spike round, then build)

**Close the one item that can still change the design: `large` + incompressible + a single
1–5 GB file.** The connector chunks by *grouping files*, so a **single file larger than the split
threshold becomes one chunk** → temp = file size (e.g. 5 GB) and a 5 GB multipart. This case is
untested and is the only remaining thing that could contradict "temp bounded". If it fails, the
engine needs **intra-file streaming multipart**.

To run it:
1. Fix `spike/fixtures/gen-files.php` `writeSmall()` to fill each block with *fresh* hash output
   (per-32-bytes), not `str_repeat` of one block — so content is truly incompressible.
2. Generate `--profile=large` (or a targeted set with a few 1–5 GB files via the `writeHuge` path).
3. Backup + measure real chunk sizes / temp peak / behaviour on the big single file.
4. Same round: full multi-table Woo/HPOS desync at high write rate, Multisite (`wp_N_*`), `mariadb:11`.
5. Update the docs + final verdict.

**Then** start the production build — Phase 1 from the production roadmap
(`docs/backup/IMPLEMENTATION-ROADMAP.md`): additive/reversible schema + `BackupSession`/`RestoreSession`
FSM + observability + `backup:reconcile-storage`, all zero-impact.

## Two locked design decisions from the spike (carry into the build)

1. **DB dump must run in ONE connection inside `START TRANSACTION WITH CONSISTENT SNAPSHOT`** — the
   current chunked-DB-across-HTTP-requests model (new connection per chunk) cannot be consistent.
   No change-journal for InnoDB; journal only as a MyISAM/multi-connection fallback.
2. **Shorten `S3Driver` presigned TTL from `+4h`** to a short, per-part just-in-time value (multipart
   abort/expiry semantics already validated).
