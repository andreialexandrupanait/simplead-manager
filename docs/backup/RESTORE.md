# simplead-backup V2 — RESTORE

> How a restore runs end-to-end: `App\Backup\V2\Restore\RestoreRunner` (manager FSM) +
> `SAM_Backup_Restore_Engine` (plugin staged atomic swap). The site is **never left broken** — a
> mid-swap failure self-rolls-back and a failed validation returns the site to its pre-apply state.

Legend: **[IMPLEMENTED+PROVEN]** — P4 gate: MIRROR reproduces exactly, SAFE_MERGE preserves additions,
restore-from-chain (full+2inc) with tombstones, **kill-mid-restore → site byte-identical** via reverse
journal, DB oracle round-trip/selective/rollback.

---

## 1. Restore FSM

`RestoreRunner::run()` drives `RestoreSession` via `transitionTo()`:

```
requested
  → validating_backup      resolve target + chain (BrokenChain → refuse); assert every chain
                           member has _COMPLETE; build RestorePlan
  → pre_restore_backup     take a safety backup of the live site (MANDATORY for MIRROR)
  → downloading            restore/prepare; per planned object: getObject from S3 → restore/stage-chunk
                           (push to plugin staging); resumable via checkpoint.staged
  → decrypting             placeholder (lab objects are plaintext) — [TODO-PROD]
  → verifying              assert every planned chunk staged with a matching sha256
  → maintenance            record entry into the critical window
  → database_restore       restore/apply: DB import + sambk_stg_*→live RENAME swap + journaled file swap
  → file_restore           record file half (apply() already did DB+files atomically)
  → cleanup                free manager-side temp (plugin rollback data KEPT until validation)
  → post_restore_validation  health-check → restore/commit (drop old/trash) OR rollback → failed
  → completed
```

Side states: `rollback` (only from mutating states `maintenance` onward) → always resolves to `failed`.
**A successful rollback is a failed restore with a safe site, never `completed`.**

---

## 2. Modes: SAFE_MERGE vs MIRROR [IMPLEMENTED+PROVEN]

`App\Backup\V2\Enums\RestoreMode`:

| Mode | Behaviour | Pre-restore backup |
|---|---|---|
| `safe_merge` (default) | overlay the backup's files onto live; **never deletes** a live file absent from the backup | not strictly required |
| `mirror` | reproduce the backup exactly: within scope, live files absent from the backup are deleted (tombstones applied) | **mandatory** (`requiresPreRestoreBackup()`); `RestoreRunner` throws if none produced |

MIRROR deletion is scope-bounded to `mirror_roots` (explicit scope paths, else the covered top segments
like `wp-content`, `wp-admin`) so it never touches a sibling tree the backup omitted. Deleted files are
moved aside to trash (recoverable until commit), not hard-deleted.

---

## 3. RestorePlan [IMPLEMENTED+PROVEN]

`RestorePlan::build($target, $resolver, $reader, $scope)` produces:

- `fileChunks[]` — distinct S3 file-chunk objects to pull+push, **ordered so a later-chain chunk
  overwrites an earlier one** (latest-wins); each carries a `seq` the plugin extracts in ascending order.
- `keepPaths[]` — the exact final-state relative paths (after latest-wins + tombstones); staging is
  pruned to this set.
- `dbChunks[]` — the **target backup's own** DB segments (never reconstructed from a chain).
- `tombstones[]` — paths deleted across the chain (MIRROR belt-and-braces).
- `dbTables[]` — optional table whitelist (selective DB restore).
- `mirrorRoots[]` — the roots MIRROR may delete live-only files under.

**Scope** (`RestoreSession.scope`): `database:bool`, `files:bool`, `paths:list<string>` (relative
prefixes), `db_tables:list<string>`. Absent keys default to a full restore (DB+files, all paths, all
tables). This is how selective restore works — a scope of `{files:true, paths:["wp-content/uploads"]}`
restores just that subtree.

---

## 4. Staging + atomic swap (plugin engine) [IMPLEMENTED+PROVEN]

`SAM_Backup_Restore_Engine` — pure PHP + mysqli + gzopen, **never `shell_exec`**, never imports connector
code.

- **Staging tables:** DB import goes into `sambk_stg_<table>`; originals are renamed to
  `sambk_old_<table>` and kept until commit/rollback.
