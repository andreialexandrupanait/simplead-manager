# Backup Engine V2 — Lab evidence (2026-07-29)

The spike lab (`spike/docker-compose.spike.yml`, project `sam_spike`) was rebuilt
and the full lab-gated suite + the plugin bash harnesses re-run against real MinIO,
real WordPress 6.9.4 (spike-wp), MySQL 8.0.46 and MariaDB 11.8.8. All flags remain
OFF; nothing was deployed, merged to `main`, or pointed at a production bucket.

## 1. Lab topology
`sam_spike` / network `sam_spike_net`: `spike-minio` (S3, 127.0.0.1:19000, bucket
`backups`), `spike-db` (mysql:8.0, :13306), `spike-mariadb` (mariadb:11, :13307),
`spike-wp` (wordpress:6-php8.3, :18080, both connector + simplead-backup active).
Test glue: `BACKUP_ENGINE_V2_LAB_S3_ENDPOINT=http://127.0.0.1:19000` +
`BACKUP_ENGINE_V2_LAB_WP_HOST=http://127.0.0.1:18080` (bin/test on --network host).

## 2. phpunit lab-gated suite (was 32 skipped / 12 errored → now)
**`tests/Feature/Backup` + `tests/Unit/Backup` + SandboxRestoreProveTest: 159 tests,
1021 assertions, 0 failures, 0 skipped.** Proven end-to-end in lab:
- Full / incremental / DB-only / files-only backup, exclusions, resume-after-crash
  (kill mid-upload → resume, no re-download/re-upload, byte-identical), no monolithic
  archive (`BackupRunnerE2ETest`, `IncrementalChainE2ETest`, `IncrementalHttpE2ETest`).
- Hardened S3 multipart incl. injected transient faults → retry/abort, 0 dangling,
  presigned-TTL expiry (`HardenedMultipartUploaderTest`, 8 tests / 39 assertions).
- Manifest + `_COMPLETE`-last + composite checksums + deep-verify re-hash
  (`BackupV2CommandsTest::test_deep_verify...` → status=passed, 0 sha mismatches).
- Full / selective / chain restore + rollback, mirror vs safe-merge, over the REAL
  simplead-backup plugin via HTTP (`RestoreRunnerE2ETest`, `RestoreHttpE2ETest`).
- Proven-restore writes a real passed/failed `proven_restores` row from an unmocked
  `SandboxRestoreService::prove()` (`SandboxRestoreProveTest`, pass + corrupt-fail).

## 3. Plugin bash harnesses
### files-test.sh — PASS (1.1 GB set)
`files=5001 bytes=1,100,696,576 excluded=6 chunks=13`; inventory hash stable x2
(deterministic exclusions, 0 excluded paths leaked into the plan); 300 MB
incompressible file = its own chunk (STORE 0.41s vs DEFLATE 5.95s ratio 1.0002);
**temp bounded**: peak 315,543,449 ≤ largest chunk 314,572,954 + slack;
restore-oracle **ok=5001 missing=0 mismatch=0**.

### db-consistency-test.sh — PASS on BOTH engines
Multisite (`wp_2_*`/`wp_3_*`) + Woo/HPOS (`wp_wc_orders`) + BLOB media, 5000
posts/site + 5000 orders, under a 20 s concurrent transactional writer:
- **MySQL 8.0.46**: paged/torn dump → orphans_total=62; **consistent dumper →
  orphan_meta=0, orphan_items=0** (`START TRANSACTION WITH CONSISTENT SNAPSHOT`),
  6 segments, BLOB CRC32 round-trip OK.
- **MariaDB 11.8.8**: paged → 62 orphans; **consistent dumper → 0 orphans**, BLOB OK.

## 4. DoD criteria moved to proven-in-lab
3–17, 19, 20, 21, 24, 25, plus the storage/integrity set (10–14). Criteria 22/23
(no 5xx under traffic) retain the spike `PERFORMANCE-IMPACT.md` evidence (0×5xx over
2,919 requests, p95 +22%). Remaining open items are inventoried in
`KNOWN-LIMITATIONS.md` §7–8 (disk-full/session-lock wiring, cross-domain multisite
restore, encryption-at-rest placeholder, two minor security hardenings). Real pilot
(Rung 2, Hetzner/prod) is still gated on the owner instruction `DA PILOT BACKUP V2`.
