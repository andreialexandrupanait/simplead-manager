# Current Backup System — Audit

> Read-only audit of the existing backup/restore implementation. Sources: static code audit
> (Laravel backend, WP connector, manager UI) + read-only production queries (2026-07-25).
> **No backups or restores were triggered; nothing in production, storage, scheduler, Horizon,
> or client sites was modified.**

## 1. Verdict

The system is **not** a thin zip+mysqldump wrapper. It is a mature, actively-used engine with
chunked resumable backups, S3 multipart, incremental file diff + chains, atomic staged
restore, sandbox proven-restore, and chain-aware retention. Its problems are **specific and
fixable**, not architectural bankruptcy — but several are P0 (integrity, transfer security,
transport reliability, silent failures).

**Production reality (Mar 12 – Jul 25 2026, 1,319 backups):**

| Metric | Value |
|---|---|
| Total backups | 1,319 |
| Completed | 1,253 (95.0% all-time) |
| Failed | 60 |
| Cancelled | 6 |
| Currently stuck (pending/in_progress) | **0** (recovery is working *now*) |
| Success — last 30d | **91.2%** (527/578, 51 failed) |
| Success — last 7d | ~96.5% (140/145) |
| Backup types | full 1,291 · incremental 23 (1.7%) · database 5 |
| Formats | v3-zip 839 · v2-zip 470 · direct-s3 10 |
| Triggers | scheduled 1,283 · manual 34 · manual_bulk 2 |
| Completed duration | avg 342s · p50 274s · p95 884s · max 4,288s |
| Completed size | avg ~1.17 GB · max ~4.0 GB |
| Sites | 40 total · 23 backup-enabled (all daily-full) · 24 with any backup |
| Stale (enabled, >48h no success) | 2 · never backed up 1 |

## 2. Why backups fail — root causes (from live `error_message`)

Failures are **overwhelmingly upload/transport**, not stuck jobs and not the manifest issue:

| Cause (grouped) | ~count | Layer |
|---|---|---|
| S3 multipart "parts had errors" | 33 | Upload transport (S3Driver / WP push) |
| Per-part upload timeout (cURL 28, 90s) | 5 | WP→S3 per-part push (Cloudflare ~100s gateway) |
| Cloudflare **HTTP 522** (origin timeout) on prepare/chunk | ~6 | WP endpoint under long op |
| Chunk exec **HTTP 500 / 503** | ~5 | WP chunk build failing/overloaded |
| Stream download reset / HTTP 200 recv-failure | ~4 | Manager pull from WP |
| cURL 28 connect/read timeouts (15/30s) | ~4 | Network |
| DNS resolution failure (`motivonti.ro`) | 1 | Site DNS |
| "attempted too many times" | 1 | Job retry exhaustion |

**Interpretation.** The engine's *logic* is sound; failures cluster at the **WP↔S3↔manager
transport boundary** — long synchronous operations colliding with Cloudflare/gateway/network
timeouts, and S3 multipart parts failing without fine-grained resume. This is the single
biggest lever for raising success rate.

## 3. Component inventory (Laravel backend)

### Models (`app/Models/`)
- `Backup` (`backups`) — status/`restore_status` → `BackupStatus` enum; `replicas` jsonb;
  `format` (v2-zip/v3-zip/multipart-v3/direct-s3); `manifest_path`, `parent_backup_id`
  (self-relation → chains), `verification_status`, `is_locked`, `expires_at`.
- `BackupConfig` (`backup_configs`) — per-site schedule; **`exclude_paths`/`exclude_tables`
  columns exist but are dead** (not fillable/cast; 0 rows use them).
- `StorageDestination` (`storage_destinations`) — `config` jsonb (encrypted creds),
  `used_bytes`/`quota_bytes`, `last_test_*`. Prod: #1 Dropbox, #2 Hetzner (default).
- `ProvenRestore` (`proven_restores`) — **0 rows in prod (never run)**.
- `AppBackup`/`AppBackupConfig` — whole-platform backups (status **stringly-typed**, has
  undeclared `degraded`, no site lock, `tries=1`). Prod: 7 completed, 2 failed.
- `RollbackPoint` — update rollback markers (distinct from full backups).
- No dedicated session/chunk/object/manifest/log models — those live in jsonb/sidecars/`app_settings`.

### Jobs (`app/Jobs/`, queue `backups` unless noted)
- `CreateBackup` — `ShouldBeUnique` (`backup-{siteId}`), timeout 2700, tries 2; pull
  (`runV3ZipPipeline`) + push (`runDirectUploadPipeline`, presigned multipart) paths;
  release-based polling; **sidecar+manifest generation is "non-fatal"**.
