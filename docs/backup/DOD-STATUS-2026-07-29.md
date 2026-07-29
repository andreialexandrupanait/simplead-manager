# Backup Engine V2 — Definition of Done status (2026-07-29, lab-proven)

Re-assessment after rebuilding the spike lab and running the full lab-gated suite +
plugin harnesses. Compare to the morning audit (13 pass / 7 partial / 1 fail / 9
lab-gated → NOT deployable). Everything below is **in lab, on branch
`feature/simplead-backup-production-ready`, flags OFF — nothing deployed or merged.**

## Per-criterion verdict

| # | Criterion | Verdict | Evidence |
|---|-----------|---------|----------|
| 1 | Plugin complete & installable | ✅ pass | installed+active on spike-wp; all REST routes registered |
| 2 | Manager fully orchestrates | ✅ pass | FSM + SessionActions + jobs, driven end-to-end in E2E |
| 3 | Full backup | ✅ pass | `BackupRunnerE2ETest` real plugin + MinIO |
| 4 | Incremental backup | ✅ pass | `IncrementalChainE2ETest` + `IncrementalHttpE2ETest` |
| 5 | DB-only | ✅ pass | scope tests + consistent dumper |
| 6 | Files-only | ✅ pass | `files-test.sh` |
| 7 | Exclusions | ✅ pass | files-test: 6 excluded, 0 leaked, deterministic hash |
| 8 | Resume after interruption | ✅ pass | kill mid-upload → resume, byte-identical, 0 dup |
| 9 | No monolithic archive | ✅ pass | 22–73 discrete objects; big file = own STORE chunk |
| 10 | Temp storage capped | ✅ pass | files-test: peak ≤ largest chunk + slack |
| 11 | Hetzner S3 multipart robust | ✅ pass (MinIO) | fault-injection retry/abort, 0 dangling; **Hetzner-real at pilot** |
| 12 | Manifest mandatory | ✅ pass | manifest asserted in every E2E |
| 13 | Completion marker mandatory | ✅ pass | `_COMPLETE` written last (mtime-verified) |
| 14 | Checksums validated | ✅ pass | deep-verify re-hash, 0 sha mismatch |
| 15 | Full restore | ✅ pass | `RestoreRunnerE2ETest` + `RestoreHttpE2ETest` (real HTTP) |
| 16 | Selective restore | ✅ pass | folder-scope restore test |
| 17 | Incremental chain restore | ✅ pass | chain reconstruct + tombstones |
| 18 | Rollback | ✅ pass | validation-fail → rollback to pre-apply |
| 19 | Proven restore produces real rows | ✅ pass | `SandboxRestoreProveTest` (unmocked) + `ProvenRestoreServiceTest` |
| 20 | WooCommerce DB consistent | ✅ pass | db-consistency: 0 orphans, HPOS, both engines |
| 21 | Multisite consistent | ✅ pass | db-consistency: 0 orphans `wp_N_*`, MySQL8 **and** MariaDB11 (**was contested — resolved**) |
| 22 | Site online under traffic | 🟡 pass* | spike PERFORMANCE: 0×5xx / 2919 req, p95 +22%. *LOW/FAST throttle knobs not fully wired — documented |
| 23 | No 5xx from backup | 🟡 pass* | spike: 0×5xx during backup window. *same caveat |
| 24 | No OOM | ✅ pass | spike: 2 GB file → RSS flat 137 MB |
| 25 | No disk full | 🟡 pass* | files-test temp bound. *V2 DiskSpaceGuard preflight still V1-only — documented |
| 26 | Review: no critical/major | ✅ pass | re-review: 1 MAJOR (tombstone) FIXED; 2 minor open+documented |
| 27 | Limitations documented | ✅ pass | KNOWN-LIMITATIONS §7/§8 updated |
| 28 | CI green | ✅ pass | full suite w/ lab: 1160 tests, 3838 assertions, 0 fail, exit 0 |
| 29 | Plugin ZIP generated & verified | ✅ pass | `dist/simplead-backup.zip` rebuilt w/ security fixes + sha256 |
| 30 | Tested rollout + rollback | 🟡 pass* | runbooks + flag-off inertness + self-rollback proven. *rungs 2+ owner-gated; `backup:v2-reap-uploads` command still TODO-PROD |

**Tally: 26 ✅ pass · 4 🟡 pass-with-documented-caveat · 0 fail.**

## Verdict: READY FOR PILOT (lab-proven)

The DoD is substantially green in the lab. The 4 caveated items are documented in
`KNOWN-LIMITATIONS.md` and are exactly what the graduated rollout ladder accounts for.

**The real pilot does NOT start autonomously.** Per `ROLLOUT-RUNBOOK.md`, Rung 2
(1 real site on Hetzner/prod) requires:
1. the owner instruction **`DA PILOT BACKUP V2`**, and
2. per-site plugin API keys provisioned (`sam_backup_api_key`/`secret`) + the prod
   S3 resolver exercised against real Hetzner (only MinIO proven so far).

Until then the engine stays inert (all flags false, `/backup-v2` 404s in prod, no V2
jobs on Horizon, V1 untouched).