- **Atomic DB swap:** a **single** `RENAME TABLE` statement swaps all tables at once
  (`` `t` TO `old` `` then `` `staged` TO `t` `` per table). On failure it verifies via `show_tables`,
  builds a reverse RENAME, drops staging, and reports the live DB untouched (or, if the reverse also
  fails, that originals are preserved under `sambk_old_` for manual recovery).
- **DB import:** statement-by-statement from `.sql.gz` via `gzgets`; each statement is rewritten to
  target its `sambk_stg_` table; **zero error tolerance** — any failed query aborts the whole import.
  Out-of-scope tables (selective `db_tables`) are skipped; a statement the rewriter didn't touch (and
  that isn't a benign `SET`/transaction control) aborts rather than run raw against live (defence in
  depth).
- **File swap (journaled):** staged zips extracted in ascending seq (chain order, latest wins), staging
  pruned to the exact `keepPaths` (dropping non-kept, `PROTECTED_FILES` = `wp-config.php`/`.maintenance`,
  and tombstones), then a per-path journaled swap: displaced live → trash, journal written **before** the
  second rename, staged → live (`rename()` atomic on the same filesystem).
- **Zip extraction** is path-traversal safe (rejects `..`/null-byte, target must stay under staging
  root).

---

## 5. Maintenance window [IMPLEMENTED+PROVEN]

Maintenance mode is on **only** for the `restore/apply` call (the exact swap window), not the whole
restore. The `.maintenance` file is written as `<?php $upgrading = <time>;` — WP auto-ignores it after
10 minutes, so even a crash mid-apply cannot lock the site out permanently. Staging/download happen
outside the window.

---

## 6. Pre-restore safety backup [IMPLEMENTED+PROVEN wiring; provider TODO-PROD]

`RestoreRunner` calls an injected `preRestoreBackup` closure and records
`restore_sessions.pre_restore_backup_id`. Mandatory for MIRROR (throws if none produced). It is the
backstop if the journaled rollback is ever insufficient. In `RunRestoreSessionJob` the closure is
currently wired as `null` — the production safety-backup and health-check closures are **[TODO-PROD]**.

---

## 7. Rollback [IMPLEMENTED+PROVEN]

Triggered by a failed post-restore health-check or any mutating-state failure:

1. `RestoreSession` → `rollback` state, records `post_restore_validation_failed` /
   `restore_apply_failed`.
2. `restore/rollback` — the plugin reverses the journal (staged→trash, trash→live) and renames
   `sambk_old_*` back over live (return to pre-apply). Idempotent no-op if nothing was applied.
3. The pre-restore safety backup remains as an operator backstop (`checkpoint.rolled_back`).
4. Session → `failed` ("rolled back to pre-apply").

`RestoreRunner::handleMutatingFailure` calls rollback for any failure from a mutating state; if rollback
itself also fails it records the error and transitions to `failed` (never leaves the session hanging).
`apply()` in the plugin also self-rolls-back a mid-swap failure independently.

---

## 8. Authenticated transfer [IMPLEMENTED+PROVEN]

The old unauthenticated `/restore-download/{token}` is gone. Restore chunks are **pushed** to the plugin
via `restore/stage-chunk` where the **raw chunk bytes are the HMAC-signed body**
(`METHOD|ROUTE|TS|NONCE|BODY`) — a tampered chunk fails the signature. The staged sha256 is verified
against the local sha256 (`verify` phase). See [`PLUGIN-PROTOCOL.md`](PLUGIN-PROTOCOL.md) §2.8.

---

## 9. Commit [IMPLEMENTED+PROVEN]

On a passing health-check, `restore/commit` drops the retained `sambk_old_*` tables, trash, and staging;
the session reaches `completed`. Until commit, everything needed to roll back is kept.

---

## 10. Production TODOs

| Item | Location |
|---|---|
| `decrypting` step (client-side encryption) | `RestoreRunner::decrypt()` |
| Production `preRestoreBackup` closure | `RunRestoreSessionJob` (currently null) |
| Production `healthCheck` closure | `RunRestoreSessionJob` (currently null → validation trivially passes) |
| Per-site plugin creds + prod S3 client | `SimpleadBackupClient::forSite`, `S3ClientFactory::forDestination` |
