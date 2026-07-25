# Final Architecture Decision (spike verdict)

## Verdict: **GO — with two mandatory revisions**

The intended architecture is **validated on its core, load-bearing claims** with measured evidence:
no-monolithic-archive, bounded temp, resumability, verify-before-complete, restorability,
deterministic exclusions, and site-stays-online. Nothing is a NO-GO — every problem surfaced has a
demonstrated fix. Two revisions are **required** before building the production engine, and a set of
larger-scale confirmations must complete before fleet rollout.

## What the spike proved (evidence)

| Claim | Evidence | Status |
|---|---|---|
| No monolithic archive | 22 discrete objects, `prepare-finalize` skipped | ✅ |
| Temp bounded | peak temp = largest chunk (~14 MB) ≪ 750 MB source | ✅ |
| Resumable, no dups | kill→resume: 20 chunks, 0 dups, composite identical to clean run | ✅ |
| Verify-before-complete | `_COMPLETE` only after all objects verified + manifest | ✅ |
| Restore reproduces content | 5000/5000 files sha256 match after resume | ✅ |
| Idempotent ops / replay-safe | duplicate `chunk-exec` → `skipped`; nonce replay → 401 | ✅ |
| Deterministic exclusions | 153 excluded, identical across 2 runs, all rule types | ✅ |
| Site stays online | 0× 5xx, p95 +22% during backup | ✅ |

## Mandatory revisions (before building the engine)

1. **DB dump must be a single-connection consistent snapshot.** Test 3 measured **342 broken FK
   rows** from the current no-transaction paged dump, and **0** from a single
   `START TRANSACTION WITH CONSISTENT SNAPSHOT` (no mysqldump/SUPER/LOCK — shared-hosting-safe).
   Because a snapshot only holds within one connection, the **chunked-DB-across-HTTP-requests model
   must change**: dump the whole DB in one transaction/process, streaming output to S3 (chunk the
   *byte stream*, not the *table reads*). Change-journal only as a MyISAM/multi-connection fallback.
2. **Exercise the real S3 multipart path** (`S3Driver` presign-per-part + `abortMultipartUpload` +
   `ListMultipartUploads`=0 dangling), and shorten presigned-URL TTL from the current `+4h`. This
   run used `mc` (multipart under the hood); the manager's presign/abort semantics and the
   presigned-expiry injection were not exercised.

## Complete-before-production checklist (not blockers to *start*, blockers to *ship*)

- Medium + large profiles (100k/500k files, 2/10 GB DB, 1–5 GB single files).
- Full WooCommerce/HPOS multi-table desync + Multisite (`wp_N_*`) DB-consistency runs; `mariadb:11`.
- Remaining 8 failure injections (WP/worker restart, S3 cut, MinIO pause, presigned expiry,
  disk-full, overlapping-session lock).
- Wire exclusions into `prepare-init` (so excluded files are never read) + config UI.
- LOW/NORMAL/FAST tuned from medium/large latency plots; auto-pause control loop.

## Components to REUSE (validated as sound)

- Connector **chunked prepare / resume / pull-and-free** (`prepare-init`, `prepare-chunk-exec`,
  `prepare-chunk-download?delete=1`) — the `.done`-marker + output-recheck idempotency held.
- Connector **capability discovery** and **HMAC auth incl. nonce replay protection**.
- Manager **`BackupManifestV3`** layout + composite checksum; **`IntegrityVerifier`** verify model;
  the cursor/confirm-after-S3 pattern; `SiteOperationLock`, `DiskSpaceGuard` (unchanged).
- The exclusion matcher shape (Test 4).

## Components to REWRITE / CHANGE

- **DB export**: single-connection consistent-snapshot streaming (see revision 1). The connector's
  per-chunk-request DB path is replaced.
- **Upload transport**: real `S3Driver` multipart-per-part with persisted `mp_upload_id`, resume of
  missing parts, `abort` on expiry/mismatch; short-lived presigned URLs.
- **Session/cursor as first-class state** (the spike used a JSON cursor; production needs the
  `backup_sessions`/`chunk_cursor` tables from the data-migration plan).
- **Exclusions**: wire the matcher into config + the connector walk (dead columns today).
- **Completion contract**: keep the spike's rule — no `completed` without a verified manifest +
  marker (fixes the production 191-missing-manifest defect).
- **Plugin packaging**: extract into the standalone `simplead-backup` plugin (connector shim during
  migration), per the target architecture.

## Remaining risks

- Large-site absolute timings and big-file (1–5 GB) multipart behaviour unmeasured.
- MyISAM / mixed-engine hosts need the fallback consistency path (change-journal) — scope unknown
  until surveyed across the fleet.
- Presigned-URL expiry vs slow-host large-part uploads (the `+4h` may be both too long for security
  and too short for a 5 GB part on a slow link) — must be measured.
- WP-Cron/loopback detach reliability on low-traffic hosts (not re-tested here; known from Phase 0).

## Bottom line

**GO** to build the production engine on this architecture, starting with the two mandatory
revisions (DB consistent-snapshot; real multipart), then completing the medium/large + Woo/Multisite
+ full-injection matrix before any fleet rollout. No architectural dead-end was found; the biggest
correctness risk (DB torn reads) has a cheap, shared-hosting-compatible fix proven at 0 orphans.
