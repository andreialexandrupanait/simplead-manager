# simplead-backup V2 — FINAL ARCHITECTURE (as implemented)

> The architecture **as built and proven** on branch `feature/simplead-backup-production-ready`.
> This documents what the code does, not an ideal. Decisions are anchored in
> [`DECISION-LOG.md`](DECISION-LOG.md) (D-001..D-013, D-STORAGE) and the approved design in
> [`TARGET-ARCHITECTURE.md`](TARGET-ARCHITECTURE.md). Status of each phase: [`PROJECT-STATUS.md`](PROJECT-STATUS.md).
>
> **Safety contract:** every V2 enable flag in `config/backup_v2.php` defaults to `false`. With
> defaults, V2 is completely inert in production (no scheduler, no queue, no restore, no reconcile
> writes, no legacy restore). See [`ROLLOUT-RUNBOOK.md`](ROLLOUT-RUNBOOK.md).

Legend: **[IMPLEMENTED+PROVEN]** exercised by lab tests · **[TODO-PROD]** production hardening still open.

---

## 1. Two-sided system

```
┌──────────────────────── manager.simplead.ro (Laravel 12) ─────────────────────────┐
│  App\Backup\V2\*  — namespace-isolated, registered by BackupV2ServiceProvider      │
│                                                                                    │
│  FSM sessions        BackupSession / RestoreSession   (explicit state machines)    │
│  Orchestration       BackupRunner · RestoreRunner · SessionActions                 │
│  Storage             HardenedMultipartUploader · ObjectLayout · S3ClientFactory ·  │
│                      MultipartProgressStore (confirmed_parts jsonb)                 │
│  Chain               ChainResolver · ManifestReader / S3ManifestReader             │
│  Retention           ChainRetentionService (chain-safe, dry-run default)           │
│  Verification        BackupVerifier (at-creation) · DeepVerifyService (sampled)    │
│  Proven restore      ProvenRestoreService (sandbox restore + health-check)         │
│  Legacy              LegacyBackupReader · LegacyImportService (read-only index)     │
│  Quota / Alerts      QuotaService · BackupV2Notifier                               │
│  Plugin client       SimpleadBackupClient (HMAC+nonce)  implements PluginClient +  │
│                      RestoreClient                                                  │
│  UI (Livewire)       App\Livewire\Backup\V2\* under /backup-v2 (flag+admin gated)  │
└───────────▲────────────────────────────────────────────────────────▲──────────────┘
            │ HMAC-SHA256 + mandatory nonce (X-SAM-Backup-*)           │ AWS SDK (pull model)
            │ REST namespace simplead-backup/v1                        │ multipart, short-TTL presign
   ┌────────┴─────────────────────┐                          ┌────────┴──────────────────┐
   │  simplead-backup (WP plugin)  │                          │  Hetzner S3 (EU) / MinIO   │
   │  capabilities discovery       │                          │  clients/{c}/sites/{s}/    │
   │  consistent DB dumper         │  chunk sessions (temp)   │    backups/{b}/...         │
   │  inventory · exclusions ·     │  pull-and-free segments  │  SSE + versioning          │
   │  chunker · file-diff          │                          └────────────────────────────┘
   │  staged atomic restore engine │
   └───────────────────────────────┘
```

The **manager holds the S3 credentials** and the WP plugin never sees them (pull model, D-005). The
plugin serves DB/file segments over signed HTTP; the manager pulls each segment, uploads it to S3, and
frees it on the WP host (`delete=1` pull-and-free). No single HTTP request spans a whole backup/restore.

---

## 2. Manager components

### 2.1 FSM sessions

| Concern | Backup | Restore |
|---|---|---|
| State enum | `Enums\BackupSessionState` (17 states) | `Enums\RestoreSessionState` (14 states) |
| Graph | `StateMachine\BackupStateMachine` | `StateMachine\RestoreStateMachine` |
| Model | `Models\BackupSession` (`backup_sessions`) | `Models\RestoreSession` (`restore_sessions`) |
| Transition | `transitionTo()` → `assertTransition()` | same |
| Illegal edge | throws `Exceptions\IllegalStateTransition` | same |

**Rules (D-009):** the graph is an explicit adjacency map; illegal edges throw hard; a self-transition
is an idempotent no-op (redelivery-safe). `isActive() == !isTerminal()`.