- `CreateIncrementalBackup` — file diff via WP `/backup/incremental-init`; falls back to full
  if no parent manifest.
- `RestoreBackup` — timeout 3600, real work never retries; sync (**1800s single call**) or
  async transport per connector capability.
- `ReplicateBackup` — 3-2-1 pull-then-push to secondary; failure does not fail the backup.
- `RetentionCleanup` — nightly; **dry-run default ON** (`config/backups.php`).
- `RunBackupVerification` (on-demand test restore), `RunProvenRestore` (weekly sandbox).
- `NotifyBackupFailed`/`NotifyRestoreFailed` (queue `notifications`).

### Services (`app/Services/Backup/`)
- `Storage/`: `S3Driver` (auto-multipart >100MB, presigned parts +4h, initiate/complete/abort),
  `DropboxDriver` (8MB chunked sessions, OAuth refresh, `listRecursive` **unreliable — see §5**),
  `LocalDriver`, `StorageFactory`.
- `IntegrityVerifier` (v1/v2/v3/multipart), `BackupVerifier`, `SqlDumpParser`.
- `RetentionService` (chain-aware) + `RetentionPolicyService`.
- `SiteOperationLock` (cross-class DB mutex, 7200s), `DiskSpaceGuard` (10GB min, **fails open**
  when unmeasurable).
- `ManifestService` (chain resolution), `BackupManifestV3`, `BackupSidecarMetadata`,
  `BackupZipBuilder`, `SafeZipExtractor` (zip-slip guard).
- `PostRestoreVerifier`, `SandboxRestoreService`, `BackupHealthService`, `BackupBrowserService`.

### Dispatcher / scheduler / commands
- `BackupDispatcher` (per-minute): stale recovery (in_progress >20min heartbeat, pending
  stagger-aware), circuit breaker, disk guard, stagger dispatch (180s), full-vs-incremental
  decision. **Does not recover stuck restores** (delegated to command).
- `routes/console.php`: `RetentionCleanup` (03:00), `backup:verify-restore` (weekly, Level-B
  sample 3), `RunProvenRestore` (weekly), `backups:recover-stuck-restores` (15min),
  `backup:cleanup-temp` (04:30), `db:dump`/`db:dump-offsite` (platform PG), app-backup crons.
- DR tools: `backup:reindex-from-storage` (rebuild rows from sidecars), `backup:release-lock`.

### Enums / config / routes
- `BackupStatus`: Pending/InProgress/Completed/Failed/Cancelled (used for both backup and
  restore status). No verification/app-backup enum.
- `config/horizon.php`: `supervisor-backups` (queue `backups`, mem 1024MB, timeout 3600, tries 2,
  maxProcesses 2 prod).
- Routes: `POST /backup/callback` (HMAC `X-Backup-Token`), `BackupRelayController`,
  `GET /backups/{backup}/download` (signed), **`GET /restore-download/{token}` (unauthenticated,
  64-hex, 45-min)**, `download.connector-plugin.signed`.

## 4. Component inventory (WordPress connector)

All backup/restore lives in **one 3,384-line file**: `class-backup-endpoint.php` (+
`class-direct-uploader.php` transport). REST namespace `simplead/v1`. Plugin `SAM_VERSION 2.19.0`.

- **DB export**: pure PHP paged dump (no mysqldump/WP-CLI), `SHOW CREATE` + paged `SELECT`,
  streamed + gzip. **Full dump every time** (no DB-level increment).
- **Files**: `ZipArchive` + `RecursiveDirectoryIterator`, `is_within_abspath()` containment.
- **Chunking**: DB grouped to ~2MB/chunk (large tables row-range split); files grouped by
  wp-content child, >200MB split; incremental via manifest size+mtime diff.
- **Transport**: `s3_multipart` (per-part cURL PUT, 3 retries, ETag), `s3_multipart_per_part`
  (one part per HTTP request — to stay under Cloudflare ~100s), `chunked_push` relay.
- **Async/resume**: chunk sessions in `sys_get_temp_dir()/sam_prepared` with `.done` markers;
  task state in transients; detach via **loopback → WP-Cron** (fire-and-forget, **cannot
  confirm worker started** → 7200s stale locks). No Action Scheduler.
- **Restore**: staged `samstg_*` DB swap (atomic `RENAME TABLE`, connector self-preservation),
  journaled file swap (`wp-config.php` never swapped), maintenance mode.
