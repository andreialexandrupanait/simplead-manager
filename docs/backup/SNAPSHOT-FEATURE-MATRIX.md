# Snapshot 4.0 — Feature Parity Matrix

> **Purpose.** Independent functional parity target for the Simplead backup engine,
> benchmarked against WPMU DEV **Snapshot 4.0** behavior. This documents *capabilities and
> flows only* — no WPMU proprietary code, branding, texts, icons, or internal structure is
> reused. "Simplead status" columns are grounded in the code audit (Laravel backend + WP
> connector `class-backup-endpoint.php` + manager UI) and the read-only production audit
> (2026-07-25, 1,319 backups Mar–Jul 2026).
>
> Legend — **Status**: ✅ full · 🟡 partial · ⛔ missing · 🔴 broken/unreliable.
> **Priority**: P0 (parity-critical) · P1 (important) · P2 (nice-to-have).

## Summary

Estimated Snapshot 4.0 functional coverage **today: ~62%** (weighted by parity-criticality).
The engine already implements the hard parts (chunked resumable backups, multipart upload,
incremental file diff + chains, staged atomic restore, sandbox proven-restore, chain-aware
retention). The gaps are concentrated in: **content selection / exclusions**, **selective
restore of components (plugins/themes/uploads/core)**, **integrity guarantees at completion**
(manifest-optional), **archive encryption**, **multisite**, and **operator-facing management
surfaces** (verification history, global retention, notifications on success).

| Band | Count |
|---|---|
| ✅ Full | 14 |
| 🟡 Partial | 16 |
| ⛔ Missing | 8 |
| 🔴 Broken/unreliable | 4 |

---

## Matrix

### A. Configuration & scheduling