- **Backup happy path:** `requested → capability_check → inventory → database_export → file_diff →
  chunking → upload_initializing → uploading → upload_verifying → finalizing → completed`.
  Side states: `retry_wait`, `paused`, `cancelling → cancelled`, `failed`, `corrupt`.
  Every *processing* state may branch to `retry_wait / paused / cancelling / failed`. Only
  `upload_verifying` and `finalizing` may reach `corrupt` (late integrity failure). Terminal:
  `completed / cancelled / failed / corrupt`.
- **Restore happy path:** `requested → validating_backup → pre_restore_backup → downloading →
  decrypting → verifying → maintenance → database_restore → file_restore → cleanup →
  post_restore_validation → completed`. Side states: `rollback`, `failed`. `rollback` is only legal
  from *mutating* states (`maintenance` onward) and always resolves to `failed` — **a successful
  rollback is a failed restore with the site returned to its pre-apply state, never `completed`**.

Typed errors: `Enums\BackupErrorCode` (11 codes: `object_missing`, `checksum_mismatch`, `disk_full`,
`host_timeout`, `manifest_invalid`, `snapshot_unavailable`, `upload_failed`, `callback_lost`,
`broken_chain`, `restore_apply_failed`, `post_restore_validation_failed`); `isTransient()` advises
retry vs hard-fail.

### 2.2 BackupRunner (`Orchestration\BackupRunner`) [IMPLEMENTED+PROVEN]

Drives one `BackupSession` through the **entire** FSM (each step via `transitionTo` — no shortcuts).
Endpoint ↔ state mapping:

| State | Work |
|---|---|
| `capability_check` | plugin `capabilities()`; records host DB/PHP/WP; if DB in scope and host cannot take a consistent snapshot → records `snapshot_unavailable` warning |
| `inventory` | plugin `files/inventory` → saves `exclusion_policy_hash` + `scope_hash`; incremental sends `base_manifest` |
| `database_export` | plugin `database/dump` then per-segment `database/chunk-download` (pull-and-free) → S3 `database/` |
| `file_diff` | records incremental diff / chain fields for the manifest |
| `chunking` | no-op (plan persisted plugin-side at inventory) |
| `upload_initializing` | reaps abandoned multipart uploads under this backup's prefix |
| `uploading` | per chunk: `files/chunk-exec` + `files/chunk-download` (pull-and-free) → S3 `files/` |
| `upload_verifying` | `headObject` size + re-download & sha256 per confirmed object |
| `finalizing` | write `manifest.json` / `checksums.json` / `metadata.json`, then **`_COMPLETE` LAST**, then `completed`; runs `BackupVerifier::verifyOnComplete` |

**Idempotence/resume (D-011b):** every uploaded object is recorded in `confirmed_objects` (jsonb); a
resumed run skips confirmed objects (pull-and-free makes a re-pull impossible anyway); the DB dump is
short-circuited once `checkpoint.db_done` is set. Multipart resume is a layer down via `confirmed_parts`.

**Verify-before-complete (D-011c):** any missing/mismatched object (size or sha256), or a dump reporting
`done=false`/`consistent=false`, throws `CorruptBackupException` → `corrupt`, **never `completed`**.

### 2.3 RestoreRunner (`Restore\RestoreRunner`) [IMPLEMENTED+PROVEN]

Drives one `RestoreSession`. Resolves the target + chain (refuses a `BrokenChainException` before any
work), asserts each chain member's `_COMPLETE`, builds a `RestorePlan`, takes a pre-restore safety
backup (mandatory for MIRROR), pulls each planned object from S3 and pushes it to plugin staging
(resumable via `checkpoint.staged`), verifies staged sha256, then `restore/apply` (atomic DB+file swap),
`restore/commit` on a passing health-check, or `restore/rollback` on failure. Uses `RestoreMode`
(`safe_merge` default / `mirror` destructive). See [`RESTORE.md`](RESTORE.md).

- **`decrypting` is a placeholder** [TODO-PROD]: lab objects are stored in clear; decryption will hook
  here when client-side encryption lands.

### 2.4 Storage

- **`Storage\HardenedMultipartUploader`** [IMPLEMENTED+PROVEN] — pull model, parts default 16 MiB
  (clamped to S3 5 MiB floor), per-part retry with exponential backoff + full jitter, per-part checksum
  (sha256 + Content-MD5, ETag verified == md5(part)), resume from `confirmed_parts` (same UploadId, no
  re-read/re-upload), clean abort verified via `ListMultipartUploads`, `reapAbandonedUploads()` reaper.
  `presignPartUrl()` retained at short TTL for a future direct-from-WP push. Concurrency 1.
  MinIO ignores server-side `Prefix` on `ListMultipartUploads`, so the reaper filters **client-side**.
