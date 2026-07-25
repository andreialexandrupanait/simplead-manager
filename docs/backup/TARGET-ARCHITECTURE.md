# Target Architecture — Snapshot-parity Backup Engine

> Independent design (no WPMU proprietary code/structure). Reuses the proven internals of the
> current engine; replaces the specific weak points identified in
> [`CURRENT-BACKUP-AUDIT.md`](CURRENT-BACKUP-AUDIT.md).

## Design decisions (locked with product owner)

1. **Separate WordPress plugin `simplead-backup`** — extract the 3,384-line engine out of the
   connector into a dedicated plugin with its own REST namespace (`simplead-backup/v1`),
   version, and release cycle. Rationale below.
2. **DB strategy: full logical dump every backup + incremental FILES** (changed/new/tombstones
   vs manifest). No faked incremental DB — honest and universally restorable on heterogeneous
   shared hosting.
3. **Manifest + completion-marker mandatory** for a backup to reach `completed`.

### Why a separate plugin (justified after audit)

| Signal from audit | Implication |
|---|---|
| Backup engine is one 3,384-line file, heavy CPU/disk/memory | Own blast radius; a backup OOM/timeout must not take down connector auth, monitoring, updates |
| Upload transport is the #1 failure source | Needs its own hardening/release cadence independent of connector |
| Own persistent state (chunk sessions, transients, locks) | Own lifecycle, own rollback |
| Must work even if other connector functions are broken | Independent activation/deactivation |
| WP-Cron/loopback detach model is delicate | Isolate so connector changes don't perturb it |

The connector keeps **thin shim endpoints** that proxy to `simplead-backup` during migration,
so the manager's contract is stable while sites roll over (see
[`DATA-MIGRATION-PLAN.md`](DATA-MIGRATION-PLAN.md)).

## Component map

```
┌─────────────────────────── manager.simplead.ro (Laravel 12) ───────────────────────────┐
│ Orchestration + state machines + storage + retention + verification + UI                │
│                                                                                          │
│  BackupSession/RestoreSession (explicit FSM) ── SiteOperationLock ── DiskSpaceGuard      │
│  Storage drivers: S3Driver(Hetzner/B2/S3) · DropboxDriver · LocalDriver                  │
│  ManifestService (chains) · IntegrityVerifier · RetentionService · BackupVerifier        │
│  SandboxRestoreService (proven restore) · BackupHealthService                            │
└───────────────▲───────────────────────────────────────────────────────▲────────────────┘
                │ HMAC (nonce-mandatory) + short-lived presigned URLs     │
     ┌──────────┴───────────┐                                  ┌──────────┴───────────┐
     │  simplead-backup      │  (new WP plugin)                │  Hetzner S3 (EU)     │
     │  capability discovery │                                  │  clients/{c}/sites/  │
     │  inventory (db+files) │  chunk sessions · resume tokens  │  {s}/backups/{b}/    │
     │  full / incremental   │  idempotency · heartbeat         │  SSE + versioning    │
     │  staged atomic restore│  safe/maintenance mode           └──────────────────────┘
     └───────────────────────┘
```

## S3 object layout (per backup)

```
clients/{client_id}/sites/{site_id}/backups/{backup_id}/
  manifest.json          # file inventory + hashes + chain ref
  metadata.json          # type, sizes, wp/php versions, timings
  database/              # full logical dump (gzip, chunked)
  files/                 # file payload chunks (incremental: only changed)
  chunks/                # transient part staging (cleaned on finalize)
  checksums.json         # per-object sha256
  encryption.json        # SSE + optional client-side key metadata (no keys)
  restore.json           # restore hints (chain order, tombstones)
  logs/                  # structured per-backup log
  _COMPLETE              # completion marker (written last)
```

**A backup is `completed` only when:** every declared object exists · sizes match manifest ·
sha256 match · manifest validates · `_COMPLETE` written · manager confirms integrity. This
directly fixes the 191-missing-manifest / phantom-completed defect.

