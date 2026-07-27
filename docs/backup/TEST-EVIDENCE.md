# Backup Engine V2 — Test Evidence (P7 audit)

Authoritative record of what the V2 test suite actually runs and proves.
Numbers below are the measured result of running the suite twice on the isolated
lab (`sam_lab`) with the spike stack (`sam_spike`: live WordPress + MinIO + MySQL
+ MariaDB) attached.

## Suite result (measured)

```
php artisan migrate:fresh --force
php artisan test tests/Feature/Backup/V2 tests/Unit/Backup/V2
→ Tests: 137 passed (989 assertions)   0 failed   0 skipped   0 risky
  Duration: ~574s (real HTTP E2E + real S3 multipart included)
```

- **Pint:** `./vendor/bin/pint --test app/Backup/V2 app/Livewire/Backup` → PASS (58 files).
- **PHPStan:** `./vendor/bin/phpstan analyse app/Backup/V2` → **No errors**.
- **Plugin lint:** `php -l` on all **23** `wordpress-plugin/simplead-backup/**.php` → 0 syntax errors.

### Why 0 skipped matters

The HTTP E2E tests (`BackupRunnerE2ETest`, `RestoreHttpE2ETest`,
`RestoreRunnerE2ETest`, `IncrementalHttpE2ETest`) `markTestSkipped()` when the
live plugin is unreachable, and the MinIO tests skip when object storage is
unreachable. This run reported **0 skipped**, and lab-php resolves both
`sam_spike-spike-wp-1` and `spike-minio` (it is joined to `sam_spike_net`). The
integration paths therefore genuinely executed — e.g. observed in the run:
`validation failure rolls back to pre apply` (14.4s, real restore/apply against
spike-wp) and `persistent etag corruption fails cleanly no dangling` (real MinIO
multipart). This is not a mock-only pass.

## Test inventory (39 files) — what each group proves

### State machine & models (unit)
- `BackupStateMachineTest`, `RestoreStateMachineTest`, `BackupSessionStateTest`,
  `RestoreSessionStateTest` — legal edges permitted, illegal edges rejected
  (skip/backwards/jump-to-completed/terminal-out), terminal states have no
  outgoing edges, `corrupt` only reachable from verification states, `rollback`
  only from mutating states → resolves to `failed`. Idempotent same-state no-op.
- `ObjectLayoutTest` — tenant-isolated key derivation from the configured prefix,
  leading-slash normalisation.
- `BackupSessionModelTest`, `RestoreSessionModelTest` — persistence, casts, state
  transitions on the Eloquent models.

### Orchestration correctness
- `BackupRunnerE2ETest` — full backup through the FSM against the live plugin +
  real S3: chunking → hardened multipart upload → **verify-before-complete** →
  `_COMPLETE` last → `completed`. Includes surgical object corruption →
  `CorruptBackupException` → `Corrupt` (never `Completed`), and DB
  `done=false/consistent=false` → refuse to complete.
- `IncrementalChainE2ETest` / `IncrementalHttpE2ETest` — incremental chain: base
  + delta, latest-wins materialisation, tombstones, unchanged fast-path.
- `Chain/ChainResolverTest` — chain walk, `BrokenChainException` on a missing
  parent link.

### Storage
- `Storage/HardenedMultipartUploaderTest` — real MinIO multipart: happy path,
  per-part retry with backoff, transient error retried then succeeds, persistent
  ETag corruption fails cleanly with **no dangling parts** (abort verified).
- `Storage/BackupSessionProgressStoreTest` — resumable part progress, dedupe.

### Verification
- `Verification/BackupVerifierTest` — create-verification: PASS stamps
  `verified_at`; missing object / size mismatch → `CORRUPT`; manifest/checksums
  disagreement → `FAILED`; only PASS is retention-facing.
- `Verification/DeepVerifyServiceTest` — sampled byte-level deep verify.

### Restore correctness
- `Restore/RestoreEngineTest` — staged/atomic restore over HTTP against spike-wp:
  DB import → `sambk_stg_*` → atomic RENAME swap keeping `sambk_old_*`; journaled
  file swap; SAFE_MERGE vs MIRROR; selective scope; **fault-injected mid-swap
  crash rolls back to pre-apply** (site never broken).
