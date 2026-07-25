# Data Migration Plan

> How the data model evolves from today's schema to the target, with rollback for every step
> and a dual-format transition. Grounded in the current schema (read from prod, 2026-07-25).

## Principles

- **No redundant tables** where an existing table migrates cleanly.
- Every migration ships a real `down()`; base schema (currently only in
  `database/schema/pgsql-schema.sql`) is captured as first-class migrations so fresh
  environments no longer depend on the dump.
- **Dual-format read** throughout: the manager reads legacy `v2-zip`/`v3-zip` and new-format
  backups simultaneously; nothing re-uploads or re-formats existing objects.

## Table-by-table

| Table | Action | Detail | Rollback |
|---|---|---|---|
| `backups` | **KEEP + extend** | Add `client_id` (tenant prefix), `object_prefix`, `completion_marker_at`, `format` value `v4`, `object_missing` status; backfill `client_id` from site→client | drop added cols; status enum revert |
| `backup_configs` | **KEEP + fix** | Wire `exclude_paths`/`exclude_tables` into model (`$casts`); add `content_scope` (full/db-only/files-only) | remove casts; drop `content_scope` |
| `storage_destinations` | **KEEP + fix** | Recompute `used_bytes` from storage truth (job); add `region`, `sse_enabled` | keep old `used_bytes` snapshot |
| `proven_restores` | **KEEP** | Already correct; ensure the weekly job actually populates it (0 rows today) | n/a |
| `app_backups` | **KEEP + fix** | Replace stringly-typed `status` with enum incl. `degraded`; add site lock + unique | revert to string |
| **`backup_sessions`** | **NEW** | FSM state per attempt (step, heartbeat_at, resume_token, idempotency_key, error_code) — replaces ad-hoc transient/jsonb state | drop table |
| **`restore_sessions` + `restore_steps`** | **NEW** | Explicit restore FSM (today `restore_*` columns on `backups` — keep as denormalized mirror) | drop tables |
| **`backup_chunks`** | **NEW** | Per-chunk manifest + upload state (multipart part → ETag → checksum) — enables true per-part resume | drop table |
| **`backup_objects`** | **NEW** | One row per S3 object in a backup (path, size, sha256, present_checked_at) — powers reliable reconciliation | drop table |
| **`backup_manifests`** | **NEW (or promote sidecar)** | Promote sidecar/`manifest_path` into a queryable table; keep JSON in storage as source of truth | drop table; sidecar remains authoritative |
| **`backup_verifications`** | **NEW** | History of verification runs (today only `verification_status` scalar) — powers verification dashboard | drop; scalar remains |
| **`connector_capabilities`** | **NEW** | Persist `/backup/capabilities` per site (today read live/ad-hoc) | drop table |
| **`storage_usage`** | **NEW** | Point-in-time storage truth snapshots (replaces trusting `used_bytes`) | drop table |
| **`retention_runs` / `backup_alerts` / `backup_locks`** | **NEW (thin)** | Formalize what's today implicit (schedule logs / `SiteOperationLock` rows) | drop tables |
| `rollback_points` | **KEEP** | Update-rollback markers; unrelated to backup engine | n/a |

> `backup_chains` is **not** a new table — chains remain modeled by `backups.parent_backup_id`
> + `ManifestService`. Adding a table would be redundant.

## Legacy-object compatibility

- A `LegacyBackupReader` resolves `v2-zip`/`v3-zip` on read (restore/verify/download) so all
  849 Category-A + 57 Category-B backups stay restorable without migration.
- Category-A/B rows get a `format` tag and are indexed into `backup_objects` by a **read-only
  reconcile job** (no re-upload).
- 347 Category-F phantom rows → new `object_missing` status (reversible), surfaced read-only.

## Data-migration sequence (each reversible)

1. **Additive migrations** (new tables + nullable columns) — zero downtime, no behavior change.
2. **Backfill jobs** (idempotent, dry-run first): `client_id`, `backup_objects` from storage
   reconcile, `connector_capabilities`, `storage_usage` snapshot.
3. **Reconcile `used_bytes`** from `storage_usage` (after `DropboxDriver.listRecursive` fix).
4. **Soft-mark** Category-F rows (`object_missing`) — reversible.
5. **Cut over** per-site via feature flag (see [`IMPLEMENTATION-ROADMAP.md`](IMPLEMENTATION-ROADMAP.md)).

## Rollback strategy

- Migrations: `php artisan migrate:rollback` restores prior schema (down() implemented + tested
  in CI against a Postgres fixture).
- Backfills: additive only; a `--revert` flag nulls backfilled columns / truncates new tables.
- Feature flag off → manager uses the old engine unchanged; new tables simply go unused.
- No destructive data migration exists in this plan — legacy objects are never rewritten.