## Lifecycle state machines (see [`ACCEPTANCE-TESTS.md`](ACCEPTANCE-TESTS.md) for transitions)

**Backup:** `requested → capability_check → inventory → database_export → file_diff →
chunking → upload_initializing → uploading → upload_verifying → finalizing → completed`
with side-states `retry_wait · paused · cancelling → cancelled · failed · corrupt`.

**Restore:** `requested → validating_backup → pre_restore_backup → downloading → decrypting →
verifying → maintenance → database_restore → file_restore → cleanup →
post_restore_validation → completed` with `rollback · failed`.

Every step: **resumable · idempotent · observable · timed · heart-beated · error-coded ·
self-cleaning.** No single HTTP request spans a whole backup or restore.

## Reliability model (fixes the #1 failure class)

- **Kill the sync endpoints** (`/backup/db|files|restore`, 1800s sync restore) — every path
  becomes chunked + resumable.
- **Multipart hardening** (the dominant prod failure): smaller default parts, per-part retry
  with backoff + jitter, per-part checksum, resume from last confirmed part, and a
  manager-side `ListMultipartUploads` reaper for abandoned uploads.
- **Gateway-timeout aware**: keep each WP request well under Cloudflare's ~100s; long work runs
  in short checkpointed steps driven by manager polling, not one long call.
- **Detach reliability**: keep loopback→WP-Cron but add a manager-driven step-pump fallback so a
  site with `DISABLE_WP_CRON` still progresses; confirm worker-started (fix fire-and-forget).
- **Locks & idempotency**: reuse `SiteOperationLock`; every step carries an idempotency key so
  duplicate callbacks/redeliveries are no-ops.

## Security model

| Control | Target |
|---|---|
| Transport auth | HMAC-SHA256 + timestamp + **mandatory nonce** (drop legacy no-nonce), replay cache |
| Presigned URLs | Short TTL (minutes, per-part just-in-time), never 4h up-front |
| Restore transfer | **Authenticated** signed fetch (fix unauthenticated `/restore-download/{token}`) |
| At rest | **S3 SSE** on all site objects + optional client-side AES-256-GCM (host-capability gated) |
| Keys | No permanent S3 creds in WP; per-site/backup logical key separation; key metadata separate from data |
| Extraction safety | zip-slip guard (reuse `SafeZipExtractor`), max extracted size/file count, symlink policy |
| SSRF/loopback | Validate callback + download hosts; keep Cloudflare-loopback 403 handling |
| Access | RBAC + 2FA gate on restore; full audit trail; tenant isolation via `clients/{id}/` prefix |
| Region | EU (Hetzner fsn1/nbg1) for GDPR |

## What we reuse vs replace

**Reuse (proven):** `S3Driver` multipart, `DropboxDriver` (after `listRecursive` fix),
`SiteOperationLock`, `DiskSpaceGuard`, `ManifestService`, `BackupSidecarMetadata`,
`SafeZipExtractor`, `IntegrityVerifier`, `SqlDumpParser`, `SandboxRestoreService`,
`RetentionService` (chain-aware), `BackupDispatcher`, connector paged-DB-dump + staged atomic
restore logic (ported into the new plugin), `/backup/capabilities`.

**Replace/repair:** manifest-optional completion → mandatory; sync legacy endpoints → removed;
single 1800s sync restore → chunked/async only; `used_bytes` accounting → derived from storage
truth; Dropbox `listRecursive` → reliable pagination + namespace handling; silent `@`/`catch{}`
→ explicit error codes; duplicated stuck-recovery → one state-machine-driven recovery; dead
`exclude_paths/tables` → real exclusions feature; stringly-typed `AppBackup` status → enum.

See [`IMPLEMENTATION-ROADMAP.md`](IMPLEMENTATION-ROADMAP.md) for phasing and
[`DATA-MIGRATION-PLAN.md`](DATA-MIGRATION-PLAN.md) for schema + rollout.
