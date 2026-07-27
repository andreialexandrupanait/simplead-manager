# Backup Engine V2 — Known Limitations (P7, honest inventory)

These are the real, remaining gaps in the V2 engine at the point of the P7
audit. They are documented openly so the pilot decision is made with full
knowledge. None of them breaks the production-isolation contract (all V2 code is
inert behind default-false flags), but the **production-resolver** items are hard
prerequisites before any real client site is enrolled.

## 1. Production credential + S3 resolvers not wired (BLOCKS real-site enrolment)

- `S3ClientFactory` only has `::lab()` (MinIO from `config('backup_v2.lab_s3.*')`).
  The `::forDestination($site->...)` path that decrypts the site's real
  `StorageDestination` (endpoint/key/secret/region/bucket, as `S3Driver` does) is
  a documented TODO and **not implemented**.
- `SimpleadBackupClient::forSite()` falls back to `::lab()` with lab HMAC creds;
  it does **not** yet decrypt per-site plugin key/secret from the Site row.
- `RunBackupSessionJob` / `RunRestoreSessionJob` therefore currently target lab
  MinIO + lab creds even on the (gated) production path.
- **Consequence:** a premature flag-flip in production would fail to reach real
  storage rather than corrupt it, but the engine cannot back up a real client
  site until these resolvers are implemented and independently re-reviewed.

## 2. Restore pre-restore-backup + health-check closures not wired in the job

`RunRestoreSessionJob` constructs `RestoreRunner` with `preRestoreBackup: null`
and `healthCheck: null` (TODO). The orchestration and rollback logic are fully
tested with injected closures, but the production dispatcher must supply:
- a real safety-backup dispatch (a `BackupRunner` run returning its id), and
- a real post-restore probe (site HTTP + expected DB tables/rows).
Until then the guaranteed-rollback backstop relies solely on the plugin's
journaled swap, not the additional safety backup.

## 3. Encryption at rest is a placeholder

`RestoreRunner::decrypt()` is a no-op ("the lab stores objects in the clear").
The FSM reserves a `decrypting` phase and the plugin will still verify sha256 of
the decrypted staged bytes, but **client-side/at-rest encryption is not
implemented**. Objects in the pilot are stored as produced (gzip for DB, zip
STORE for files). If the pilot's S3 bucket is not itself encrypted, backups are
not encrypted.

## 4. Database consistency guaranteed for InnoDB only

The consistent dumper relies on `START TRANSACTION WITH CONSISTENT SNAPSHOT`
(REPEATABLE READ), which only holds for **InnoDB**. There is **no MyISAM /
mixed-engine fallback** implemented (the spike recommended
`mysqldump --single-transaction`, per-table `LOCK TABLES`, or a change-journal
delta "only as a last resort" — none are built). A site with MyISAM tables under
concurrent writes can still produce a torn dump for those tables. The vast
majority of modern WP is InnoDB, but this is a real edge.

Additionally, a consistent snapshot lives in **one connection**: if the soft time
budget is exceeded the run reports `done=false/consistent=false` and the
orchestrator **restarts** the whole dump with a fresh snapshot (it cannot resume
a snapshot across HTTP requests). On a very large DB with a tight per-request
time budget this can loop; the budget must be tuned to the host in P-perf.

## 5. Single big files: temp bounded but not intra-file streamed

A file larger than the chunk threshold becomes one solo chunk (never split in
v1). Temp peak = `max(largest single file, threshold)`, so an N-GB single file
needs ≈ N GB free temp during that one chunk. Intra-file streaming multipart is
noted as a later optional hardening, not in this version.

## 6. Restore staging chunk is buffered, not streamed

`Restore_Endpoint::stage_chunk()` writes the raw request body to a temp file with
`@file_put_contents($tmp, $body)` (raises `memory_limit` to 512M). Very large
individual chunks are bounded by the manager-side chunk sizing, but the plugin
buffers the full chunk body in memory before staging. Keep chunk sizes within the
host memory envelope discovered by capability discovery.

## 7. Coverage the spike/tests do NOT yet prove

Carried forward honestly from `spike/CONTINUATION-RESULTS.md` and the suite:
- **MariaDB 11** consistent dump — no measured result (container exists, untested).
- **Multisite** (`wp_N_*`) backup/restore end-to-end — not exercised.
- **Large profile / incompressible content** at real chunk sizes.
- Manager-side **disk-full guard** and **overlapping-session lock** wired through
  V2 (only V1 unit coverage today).
- Full multi-table Woo/HPOS desync at sustained high write rate.

## 8. Minor security hardenings (from SECURITY-REVIEW.md)

- Nonce anti-replay has a sub-millisecond concurrency edge on hosts with **no
  persistent object cache** (sequential replay is fully blocked).
- `keep_paths` / `tombstones` in the restore engine lack an explicit `..` reject
  (inputs are HMAC-authenticated and derived from realpath-guarded inventory, so
  not currently exploitable, but belt-and-braces is recommended).

---

**Bottom line:** V2 is safe to pilot behind its flags and is functionally proven
end-to-end against a live WP plugin and real S3 for the InnoDB/MySQL + WooCommerce
HPOS case. Items 1–2 must be closed and re-reviewed before a real client site is
enrolled; items 3–8 should be scheduled and disclosed to the pilot owner.