| # | Feature | Snapshot behavior | Simplead status | Notes / evidence | Recommendation | Prio | Acceptance test |
|---|---|---|---|---|---|---|---|
| 1 | Activation & configuration | Plugin activates, config presets, save/reuse configs across sites | 🟡 | Per-site `BackupConfig` + `BackupScheduleForm`; no reusable named presets across sites | Add config presets (fleet templates) | P2 | Apply a preset to N sites → identical effective schedule |
| 2 | Manual backup | On-demand backup anytime | ✅ | `WithBackupActions::backupFull/Database/Incremental`, `CreateBackup` (`trigger=manual`); prod shows 34 manual | — | P0 | Trigger manual → completed within SLA |
| 3 | Scheduled backup | Daily/weekly/monthly, day/time | ✅ | `BackupDispatcher` (per-minute, stagger), `frequency`/`time`/`day_of_week`; prod: 23 enabled, all daily full | — | P0 | Due config dispatches once at window |
| 4 | Full initial (base) backup | Full site snapshot, chain base | ✅ | `CreateBackup` v3-zip; base for incrementals | — | P0 | Full backup produces complete restorable archive |
| 5 | Incremental backup | Changes since previous, chained | 🟡 | `CreateIncrementalBackup` (file-level diff via WP `/backup/incremental-init`), chain via `parent_backup_id`; **only 23 in prod (1.7%)**, DB is full-dump each time (by design) | Make incremental first-class; require manifest (see #17) | P0 | Incremental stores only changed files + tombstones |
| 6 | Frequencies | Daily/weekly/monthly | 🟡 | Model supports all; `incremental_frequency`/`full_backup_day_of_week` exist; prod only uses daily-full | Wire weekly/monthly + full/incremental cadence in UI | P1 | Configure weekly → fires on chosen weekday only |

### B. Content selection

| # | Feature | Snapshot behavior | Simplead status | Notes / evidence | Recommendation | Prio | Acceptance test |
|---|---|---|---|---|---|---|---|
| 7 | Content selection | Choose DB/files/full | 🟡 | `type` = full/database; no "files-only" backup type | Add files-only backup type | P1 | files-only backup omits DB dump |
| 8 | Database-only | Backup DB only | ✅ | `type=database`; prod: 5 database backups | — | P1 | DB-only archive contains only SQL |
| 9 | Files-only | Backup files only | ⛔ | No files-only backup path (only DB-only or full) | Add | P1 | files-only archive contains no SQL |
| 10 | Full-site | DB + files | ✅ | `type=full` (default); 1,291 in prod | — | P0 | full archive has DB + files |
| 11 | Exclusions | Exclude files/folders/tables, large-file detection | 🔴 | `backup_configs.exclude_paths`/`exclude_tables` columns exist but **dead** (not in model fillable/casts, 0 rows use them); WP `should_exclude()` exists but not driven by config | Wire exclusions end-to-end (config → connector) + large-file pre-scan | P1 | Excluded path absent from archive manifest |
| 12 | Multisite | Network or per-subsite | ⛔ | `is_multisite` only *reported*; backup/restore has no network/subsite semantics (`wp_blogs`/`sitemeta` unhandled) | Design multisite handling (network + per-subsite) | P2 | Network backup restores all subsites coherently |

### C. Storage

| # | Feature | Snapshot behavior | Simplead status | Notes / evidence | Recommendation | Prio | Acceptance test |
|---|---|---|---|---|---|---|---|
| 13 | Local storage | Store on server | 🟡 | `LocalDriver` + `local_export_*` columns (download/export path) | Keep as export target | P2 | Local export produces downloadable zip |
| 14 | Remote storage | Off-site destinations | ✅ | `StorageDestination` + drivers; prod: Hetzner (primary) + Dropbox | — | P0 | Backup lands in remote bucket |
| 15 | S3 / S3-compatible | S3 & compatibles | ✅ | `S3Driver` (Hetzner/B2/S3), path-style, presigned | — | P0 | Upload to S3-compatible succeeds |
| 16 | Multipart upload | Large-file multipart | 🟡 | `S3Driver` auto-multipart >100MB + presigned per-part push; **but #1 failure source** (33 multipart part failures + timeouts in prod) | Harden: ret/resume per part, smaller parts, integrity per part | P0 | 3GB file uploads via resumable multipart |
| 17 | Progress | Real-time progress | ✅ | `stage`/`progress_percent`/`progress_message`, `JobTracker`, `WithBackupProgress` polling | — | P1 | UI shows advancing stages |
| 18 | Logs | Filterable logs, downloadable | 🟡 | Progress log + Laravel logs; no per-backup filterable/downloadable log object | Add structured per-backup log | P1 | Download a backup's log report |
| 19 | Retry | Auto-retry transient failures | 🟡 | `auto_retry_count`, job `tries`, dispatcher stuck-recovery retry ≤2; no per-stage retry UI | Per-stage retry + backoff surfaced | P1 | Transient part failure retries, not whole backup |
| 20 | Resume | Resume after interruption | 🟡 | WP chunk sessions resumable (`.done` markers); manager polls via release; **but sync legacy endpoints exist w/o resume** | Remove sync endpoints; make every stage resumable | P0 | Kill mid-upload → resumes from last chunk |

### D. Retention & lifecycle

| # | Feature | Snapshot behavior | Simplead status | Notes / evidence | Recommendation | Prio | Acceptance test |
|---|---|---|---|---|---|---|---|
| 21 | Retention | Count/time-based, chain-safe | 🟡 | `RetentionService` (chain-aware, count/days) — **but dry-run default ON in prod** (may not prune) | Verify/enable; add fleet retention policy | P0 | Expired backup pruned; chain base preserved |
| 22 | Deletion | Manual delete (newest first for chains) | ✅ | `deleteBackup`/`bulkDelete` → `RetentionService::purge` w/ chain guard | — | P1 | Delete removes row + object + sidecar |
| 23 | Protection | Protect from deletion | ✅ | `is_locked`/`lock_reason`, `toggleLock`; pre-update lock window | — | P1 | Locked backup survives retention |
| 24 | Download / export | Zip download, email export | 🟡 | Signed local download + `temporaryUrl`; **no email-export-with-expiry**; partial exports (files/db-only) not offered | Add email export + partial export | P2 | Signed URL downloads exact archive |

### E. Restore

| # | Feature | Snapshot behavior | Simplead status | Notes / evidence | Recommendation | Prio | Acceptance test |
|---|---|---|---|---|---|---|---|
| 25 | Full restore | DB + files | ✅ | `RestoreBackup` → connector staged atomic restore | — | P0 | Full restore reproduces site |
| 26 | Selective restore | Choose components | 🟡 | `RestoreConfirmation` file-tree + DB/files toggles + selective folders; **no component presets (plugins/themes/uploads/core)** | Add component presets | P1 | Restore only chosen folder subtree |
| 27 | Database restore | DB-only | ✅ | Connector `restore_database` staged `samstg_*` swap | — | P0 | DB-only restore atomic |
| 28 | Files restore | Files-only | ✅ | Connector `restore_files` merge/staged | — | P1 | Files-only restore keeps DB |
| 29 | Plugins restore | Just plugins | 🟡 | Achievable via selective folder (`wp-content/plugins`) but not a first-class option | Add preset | P2 | Restores plugins dir only |
| 30 | Themes restore | Just themes | 🟡 | Via selective folder | Add preset | P2 | Restores themes dir only |
| 31 | Uploads restore | Just uploads | 🟡 | Via selective folder | Add preset | P2 | Restores uploads dir only |
| 32 | Core restore | WP core files | 🟡 | Selective folder possible; `wp-config.php` deliberately never swapped | Add core preset w/ wp-config policy | P2 | Core restore preserves wp-config |
| 33 | Restore confirmation | Explicit confirm | ✅ | Typed domain confirmation + mandatory pre-restore safety backup | — | P0 | Restore blocked without confirmation |
| 34 | Maintenance mode | Site in maintenance during restore | ✅ | Connector writes `.maintenance` during swap window | — | P1 | Frontend shows maintenance during swap |
| 41 | Restore after interruption | Resume/rollback on failure | 🟡 | Journaled file swap + reverse rollback + `RecoverStuckRestores`; **but single 1800s sync restore call if `async_restore` unadvertised** | Force async transport; per-step resume | P0 | Killed restore rolls back cleanly |

### F. Integrity, verification & management

| # | Feature | Snapshot behavior | Simplead status | Notes / evidence | Recommendation | Prio | Acceptance test |
|---|---|---|---|---|---|---|---|
| 35 | Integrity verification | Pre/post checks, checksums | 🟡 | Creation-time `verifyV3Zip`, sha256 (all completed have checksum), Level-B weekly sample; **191 completed lack manifest**, `restore.json`/completion-marker not enforced | Make manifest+marker mandatory for `completed` | P0 | `completed` implies manifest+checksum+marker valid |
| 36 | Central Hub management | Fleet overview, storage, restore | 🟡 | `BackupsOverview` (health, stale, storage), `GlobalDashboard`; no destination-health/verification-history fleet views | Add fleet verification + destination health | P1 | Fleet view lists per-site health + failures |
| 37 | Local WP management | In-plugin status/diagnostics | 🟡 | Connector `/backup/capabilities`, `/health`, `/info`; no dedicated backup admin UI in plugin | Minimal local status/diagnostics screen | P2 | Plugin shows current job + last backups |
| 38 | Notifications | Success + failure emails | 🟡 | **Failure only** (`NotifyBackupFailed`/`NotifyRestoreFailed` + scheduled-task alerts); no success/limit emails | Add success + storage-limit notifications | P1 | Failure and success both notify |
| 39 | Limits | Storage/exec/memory limits handling | 🟡 | `DiskSpaceGuard`, capability limits reported; `used_bytes` **drift-prone** (Dropbox 2,346GB acct vs ~50GB listed) | Reconcile accounting from storage truth | P1 | `used_bytes` matches summed objects ±1% |
| 40 | Error handling | Pre-scan warnings, graceful failures | 🟡 | Rich error capture; **but `@`-suppressed cleanup + "non-fatal" manifest/sidecar swallow real problems** | Remove silent swallows; pre-backup scan | P0 | Failure surfaces actionable error code |
| 42 | Backup chains | Chain-aware ops (delete/restore) | 🟡 | `ManifestService` chain resolution, retention chain guard; only 23 incrementals in prod so lightly exercised | Exercise chains at scale; verify chain restore | P1 | Restore from full+increments reproduces latest state |

### G. Security (cross-cutting, Snapshot-adjacent)

| # | Feature | Snapshot behavior | Simplead status | Notes / evidence | Recommendation | Prio | Acceptance test |
|---|---|---|---|---|---|---|---|
| S1 | Encryption at rest | Credentials secured server-side | 🔴 | Site archives stored **unencrypted** (sha256 only); creds encrypted via APP_KEY; platform DB dumps `.enc` but not site backups | Add S3 SSE + optional client-side AES-256-GCM | P0 | Bucket object is ciphertext / SSE-enabled |
| S2 | Transfer auth | Secure signed transfer | 🔴 | `/restore-download/{token}` **unauthenticated** (64-hex, 45-min); 4h presigned part URLs | Authenticate transfer; shrink URL TTL | P0 | Staged file requires signed+authenticated fetch |
| S3 | Replay protection | — | 🟡 | HMAC-SHA256 + timestamp + nonce, **but legacy no-nonce signatures still accepted** | Require nonce | P1 | No-nonce request rejected |

---

## Cross-references
- Current implementation detail → [`CURRENT-BACKUP-AUDIT.md`](CURRENT-BACKUP-AUDIT.md)
- Existing backup classification → [`EXISTING-BACKUPS-INVENTORY.md`](EXISTING-BACKUPS-INVENTORY.md)
- Target design → [`TARGET-ARCHITECTURE.md`](TARGET-ARCHITECTURE.md)
- Acceptance criteria detail → [`ACCEPTANCE-TESTS.md`](ACCEPTANCE-TESTS.md)
