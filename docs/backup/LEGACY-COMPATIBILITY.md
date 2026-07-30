# simplead-backup V2 — LEGACY COMPATIBILITY

> How V2 sees and handles the existing V1 backups (v2-zip / multipart-v3). The rule is **read-only,
> nothing moved, nothing deleted**: V2 builds a classification index over the legacy artifacts and can
> restore them only behind an explicit gate. See [`LEGACY-BACKUPS-DECISION.md`](LEGACY-BACKUPS-DECISION.md).

Legend: **[IMPLEMENTED+PROVEN]** — P6 gate: legacy classification + read-only index proven.

---

## 1. `LegacyBackupReader` [IMPLEMENTED+PROVEN]

`App\Backup\V2\Legacy\LegacyBackupReader` — strictly read-only: parses/normalises legacy metadata,
**never** moves, rewrites, or deletes.

| Format | Constant | Shape | Decoder |
|---|---|---|---|
| Multipart v3 | `FORMAT_MULTIPART_V3 = 'multipart-v3'` | per-backup prefix + `manifest.json` | `BackupManifestV3::decode` |
| v2 zip | `FORMAT_V2_ZIP = 'v2-zip'` | single `.zip` + sidecar `<file>.meta.json` | `BackupSidecarMetadata::decode` |

Methods: `readManifest($json)` (normalises v3: site, type, created_at, wp/php versions,
parent_backup_id, includes_files/database, `composite_checksum` with a sha256-over-column fallback,
total_size, file_count, files), `readSidecar($json)` (normalises v2-zip sidecar), `classifyPath($path)`
(→ `multipart-v3` / `v2-zip` / null), `read($format, $json)` (dispatch; throws on unknown format).

---

## 2. `LegacyImportService` — classification A–F [IMPLEMENTED+PROVEN]

`App\Backup\V2\Legacy\LegacyImportService::importDestination($destination, $persist=false, $siteFilter=null)`
reconciles `backups` rows against storage contents (via `StorageFactory::make()->listRecursive('')`).
**Read-only w.r.t. legacy storage** — it only lists and downloads metadata docs to read them; it never
uploads/moves/rewrites/deletes. Persisting index rows is opt-in (`$persist`) and additionally gated.

Classification (constants on `LegacyBackupIndexEntry`):

| Class | Constant / value | Meaning |
|---|---|---|
| **A** | `CLASS_VALID` = `valid` | completed row + object present + `verification_status = passed` |
| **B** | `CLASS_VERIFICATION_REQUIRED` = `verification_required` | completed + present + not yet verified |
| **C** | `CLASS_RECOVERABLE` = `recoverable` | constant defined (not assigned by current `classify()`) |
| **D** | `CLASS_INCOMPLETE` = `incomplete` | row status ≠ Completed (failed/cancelled) |
| **E** | `CLASS_ORPHANED` = `orphaned` | metadata doc in storage with no `backups` row |
| **F** | `CLASS_PHANTOM` = `phantom` | completed row whose object/manifest is gone |

Plus `CLASS_UNKNOWN` (`unknown`, storage unreadable) and unused `CLASS_QUARANTINED` / `CLASS_INVALID`.
Presence matching is suffix-tolerant (robust to driver `base_path`); multipart expected path
`prefix/manifest.json`, v2-zip the prefix object itself (its sidecar also marked claimed).

---

## 3. Read-only index (`legacy_backup_index`) [IMPLEMENTED+PROVEN]

Model `App\Backup\V2\Models\LegacyBackupIndexEntry`, table `legacy_backup_index` (migration
`…000005_create_legacy_backup_index`). `persist()` uses `updateOrCreate` keyed on
`storage_destination_id` + `path`. Fields: `backup_id`, `site_id`, `format`, `classification`,
`object_present`, `composite_checksum`, `total_size`, `file_count`, `metadata`, `discovered_at`.

The index is a **catalogue** — it records what exists and how healthy it is. It touches nothing in the
legacy storage or the V1 `backups` table.

---

## 4. Command `backup:v2-import-legacy` [IMPLEMENTED+PROVEN]

```
backup:v2-import-legacy
  {--destination= : Restrict to a single StorageDestination id}
  {--site=        : Restrict to a single site id}
  {--apply        : Persist index rows (also requires backup_v2.enabled); default is dry-run}
  {--json         : Machine-readable report}
```

- **Read-only by default** (dry-run: classifies + reports, persists nothing).
- Writing the index is **doubly gated**: `--apply` **and** `config('backup_v2.enabled')`. `--apply`
  with the flag off warns and runs dry.
- It never mutates legacy objects under any flag combination.

---

## 5. Restoring a legacy backup [gated]

`LegacyBackupReader` and the import/index are always safe to run. **Actually restoring** a legacy
artifact through the new reader is gated by `config('backup_v2.legacy_restore_enabled')` (env
`BACKUP_LEGACY_RESTORE_ENABLED`, default false). With defaults, legacy backups are catalogued and
readable but not restorable via V2 — the V1 engine remains the path for legacy restores until the owner
opts in.

---

## 6. Coexistence guarantees

- V2 retention (`ChainRetentionService`) operates on `backup_sessions` **only** — it never deletes V1
  `backups` rows or legacy storage.
- Nothing in the V2 legacy path moves or rewrites a legacy artifact; the migration plan
  ([`DATA-MIGRATION-PLAN.md`](DATA-MIGRATION-PLAN.md)) treats legacy backups as immutable history.
- The V1 engine and its UI are untouched; V2 legacy handling is purely additive.
