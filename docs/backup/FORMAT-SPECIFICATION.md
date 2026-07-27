# simplead-backup V2 — BACKUP FORMAT SPECIFICATION

> The on-storage format a V2 backup produces. `format_version = "simplead-backup/1"`
> (`config/backup_v2.php`). Written by `App\Backup\V2\Orchestration\BackupRunner::finalize()`; keys
> built by `Storage\ObjectLayout`. See [`FINAL-ARCHITECTURE.md`](FINAL-ARCHITECTURE.md).

Legend: **[IMPLEMENTED+PROVEN]** unless noted.

---

## 1. S3 object layout (per backup) [IMPLEMENTED+PROVEN]

Tenant-isolated prefix from `config('backup_v2.object_prefix')`:

```
clients/{client_id}/sites/{site_id}/backups/{backup_id}/
  database/
    chunk_0.sql.gz          # full logical dump, gzip segment 0
    chunk_1.sql.gz          # segment 1 …
  files/
    chunk_0.zip             # file payload chunk 0 (ZipArchive, STORE)
    chunk_1.zip             # … (incremental: only changed+new chunks exist)
  manifest.json             # inventory + hashes + chain refs + tombstones
  checksums.json            # per-object sha256 + size
  metadata.json             # type, sizes, versions, timings, profile
  _COMPLETE                 # completion marker — WRITTEN LAST
```

Notes:
- The prefix is expanded once by `ObjectLayout` (`{client_id}/{site_id}/{backup_id}` substituted).
  `listPrefix()` (trailing slash) scopes `ListObjects`/`ListMultipartUploads`.
- `TARGET-ARCHITECTURE.md` also lists `chunks/`, `encryption.json`, `restore.json`, `logs/`.
  In the implemented engine: `ObjectLayout::restore()` (`restore.json`) key exists but the runner does
  not emit it in `finalize()`; `encryption.json`, `chunks/`, `logs/` are **[TODO-PROD]** (not written).
  The authoritative artifacts today are the six under the tree above.

### Segment naming

| Kind | Object key | Producer |
|---|---|---|
| DB segment N | `database/chunk_{N}.sql.gz` | plugin dumper → pulled → `putObject` |
| File chunk N | `files/chunk_{N}.zip` | plugin chunker → pulled → hardened multipart |

DB segment index is parsed from the plugin file name via `/chunk_(\d+)\.sql\.gz$/`. Empty file chunks
are **not produced** (empty-chunk contract) and never appear — their index is recorded in
`manifest.files.skipped_empty_chunks`.

---

## 2. `_COMPLETE` marker [IMPLEMENTED+PROVEN]

**Single source of truth that a backup is whole.** Written LAST, only after every declared object,
`manifest.json`, `checksums.json`, `metadata.json` are confirmed present in storage (verify-before-
complete). A backup with no `_COMPLETE` is treated as incomplete/phantom and refused by restore and by
chain resolution — this directly fixes the historical "191 missing-manifest / phantom-completed" defect.

Body (JSON):

```json
{
  "completed_at": "2026-07-27T12:00:00+00:00",
  "format_version": "simplead-backup/1",
  "manifest_sha256": "<sha256 of manifest.json bytes>",
  "object_count": 7
}
```

---

## 3. `manifest.json` [IMPLEMENTED+PROVEN]

Built by `BackupRunner::buildManifest()`. Fields:

| Field | Type | Meaning |
|---|---|---|
| `format_version` | string | `simplead-backup/1` |
| `engine` | string | `simplead-backup` |
| `engine_version` | string\|null | plugin version reported by capabilities |
| `backup_type` | string | `full` / `incremental` / `database` / `files` |
| `session_id` | int | `backup_sessions.id` |
| `plugin_session_id` | string | `sambk_{session_id}` (WP-side session) |
| `scope.database` / `scope.files` | bool | what was included |
| `scope_hash` | string | sha256 over type + scope + rules + exclude_tables |
| `exclusion_policy_hash` | string | from the plugin (see [`EXCLUSIONS.md`](EXCLUSIONS.md)) |
| `full_base_id` | int\|null | chain base full (null for a full) |
| `chain_position` | int\|null | 1,2,3… within the chain |
| `base_manifest_ref` | int\|null | back-reference to the diffed base full (incremental only) |
| `objects[]` | list | `{kind, key, chunk_index, size, sha256}` sorted by (kind, chunk_index) |
| `files.included[]` | list | `{p, s, m, sha256, chunk_index}` per stored file, sorted by path |
| `files.included_count` | int | count |
| `files.excluded_count` / `excluded_bytes` / `total_bytes` | int | inventory totals |
| `files.skipped_empty_chunks[]` | list<int> | empty chunk indexes skipped |
| `files.tombstones[]` | list<string> | paths present in base, deleted in this incremental |
| `database.name` / `tables[]` / `table_count` / `total_rows` / `excluded_tables[]` / `segment_count` | mixed | DB dump summary |
| `environment.wp_version` / `php_version` / `db_server_version` / `db_engine` / `consistent_snapshot` | mixed | host facts at backup time |
| `started_at` / `completed_at` | ISO-8601 | timings |