- **`Storage\ObjectLayout`** — pure value object expanding `config('backup_v2.object_prefix')`
  (`clients/{client_id}/sites/{site_id}/backups/{backup_id}`) into every artifact key.
- **`Storage\S3ClientFactory`** — lab (MinIO) factory implemented; `forDestination()` production path
  (decrypt creds from `StorageDestination`) is **[TODO-PROD]**.
- Progress stores: `MultipartProgressStore` (interface), `BackupSessionProgressStore`
  (`confirmed_parts` jsonb), `InMemoryProgressStore` (tests).

### 2.5 Chain (`Chain\*`) [IMPLEMENTED+PROVEN]

`ChainResolver`: `resolveChain()` orders `[full, inc_1, …, target]` from `full_base_id` + `chain_position`,
refusing a broken chain (missing/incomplete base, gap/duplicate position) with `BrokenChainException`;
`materialize()` applies full then each incremental (new/changed overwrite by path, tombstones delete) →
the exact final file-state; `baseFileState()` flattens the base chain into the plugin's `base_manifest`
format. `ManifestReader` is abstract; `S3ManifestReader` reads `manifest.json` from S3 (in-memory in
tests). The **DB is never part of chain materialisation** — every backup carries a full dump.

### 2.6 Retention (`Retention\ChainRetentionService`) [IMPLEMENTED+PROVEN]

Operates on `backup_sessions` (never V1 `backups`). The atomic unit is the **chain**. Never deletes: a
full with an in-window incremental, the last valid full, the last verified session, a `protected`
session — a chain is selected only when **every** member is expired. **Dry-run by default**
(`config('backup_v2.retention_dry_run')`, default `true`); a real delete requires `apply(force: true)`
**and** `config('backup_v2.enabled')`. Storage deletion is an injected closure. Returns a `RetentionPlan`.

### 2.7 Verification & proven restore

- `Verification\BackupVerifier::verifyOnComplete()` — runs at `completed`; checks manifest + `_COMPLETE`
  + per-object size/sha256, writes a `backup_verifications` row, and on pass stamps `verified_at` (which
  retention reads). Deliberately non-fatal (verify-before-complete already guaranteed wholeness).
- `Verification\DeepVerifyService` + `backup:v2-deep-verify` — sampled deep check (opens archives, parses
  SQL, composite).
- `ProvenRestore\ProvenRestoreService` + `backup:v2-proven-restore` — sandbox restore + health-check that
  **writes a real `ProvenRestore` row** (`passed`/`failed`), closing the "proven_restores empty / 0-rows"
  defect. Gated by `proven_restore_enabled`.

### 2.8 Legacy (`Legacy\*`) [IMPLEMENTED+PROVEN]

`LegacyBackupReader` reads old v2-zip / v3 artifacts **read-only**. `LegacyImportService` +
`backup:v2-import-legacy` classify legacy backups (A–F) into a read-only `legacy_backup_index`; nothing
is moved or deleted. Actually restoring a legacy artifact is gated by `legacy_restore_enabled`. See
[`LEGACY-COMPATIBILITY.md`](LEGACY-COMPATIBILITY.md).

### 2.9 UI, quota, notifications

- **UI** — `App\Livewire\Backup\V2\*` (`BackupV2Overview`, `SiteBackupV2`, `BackupV2Detail`) under the
  `/backup-v2` route prefix, double-gated by middleware `EnsureBackupV2Ui` (404 when `ui_enabled` off)
  and a per-component admin check. V1 UI/routes untouched.
- **Quota** — `Quota\QuotaService` enforces on a destination's reconciled `used_bytes`
  (`Jobs\ReconcileUsedBytesJob`); `enforce` gated (reports usage even when off), `warn_threshold_percent`
  drives the storage-limit alert; over-quota → `QuotaExceededException`.
- **Notifications** — `Notifications\BackupV2Notifier` (success + storage-limit mail); gated, recipients
  fall back to the app "from" address.

### 2.10 Jobs, provider, console

