# simplead-backup V2 — ROLLOUT RUNBOOK

> How to take V2 from inert to production, one graduated step at a time. **V2 is inert by default** —
> every enable flag in `config/backup_v2.php` is `false`, so following this runbook is the *only* way V2
> ever touches a real site.
>
> **HARD GATE: the first real pilot does NOT start without the explicit owner instruction
> `DA PILOT BACKUP V2`.** No flag on a production site is flipped before that.

Companion: [`ROLLBACK-RUNBOOK.md`](ROLLBACK-RUNBOOK.md) (instant disable) ·
[`ACCEPTANCE-TESTS.md`](ACCEPTANCE-TESTS.md) (gates) · [`PROJECT-STATUS.md`](PROJECT-STATUS.md).

---

## 1. Feature flags (all env → `config/backup_v2.*`, default false)

| Env var | Config | Controls |
|---|---|---|
| `BACKUP_ENGINE_V2_ENABLED` | `enabled` | master kill-switch — no V2 path runs against a site when false |
| `BACKUP_ENGINE_V2_SITE_IDS` | `site_ids` | comma-separated allowlist; a site must be listed even when enabled |
| `BACKUP_ENGINE_V2_SCHEDULER_ENABLED` | `scheduler_enabled` | V2 self-scheduling (false = never self-starts) |
| `BACKUP_ENGINE_V2_RESTORE_ENABLED` | `restore_enabled` | V2 restore orchestration |
| `BACKUP_ENGINE_V2_PROVEN_RESTORE_ENABLED` | `proven_restore_enabled` | weekly sandbox proven-restore |
| `BACKUP_LEGACY_RESTORE_ENABLED` | `legacy_restore_enabled` | restoring legacy artifacts via V2 |
| `BACKUP_RECONCILIATION_WRITES_ENABLED` | `reconciliation_writes_enabled` | `backup:reconcile-storage` write mode |
| `BACKUP_ENGINE_V2_RETENTION_DRY_RUN` | `retention_dry_run` | retention log-only (default true) |
| `BACKUP_ENGINE_V2_UI_ENABLED` | `ui_enabled` | `/backup-v2` console (defaults to `enabled`) |
| `BACKUP_ENGINE_V2_QUOTA_ENFORCE` | `quota.enforce` | block over-quota backups (defaults to `enabled`) |
| `BACKUP_ENGINE_V2_NOTIFICATIONS_ENABLED` | `notifications.enabled` | success/limit mail (defaults to `enabled`) |

**Safety invariants regardless of flags:**
- Jobs throw/skip unless their flags are on (`RunBackupSessionJob` needs `enabled`;
  `RunRestoreSessionJob` needs `enabled` **and** `restore_enabled`).
- Retention deletes only on `apply(force:true)` **and** `enabled`; dry-run otherwise.
- Reconcile writes only on `reconciliation_writes_enabled`.
- The `/backup-v2` UI 404s when `ui_enabled` is false.

---

## 2. Rollout ladder

Never skip a rung. Each rung must pass every gate in §3 before advancing; a failure at any rung →
[`ROLLBACK-RUNBOOK.md`](ROLLBACK-RUNBOOK.md).

| Rung | Scope | Precondition |
|---|---|---|
| 0 | **Lab** (MinIO + spike WP) | all E2E suites green (see PROJECT-STATUS) |
| 1 | **Staging** (real Hetzner S3, staging sites) | prod S3 resolver wired; per-site creds resolver wired |
| 2 | **1 pilot site** | **owner says `DA PILOT BACKUP V2`** |
| 3 | 3 sites | rung 2 clean for the agreed soak window |
| 4 | 10 sites | rung 3 clean |
| 5 | 25% of sites | rung 4 clean |
| 6 | 50% | rung 5 clean |
| 7 | 100% | rung 6 clean |

Scope is controlled with `site_ids` (add ids as you climb), never by flipping `enabled` broadly.

---

## 3. Gates to pass at each rung