`files.included[]` entries carry the per-file `sha256` (produced by the chunker, not inventory) — this
is what a later incremental diffs against and what a restore's chain materialisation keys on.

---

## 4. `checksums.json` [IMPLEMENTED+PROVEN]

```json
{
  "algorithm": "sha256",
  "objects": {
    "clients/1/sites/9/backups/42/database/chunk_0.sql.gz": { "sha256": "…", "size": 12345 },
    "clients/1/sites/9/backups/42/files/chunk_0.zip":        { "sha256": "…", "size": 67890 }
  }
}
```

Keyed by full object key. Used by `BackupVerifier` (at-creation) and `DeepVerifyService` (sampled).

---

## 5. `metadata.json` [IMPLEMENTED+PROVEN]

```json
{
  "backup_type": "full",
  "format_version": "simplead-backup/1",
  "engine_version": "0.4.0",
  "object_count": 7,
  "stored_bytes": 80235,
  "resource_profile": "low_impact",
  "environment": { "...": "same as manifest.environment" },
  "started_at": "…",
  "completed_at": "…"
}
```

`stored_bytes` = sum of object sizes (drives quota/reconciliation).

---

## 6. Chain references [IMPLEMENTED+PROVEN]

An incremental carries `full_base_id`, `chain_position`, `base_manifest_ref`, and `files.tombstones[]`.
`Chain\ChainResolver` rebuilds the ordered chain purely from `full_base_id` + `chain_position` in the
DB; the manifest fields are the human-facing / restore-hint mirror. `materialize()` applies
full→inc→inc with new/changed overwriting by path and tombstones deleting → the exact final file-state.
The **DB is never chained**: every backup has its own full dump, so a restore always uses the target
backup's own `database/` segments. See [`INCREMENTAL-BACKUP.md`](INCREMENTAL-BACKUP.md).

---

## 7. Segment internals

### `database/chunk_N.sql.gz` (gzip) [IMPLEMENTED+PROVEN]

Produced by `SAM_Backup_Consistent_Dumper` (D-006). Plain SQL, gzip level 6, rotated on statement
boundaries at ~`segment_bytes` (default 8 MiB, `config('backup_v2.plugin.db_segment_bytes')`), never
splitting a statement across segments. Content:
- Header: comment block, `SET NAMES utf8mb4;`, `SET FOREIGN_KEY_CHECKS=0;`,
  `SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';`
- Per table: `DROP TABLE IF EXISTS`, `SHOW CREATE TABLE` DDL, multi-row `INSERT`s (batched).
- Footer (only if the dump completed): `SET FOREIGN_KEY_CHECKS=1;` + end marker.
- Binary/BLOB values as `0x…` hex; generated columns omitted from INSERTs; `ORDER BY` on the real PK
  (or all columns when no PK). Snapshot statements are read-only mechanism and are **not** in the text.

### `files/chunk_N.zip` (ZipArchive, STORE) [IMPLEMENTED+PROVEN]

Produced by `SAM_Backup_File_Chunker` (D-004). Files grouped by directory locality up to the chunk
threshold (default 100 MiB, `config('backup_v2.file_chunk_target_mb')`); a file larger than the
threshold becomes its own `oversize` chunk (no intra-file split in v1). Compression **STORE** by default
(manager may override to `deflate` per-site) — STORE is ~13× faster and produces a smaller archive on
already-compressed WP media (measured, D-004). Relative paths preserved; per-file sha256 recorded.

---

## 8. Tombstones [IMPLEMENTED+PROVEN]

A tombstone is a path present in the chain base but absent from the current site at incremental time
(computed by `SAM_Backup_File_Diff`). Stored in `manifest.files.tombstones[]`. On restore,
`ChainResolver::materialize()` deletes them from the materialised state and MIRROR mode removes them
from the live tree; the restore engine also prunes them out of staging.

---

## 9. Format invariants (contract)

1. `format_version` is always `simplead-backup/1`.
2. `_COMPLETE` is the **last** object written; its presence == whole backup.
3. Every object in `manifest.objects[]` has a matching entry in `checksums.json` (same key, sha256, size).
4. No empty file chunk object exists; its index is in `skipped_empty_chunks`.
5. A DB backup that could not take a consistent snapshot never reaches `completed` (→ `corrupt`).