- Jobs `RunBackupSessionJob` / `RunRestoreSessionJob` / `ReconcileUsedBytesJob` are **inert without the
  flags** (backup: `enabled`; restore: `enabled` + `restore_enabled`; reconcile writes:
  `reconciliation_writes_enabled`), and a site must be in `site_ids`.
- `BackupV2ServiceProvider` registers only the read-only console command(s) by default — **no scheduler,
  no queue wiring** unless flags are on.
- Console: `backup:reconcile-storage` (read-only unless `reconciliation_writes_enabled`),
  `backup:v2-deep-verify`, `backup:v2-proven-restore`, `backup:v2-import-legacy`.

---

## 3. Plugin components (`wordpress-plugin/simplead-backup/`)

New standalone plugin, own REST namespace `simplead-backup/v1`, own options (`sam_backup_*`), own temp,
own log. **The connector is never touched.** See [`PLUGIN-PROTOCOL.md`](PLUGIN-PROTOCOL.md) for the wire
contract, [`EXCLUSIONS.md`](EXCLUSIONS.md) and [`RESOURCE-PROFILES.md`](RESOURCE-PROFILES.md) for detail.

| Concern | Class / file |
|---|---|
| Bootstrap | `simplead-backup.php` + `includes/class-backup-plugin.php` |
| REST routing | `endpoints/class-rest-controller.php` |
| Capabilities | `endpoints/class-capabilities-endpoint.php` |
| Consistent DB dump | `db/class-consistent-dumper.php` |
| Inventory / exclusions / chunker / diff | `files/class-inventory.php`, `class-exclusions.php`, `class-file-chunker.php`, `class-file-diff.php` |
| DB / files endpoints | `endpoints/class-database-endpoint.php`, `class-files-endpoint.php` |
| Restore engine + endpoint | `restore/class-restore-engine.php`, `endpoints/class-restore-endpoint.php` |
| Auth (HMAC + nonce) | `support/class-auth.php` |
| Options / temp / logger | `support/class-options.php`, `class-temp.php`, `class-logger.php` |
| Admin diagnostics | `admin/class-admin-page.php` |

**Consistent dump (D-006):** one mysqli connection, `SET SESSION REPEATABLE READ` +
`START TRANSACTION WITH CONSISTENT SNAPSHOT`, `ORDER BY` on the real PK, binary/BLOB as `0x...` hex,
gzip-segmented output; the snapshot is a *read* mechanism only (restore text is DDL+INSERT).
**`shell_exec` is never used** — only reported by capabilities.

---

## 4. End-to-end flow: manager ↔ plugin ↔ S3

```
BackupRunner                       simplead-backup (WP)                 S3
────────────                       ────────────────────                 ──
capability_check   ── POST capabilities ──────────►
inventory          ── POST files/inventory ───────►  walk+exclude+plan
database_export    ── POST database/dump ─────────►  consistent snapshot dump→gzip segs
                   ── POST database/chunk-download (delete=1) ─►  pull-and-free ─► putObject database/chunk_N.sql.gz
uploading          ── POST files/chunk-exec ──────►  build zip (STORE)
                   ── POST files/chunk-download (delete=1) ────►  pull-and-free ─► multipart files/chunk_N.zip
upload_verifying   ◄─ headObject + getObject (sha256 re-verify per object) ─────►
finalizing         ── putObject manifest.json, checksums.json, metadata.json ───►
                   ── putObject _COMPLETE (LAST) ─────────────────────────────►
completed
```

Restore reverses it (S3 → manager → `restore/stage-chunk` → `restore/apply` atomic swap → commit/rollback).

---

## 5. Known production TODOs (carried forward)

| Item | Where |
|---|---|
| Per-site plugin credential resolver (decrypt from Site row) | `Plugin\SimpleadBackupClient::forSite()` |
| Production S3 client from `StorageDestination` (decrypt) | `Storage\S3ClientFactory::forDestination()` |
| sha256 verify via S3 `ChecksumSHA256`/ranged instead of full re-download for large objects | `BackupRunner::verify()` |
| Non-InnoDB consistency fallback | `db/class-consistent-dumper.php` |
| Client-side encryption `decrypting` step | `RestoreRunner::decrypt()` |
| `DiskSpaceGuard` pre-dump | manager orchestration |
| Connector thin-shim endpoints for migration window | connector |

These are the same items tracked in [`PROJECT-STATUS.md`](PROJECT-STATUS.md) and
[`KNOWN-LIMITATIONS.md`](KNOWN-LIMITATIONS.md).