- `Restore/RestoreRunnerE2ETest` — orchestration: pre-restore safety backup →
  stage → verify → maintenance → apply → post-restore validation → commit OR
  rollback. Observed: `validation failure rolls back to pre apply`.
- `Restore/RestoreHttpE2ETest` — real HTTP restore endpoints end-to-end.

### Proven restore, quota, retention, notifications, legacy, reconcile
- `ProvenRestore/ProvenRestoreServiceTest` — restores latest backup into a
  sandbox and health-checks; a **corrupt backup writes a failed row** (no false
  green).
- `Quota/QuotaServiceTest` — enforced-over-quota blocked; enforcement-off reports
  only (never blocks); unbounded destination never exceeds.
- `Chain/ChainRetentionServiceTest` — keep-last-verified, never breaks a chain,
  dry-run selects-and-logs but deletes nothing.
- `Notifications/BackupV2NotifierTest` — success + storage-limit mail gated by
  flag.
- `Legacy/LegacyImportServiceTest`, `LegacyBackupReaderTest` — read v2/v3 legacy
  manifests + sidecars round-trip; invalid manifest/sidecar throws.
- `ReconcileStorageCommandTest`, `ReconcileUsedBytesJobTest` — read-only drift
  report; reconciled `used_bytes` truth.
- `Console/BackupV2CommandsTest` — the 4 registered artisan commands.

### UI (Livewire, P5)
- `Ui/BackupV2OverviewTest`, `Ui/BackupV2DetailTest`, `Ui/SiteBackupV2Test` —
  console renders under the flag, admin-gated, 404/403 semantics.

## Spike evidence (measured prototypes, `docs/backup/spike/`)

These were validated in the architecture spike and back the engine's design:

| Area | Result | Source |
|---|---|---|
| DB torn-read defect (connector) | **342 orphan FK rows** under concurrent writes | `spike/DATABASE-CONSISTENCY.md` (Test 3, MySQL 8 InnoDB) |
| Single-connection CONSISTENT SNAPSHOT fix | **0 orphan rows** | same |
| WooCommerce **HPOS** real schema | connector paged = **22** broken order line-items; consistent-snapshot = **0** | `spike/CONTINUATION-RESULTS.md` (Woo 10.9.4, HPOS on) |
| Full backup at scale | ABSPATH **71,381 files / 8.9 GB**; WP `/tmp` peak **43.5 MB**; restore oracle **56,624/56,624 sha256 match, 0 missing, 0 mismatch**, composite MATCH | `spike/CONTINUATION-RESULTS.md`, `spike/FULL-BACKUP-RESULTS.md` |
| Single big file | temp peak = max(largest file, threshold), bounded | `spike/SINGLE-BIGFILE-RESULTS.md` |
| Resume / failure injection | 7 injection classes: kill-orchestrator resume, idempotent duplicate exec, nonce replay, WP restart survives, MinIO pause → 0 corrupt/partial | `spike/CONTINUATION-RESULTS.md`, `spike/RESUME-FAILURE-RESULTS.md` |
| Real S3 multipart + presigned expiry | initiate/upload/complete, abort → **0 dangling**, 3s TTL → 403, 120s → 200 | `spike/CONTINUATION-RESULTS.md` |

## Honesty note — what the spike did NOT yet cover

Per `spike/CONTINUATION-RESULTS.md` the following remain on the pre-production
checklist and are **not** claimed as validated here:

- **MariaDB 11** consistent-snapshot dump (a `spike-mariadb` container exists in
  the lab but no measured MariaDB dump result is recorded).
- **Multisite** (`wp_N_*` tables) end-to-end backup/restore.
- **Large** resource profile with genuinely incompressible content at real chunk
  sizes.
- Manager-side disk-full guard / overlapping-session lock wired end-to-end
  through V2 (covered today only by the V1 unit tests).
- Non-InnoDB (MyISAM) fallback path (see `KNOWN-LIMITATIONS.md`).