**Health gate**
- 0 upstream 5xx attributable to the backup on the target sites.
- 0 PHP OOM / fatal on the WP hosts; 0 disk-full events.
- Each WP step completes under the profile `step_seconds` (well under CF ~100s). See
  [`RESOURCE-PROFILES.md`](RESOURCE-PROFILES.md).

**Backup gate**
- Every backup reaches `completed` with `_COMPLETE` written last; `backup:v2-deep-verify` (sampled)
  passes; a `BackupVerification` `create` row is `passed` and `verified_at` is stamped.
- No `corrupt` / stuck sessions; resume-after-interruption leaves 0 duplicate uploads.

**Restore gate**
- `backup:v2-proven-restore` writes a `passed` `ProvenRestore` row for the pilot site(s).
- A manual restore round-trip (MIRROR reproduces exactly; SAFE_MERGE preserves live-only) succeeds; a
  deliberately failed restore rolls back to a byte-identical site.

**Perf / parity gate**
- Backup duration, stored bytes, and success rate are **compared against the V1 engine** for the same
  sites; V2 is at least on par (see §4).

**Storage gate**
- `backup:reconcile-storage` shows `used_bytes` within tolerance and no missing objects / no
  manifest-less backups.

---

## 4. Comparing against the old engine

Run V2 **alongside** V1 (V1 stays the source of truth until 100%). For each pilot site compare:

- success rate and mean duration (V2 vs V1 backup jobs),
- stored size per backup (`metadata.json.stored_bytes` vs V1),
- restore success in the sandbox (`ProvenRestore` V2 vs V1 proven restore),
- host impact (5xx / load) during the backup window.

V2 must not regress any of these before a rung advances. Do **not** disable V1 backups for a site until
that site has cleared the restore gate on V2.

---

## 5. Exact activation steps (per rung)

Preconditions for rung ≥ 2: prod S3 client resolver (`S3ClientFactory::forDestination`) and per-site
plugin creds (`SimpleadBackupClient::forSite`) are implemented — these are the tracked **TODO-PROD**
items; do not run a pilot until they are wired and reviewed.

1. Deploy the `simplead-backup` plugin (v0.4.0) to the target site(s); confirm `capabilities` responds
   over HMAC and `consistent_snapshot_supported = true`.
2. Set env on the manager and deploy (see project `deploy` shortcut):
   ```
   BACKUP_ENGINE_V2_ENABLED=true
   BACKUP_ENGINE_V2_SITE_IDS=<pilot site id(s)>
   BACKUP_ENGINE_V2_UI_ENABLED=true          # optional: expose the console for operators
   BACKUP_ENGINE_V2_RESTORE_ENABLED=false    # keep restore off until the backup gate is clean
   BACKUP_ENGINE_V2_RETENTION_DRY_RUN=true    # keep retention log-only during the pilot
   ```
3. Verify inertness elsewhere: sites not in `site_ids` see no V2 behaviour.
4. Trigger one V2 backup for the pilot (via the `/backup-v2` UI or `SessionActions::startBackup`).
5. Run the Backup gate; then enable `BACKUP_ENGINE_V2_RESTORE_ENABLED=true` and run the Restore gate
   (proven-restore + a manual round-trip on the pilot).
6. Only after all gates pass for the soak window, advance the rung (extend `site_ids`).
7. Turn on `BACKUP_ENGINE_V2_SCHEDULER_ENABLED` / real retention (`RETENTION_DRY_RUN=false`) only once a
   rung has been stable on manual triggers.

At any red gate, stop and follow [`ROLLBACK-RUNBOOK.md`](ROLLBACK-RUNBOOK.md).

---

## 6. Reminder

The pilot is the first time V2 writes to production storage or touches a client site. It **must not**
begin without the owner's explicit `DA PILOT BACKUP V2`. Everything up to and including staging (rung ≤ 1)
is safe to run under the default flags plus a staging-only `site_ids`.