- **Auth**: HMAC-SHA256 + timestamp (±300s) + nonce (**legacy no-nonce still accepted**).
- **Capabilities**: `/backup/capabilities` reports chunked/direct-upload/incremental/async +
  tools (mysqldump=false, tar=false) + limits.

## 5. Storage reality (read-only)

| Destination | App `used_bytes` | Objects listed | Listed size | Note |
|---|---|---|---|---|
| #2 Hetzner (primary) | 1,055 GB | 2,504 | **1,178 GB** | 852 site `.zip` + 882 `.meta.json` sidecars + platform `.gz`/`.enc` dumps + **Coolify's own `data/coolify/backups/`** (shared bucket) |
| #1 Dropbox (legacy primary / replica) | **2,346 GB** | `listRecursive`→5,320 | **only 50.8 GB** | `listRecursive('')` returns only a legacy `websites/` folder (86 old zips + a 4,816-file raw `vechi.feco.ro` copy) |

**Two hard findings:**
1. **Dropbox `listRecursive` is unreliable** — it returned 50.8 GB while `exists()` on exact
   `file_path`s finds current objects the listing omits (e.g. backup #600). Any DR/reindex tool
   depending on `listRecursive` over Dropbox is unsafe.
2. **`used_bytes` accounting is untrustworthy** — Dropbox reports 2,346 GB; no read-only method
   reconciles that with reality. Storage-quota UI and retention sizing are built on drifting numbers.

## 6. Data-integrity findings (DB, read-only)

- **191 completed backups have no manifest** (185 full) — the "non-fatal" manifest path lets a
  backup complete without the artifact future **incrementals and reindex** depend on. Real, but
  it degrades capability rather than failing the backup.
- All 1,253 completed have a **checksum**; 0 zero-byte; 0 rows with NULL `file_path`; 0
  expired-but-present; 0 locked/protected.
- 23 incrementals, all with a completed parent (no broken chains in DB).
- Verification: 849 passed · 401 completed never_tested · 3 failed.
- `restore_status` is NULL for all rows (restores not tracked in this column recently).
- Replicas: 849 completed have a replica (all Hetzner-primary → Dropbox), 404 (Dropbox-primary
  era) have none.
- `proven_restores` **empty** — proven-restore has never produced a record in prod.

## 7. Storage-vs-DB reconciliation (authoritative `exists()`/`size()`)

> Full per-object HEAD/metadata over all completed backups; see
> [`EXISTING-BACKUPS-INVENTORY.md`](EXISTING-BACKUPS-INVENTORY.md) for per-category counts and
> [`existing-backups.csv`](../../storage/app/backup-audit/existing-backups.csv).

- **Hetzner (current primary):** stratified + full sampling → **100% present, sizes match**.
- **Dropbox (legacy primary, Mar–Apr 2026):** high miss rate at recorded `file_path`
  (sample: 7/9 missing Mar–Apr; present May onward). Likely a path-convention change or
  out-of-band cleanup (retention dry-run is ON, so retention did not delete them). Flagged for
  targeted verification; **no deletion performed or proposed here**.

## 8. Top fragilities (ranked)

1. **P0 Integrity-at-completion** — manifest/sidecar/`restore.json`/completion-marker optional;
   `completed` does not guarantee a reindexable, incrementable, restorable artifact.
2. **P0 Transport reliability** — S3 multipart part failures + Cloudflare/gateway timeouts are
   the dominant real failure cause (see §2).
3. **P0 Transfer security** — unencrypted site archives; unauthenticated `/restore-download`;
   4h presigned part URLs.
4. **P0 Silent failures** — pervasive `@`-suppressed cleanup and "non-fatal" swallows hide real
   problems (accumulating temp/staging, missing manifests).
5. **P1 Storage accounting** — `used_bytes` drift + Dropbox `listRecursive` blindness break
   quota/retention/DR assumptions.
6. **P1 Legacy sync endpoints** — single-request `/backup/db|files|restore` + 1800s sync
   restore remain reachable and time out at scale.
7. **P1 WP-Cron/loopback reliance** — detached work + temp cleanup depend on cron firing.
8. **P2 Missing parity** — exclusions (dead columns), files-only, multisite, component-preset
   restore, success/limit notifications, proven-restore actually running.

## 9. Confirmation

Audit was strictly read-only: schema introspection, `SELECT`/`\copy`, and storage `list`/`HEAD`
via the app's own drivers. No writes, no backups, no restores, no deletions, no changes to
retention/destinations/scheduler/Horizon, no plugin distribution, no client-site operations.
