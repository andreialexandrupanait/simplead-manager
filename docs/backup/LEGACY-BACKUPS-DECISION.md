# Legacy Backups — Disposition Decision

> Recommendation for existing backups, derived from
> [`EXISTING-BACKUPS-INVENTORY.md`](EXISTING-BACKUPS-INVENTORY.md). **Nothing here has been
> executed.** All cleanup commands are proposed-but-unexecuted and require your explicit
> approval + a quarantine window before any deletion.

## Guiding rules (locked)

- **Delete nothing automatically.**
- Preserve the **last valid full backup per site** (Category A).
- Preserve **protected** backups (`is_locked`) — currently 0.
- Preserve backups with a **verified restore** (`verification_status=passed`).
- Preserve **required incremental chain bases**.
- Move **incompatible/legacy** objects to a read-only `legacy/` prefix, do not delete.
- Deletion happens only after: report → your approval → quarantine → inventory export → rollback path.

## Recommended strategy: **#2 Selective retention + #3 legacy archival** (hybrid)

Neither "keep everything" nor "delete invalids" fits cleanly, because the invalid set is
almost entirely **phantom DB rows with no object** (nothing to delete in storage) and the only
real reclaimable storage is a small legacy Dropbox folder.

### Per-category disposition

| Cat | Count | Disposition | Why | Storage impact |
|---|---|---|---|---|
| **A** — valid, verified (Hetzner) | 849 | **KEEP** + import to new format index | Current protection for all 24 active sites | Retain 1,153 GB |
| **B** — present, unverified (Dropbox) | 57 | **KEEP → VERIFY**, then A or legacy | Real objects; just never restore-tested | Retain 36 GB (until verified) |
| **C** — recoverable | 0 | n/a | None found | — |
| **D** — failed/cancelled | 66 | **DB cleanup only** (no object exists) | Rows clutter history; nothing in storage | 0 |
| **E** — orphans (objects, no row) | 3 Hetzner + ≥29 Dropbox legacy | **MARK LEGACY** (move under `legacy/`, keep read-only) | May be old manual backups; do not delete blindly | ~50 GB (Dropbox legacy) stays |
| **F** — phantom (row, no object) | 347 | **DB reconcile only** — mark `object_missing`, do **not** delete | Object already gone; every affected site has a Category-A backup | 0 (nothing in storage) |

### What we KEEP
- All 849 Category-A Hetzner backups (every active site's real restore points).
- All 57 Category-B Dropbox objects until deep-verified.
- All orphan/legacy objects (moved, not deleted).

### What we MIGRATE (import into the new engine's index)
- Category A + verified-B → registered in new `backups`/manifest tables with a
  `legacy_format` flag, restorable via a compatibility reader (v2-zip/v3-zip).
- No re-upload or re-format of existing objects (avoid egress cost + risk).

### What we MARK LEGACY (read-only)
- Dropbox `websites/` folder (86 zips ≈ 50 GB incl. a 4,816-file raw `vechi.feco.ro` copy)
  and the 3 Hetzner orphan zips → surfaced read-only in the manager, excluded from retention.

### What is DELETABLE (only after explicit approval)
- **Nothing in storage** is proposed for deletion in this pass.
- **DB-only reconciliation** (rows, not objects):
  - Mark 347 Category-F rows `status=object_missing` (new terminal state) — *reversible*.
  - Optionally archive 66 Category-D failed rows to a history table.
- Abandoned S3 multipart uploads: *assess then abort* (separate, low-risk) — not yet inventoried.

### What MUST NOT be deleted
- Any Category-A or B object. Any object under `legacy/`. Any row whose site lacks another
  Category-A backup (none today, but re-check before any action).

## Storage cost

| Bucket | Real footprint | Reclaimable now | Reclaimable after approval |
|---|---|---|---|
| Hetzner | 1,178 GB (incl. platform + Coolify dumps) | ~0 (3 orphan zips only) | negligible |
| Dropbox | 50.8 GB *listable* (accounting claims 2,346 GB — **untrusted**) | 0 (nothing invalid to delete) | 0 until `used_bytes` reconciled |

> **Blocking data-quality issue:** Dropbox `used_bytes` (2,346 GB) cannot be reconciled with
> what is listable (50.8 GB). No storage-reclamation decision on Dropbox is safe until the
> `DropboxDriver.listRecursive` reliability + `used_bytes` accounting are fixed. This is a P1
> task in the roadmap, gated *before* any Dropbox cleanup.

## Risk of each option

| Option | Risk |
|---|---|
| Keep everything | Low data risk; carries phantom rows forward (bad reporting) |
| **Hybrid (recommended)** | Low — reconciles rows, keeps all real objects, defers deletes | 
| Delete phantom rows | Reversible if we soft-mark rather than hard-delete |
| Delete Dropbox legacy | **Unsafe now** — listing unreliable, could delete a real restore point |

## Proposed (UNEXECUTED) cleanup commands

> Dry-run first, quarantine ≥30 days, require `--i-understand` + your sign-off. None of these
> run in this phase.

```bash
# 1. Re-run authoritative reconciliation and export (READ-ONLY) — safe to run anytime
php artisan backup:reconcile-storage --export=storage/app/backup-audit/existing-backups.csv --dry-run

# 2. Soft-mark phantom rows (REVERSIBLE — adds status, deletes nothing)
php artisan backup:mark-object-missing --category=F --dry-run       # preview 347 rows
# php artisan backup:mark-object-missing --category=F --confirm      # after approval

# 3. Move Dropbox/Hetzner orphans under legacy/ (MOVE, not delete) — after listRecursive fix
php artisan backup:archive-legacy --prefix=legacy/ --dry-run

# 4. Abort abandoned S3 multipart uploads (after inventory) — reclaims hidden storage
php artisan backup:abort-stale-multipart --older-than=7d --dry-run

# NOTE: there is intentionally NO object-delete command proposed in this pass.
```

## Decision requested (later, not now)

1. Approve hybrid strategy? (keep all real objects, reconcile rows, defer deletes)
2. Approve soft-marking the 347 phantom rows (reversible)?
3. Hold all Dropbox storage actions until `listRecursive`/`used_bytes` are fixed — agreed?

Until you answer, the manager keeps all current behavior; this document changes nothing.
