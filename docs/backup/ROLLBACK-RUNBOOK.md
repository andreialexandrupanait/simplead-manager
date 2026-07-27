# simplead-backup V2 — ROLLBACK RUNBOOK

> How to disable V2 instantly and return to V1 with **zero data loss**. V2 was built to be reversible:
> flags off → V2 inert; V1 was never modified, so it is always the working engine to fall back to.

Companion: [`ROLLOUT-RUNBOOK.md`](ROLLOUT-RUNBOOK.md).

---

## 1. Instant disable (kill-switch)

Set the master flag off and redeploy the manager:

```
BACKUP_ENGINE_V2_ENABLED=false
```

Effect (immediate on the next config load / deploy):

- `RunBackupSessionJob` throws unless `enabled` → **no new V2 backup runs**.
- `RunRestoreSessionJob` requires `enabled` **and** `restore_enabled` → **no new V2 restore runs**.
- `ChainRetentionService` refuses a forced delete unless `enabled` → **no V2 deletions** (dry-run only).
- `ReconcileUsedBytesJob` returns early → no reconcile writes.
- `QuotaService` enforcement and `BackupV2Notifier` default to `enabled`, so they go inert too.

For a belt-and-braces disable, also clear the allowlist and turn off the sub-flags:

```
BACKUP_ENGINE_V2_ENABLED=false
BACKUP_ENGINE_V2_SITE_IDS=
BACKUP_ENGINE_V2_SCHEDULER_ENABLED=false
BACKUP_ENGINE_V2_RESTORE_ENABLED=false
BACKUP_ENGINE_V2_UI_ENABLED=false          # /backup-v2 console 404s
BACKUP_ENGINE_V2_RETENTION_DRY_RUN=true
BACKUP_RECONCILIATION_WRITES_ENABLED=false
```

`BACKUP_ENGINE_V2_UI_ENABLED=false` makes the whole `/backup-v2` route tree 404 (middleware
`EnsureBackupV2Ui`), so the console disappears for everyone.

Redeploy with the standard flow (project `deploy` shortcut: build → `up --force-recreate` →
`queue:restart` so workers pick up the new config). Run `queue:restart` so no worker keeps stale config.

---

## 2. Return to V1

Nothing to "restore" — **V1 was never touched**. Its config (`config/backups.php`), scheduler, jobs, UI,
and storage are independent of V2 (separate `config/backup_v2.php`, separate `backup_sessions` /
`restore_sessions` tables, separate `/backup-v2` routes). With `enabled=false`:

- V1 scheduled backups continue exactly as before.
- The V1 backup UI is the only backup UI users can reach.
- Legacy backups remain restorable through V1 (V2 never moved/deleted them — see
  [`LEGACY-COMPATIBILITY.md`](LEGACY-COMPATIBILITY.md)).

If V2 backups had been scheduled for some sites, re-enable the V1 schedule for those sites (it was left
in place during the rollout — V1 is not disabled per-site until a site clears the V2 restore gate, per
[`ROLLOUT-RUNBOOK.md`](ROLLOUT-RUNBOOK.md) §4).

---

## 3. In-flight sessions

- A `BackupSession` / `RestoreSession` already running finishes its current job or dies at the next
  step; with `enabled=false` it is not re-dispatched. Its state row remains for inspection (no orphaned
  side effects — see below). You may leave it or cancel via `SessionActions::cancel` (transitions to
  `cancelling → cancelled`).
- A restore interrupted mid-apply is safe by construction: the plugin `apply()` self-rolls-back a
  mid-swap failure, and `sambk_old_*` tables + the file journal + trash are retained until commit — so
  the site is never left half-restored (see [`RESTORE.md`](RESTORE.md) §7).

---

## 4. Abandoning in-progress multipart uploads (reaper)

A worker that died after `CreateMultipartUpload` but before `Complete` leaves a billable, invisible
in-progress upload. Clean them up with the built-in reaper — `HardenedMultipartUploader::reapAbandonedUploads($prefix, $olderThan)`
(`ListMultipartUploads` + client-side prefix filter, since MinIO ignores server-side `Prefix`). It aborts
uploads initiated before the threshold and verifies no dangling upload remains.

- It runs automatically at `upload_initializing` for each backup's own prefix.
- To sweep broadly after a rollback, invoke the reaper over the tenant prefix
  (`clients/{client_id}/…`) with a conservative age (e.g. `-6 hours`). Aborting a multipart upload
  **deletes only the un-assembled parts** — no completed object is affected, so this is loss-free.

There is no dedicated artisan command for the reaper today; it is called from the runner and can be
invoked via tinker against the resolved S3 client if a manual sweep is needed **[TODO-PROD: a
`backup:v2-reap-uploads` command would make this a one-liner]**.

---

## 5. Cleaning up V2 storage (optional, deliberate)

Rollback does **not** require deleting anything. V2 backups already written are complete, verified
objects under `clients/{c}/sites/{s}/backups/{b}/` and are harmless. If you deliberately want to reclaim
that storage:

- Use `ChainRetentionService` (chain-safe) with `enabled=true` + `apply(force:true)` **only** for the
  intended sites — but note this re-enables V2; prefer manual removal of the specific backup prefixes
  instead while V2 stays disabled.
- Never bulk-delete by hand without checking chains: an incremental depends on its base full and every
  earlier increment.

Leaving the objects in place is the safe default; they cost storage but risk nothing.

---

## 6. What is guaranteed loss-free

| Concern | Guarantee |
|---|---|
| V1 backups/restores | untouched — separate config, tables, routes, storage |
| Legacy backups | never moved/deleted by V2 (read-only index only) |
| In-flight restore | plugin self-rollback + retained `sambk_old_*`/journal/trash → site never half-restored |
| In-progress multipart | reaper aborts only un-assembled parts; completed objects unaffected |
| Completed V2 backups | remain valid, verified objects (safe to keep or delete deliberately) |
| Session state rows | additive tables; retained for audit, drop only via the reversible migrations |

---

## 7. Verify V2 is fully inert after rollback

1. `config('backup_v2.enabled')` is false on the running manager.
2. `/backup-v2` returns 404.
3. No `RunBackupSessionJob` / `RunRestoreSessionJob` on the Horizon queues.
4. A V1 backup for a formerly-piloted site runs and completes normally.
5. `backup:reconcile-storage` (read-only) reports no anomalies.
