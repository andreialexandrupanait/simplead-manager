# simplead-backup V2 — FULL BACKUP

> How a full backup runs end-to-end, driven by `App\Backup\V2\Orchestration\BackupRunner` through the
> `BackupSession` FSM. Format produced: [`FORMAT-SPECIFICATION.md`](FORMAT-SPECIFICATION.md). Wire
> contract: [`PLUGIN-PROTOCOL.md`](PLUGIN-PROTOCOL.md).

Legend: **[IMPLEMENTED+PROVEN]** (P2 gate: backup FULL end-to-end proven restorable — restore-oracle
41/41 files 0/0, DB import 0 errors / 59 tables incl. Woo/HPOS; resume-without-duplication proven).

---

## 1. What a full backup includes

A `full` session has scope `{database: true, files: true}` (derived from `type`, overridable via
`session.scope`). Other types share the same runner: `database` (DB only), `files` (files only),
`incremental` (files diff + full DB — see [`INCREMENTAL-BACKUP.md`](INCREMENTAL-BACKUP.md)).

- **Files:** the whole `ABSPATH` tree, minus exclusions (see [`EXCLUSIONS.md`](EXCLUSIONS.md)), packed
  into `files/chunk_N.zip` (ZipArchive, STORE).
- **Database:** a full logical dump of every base table, consistent single-snapshot, gzip-segmented into
  `database/chunk_N.sql.gz`.

---

## 2. FSM flow

`BackupRunner::run()` drives each state via `BackupSession::transitionTo()` (no shortcuts). Forward
progress is skipped on resume by comparing the ordered `ORDER` index; re-entering the current state is
an idempotent self-transition, so a crash mid-phase re-runs that phase.

```
requested
  → capability_check   probe host (capabilities); if DB in scope and host can't take a
                       consistent snapshot → record snapshot_unavailable warning
  → inventory          files/inventory → save exclusion_policy_hash + scope_hash; persist plan
  → database_export    database/dump (consistent) → per segment database/chunk-download
                       (delete=1 pull-and-free) → putObject database/chunk_N.sql.gz
  → file_diff          full: no baseline (records mode=full)
  → chunking           no-op (plan persisted plugin-side at inventory)
  → upload_initializing  reap abandoned multipart uploads under this backup's prefix
  → uploading          per chunk: files/chunk-exec → files/chunk-download (delete=1) →
                       hardened multipart → files/chunk_N.zip   (empty chunks skipped)
  → upload_verifying   headObject size + re-download & sha256 per confirmed object
  → finalizing         write manifest.json, checksums.json, metadata.json;
                       then _COMPLETE LAST; transitionTo(completed);
                       BackupVerifier::verifyOnComplete (stamps verified_at on pass)
  → completed
```

Any late integrity failure throws `CorruptBackupException` → `corrupt` (never `completed`).

---

## 3. Phase detail

### 3.1 capability_check [IMPLEMENTED+PROVEN]
Calls `capabilities`; records plugin/wp/php/db versions, engine family, consistent-snapshot support,
non-InnoDB tables into `checkpoint.capabilities`. If the DB is in scope but the host cannot take a
consistent snapshot, records `snapshot_unavailable` and a `db_consistency_warning` — enforced later
against the dump's own `consistent=false`.

### 3.2 inventory [IMPLEMENTED+PROVEN]
Calls `files/inventory` with `include_defaults=true`, the resolved `rules`, and (optionally) a
`threshold`/`compression`. Saves `exclusion_policy_hash` + `scope_hash` on the session; records totals
(`total_files`, `total_bytes`, `excluded_files`, `excluded_bytes`, `chunk_count`, `mode`). The plugin
persists the chunk plan server-side (`plan.json`).

### 3.3 database_export [IMPLEMENTED+PROVEN]
Short-circuits if `checkpoint.db_done` is already set (resume). Calls `database/dump` with
`exclude_tables` and optional `segment_bytes`. **If the dump reports `done=false` or `consistent=false`
→ `CorruptBackupException`** (refuse to pass off an inconsistent DB as good). For each segment: skip if
already in `confirmed_objects`; else `database/chunk-download` with `delete=1` (pull-and-free), assert
pull integrity + valid gzip, upload to `database/chunk_N.sql.gz`, record the object (key/size/sha256 +
reported sha). After all segments confirmed, sets `checkpoint.db_done = true`.

### 3.4 uploading [IMPLEMENTED+PROVEN]
For each chunk index `0..chunk_count`: skip if in `confirmed_objects`; call `files/chunk-exec`. **Empty
chunk (`empty=true` or `chunk_size=0`) → recorded in `skipped_empty_chunks`, not downloaded/uploaded/
manifested.** Otherwise `files/chunk-download` (`delete=1`), assert pull integrity + valid zip, upload
via `HardenedMultipartUploader` to `files/chunk_N.zip`, record the object + the per-file entries
(`p, s, m, sha256, chunk_index`) for the manifest.

### 3.5 upload_verifying [IMPLEMENTED+PROVEN]
For every confirmed object: `headObject` (size must match) + full re-download & sha256 (must match) —
else `CorruptBackupException`. (For very large objects, using S3 `ChecksumSHA256`/ranged verification
instead of a full re-download is **[TODO-PROD]**.)

### 3.6 finalizing [IMPLEMENTED+PROVEN]
Writes `manifest.json`, `checksums.json`, `metadata.json`; asserts each is present in storage; then
writes `_COMPLETE` **last** (body carries `manifest_sha256`, `object_count`, `format_version`); asserts
it present; transitions to `completed`. Immediately runs `BackupVerifier::verifyOnComplete` — on pass it
stamps `backup_sessions.verified_at` (read by retention). The verifier is non-fatal (verify-before-
complete already guaranteed wholeness).

---

## 4. Verify-before-complete (the core guarantee) [IMPLEMENTED+PROVEN]

A backup reaches `completed` **only** when every declared object exists in storage with matching size
and sha256, the manifest/checksums/metadata are present, and `_COMPLETE` is the final write. This is
enforced three ways: per-object verify (`upload_verifying`), present-after-write asserts (`finalizing`),
and the `_COMPLETE`-written-last rule. Directly fixes the historical phantom-completed / missing-manifest
defect.

---

## 5. Idempotence & resume [IMPLEMENTED+PROVEN]

- `confirmed_objects` (jsonb) — an uploaded object is never re-pulled/re-uploaded (pull-and-free makes a
  re-pull impossible anyway).
- `checkpoint.db_done` — the DB dump is skipped once all segments are confirmed.
- `confirmed_parts` (jsonb) — multipart resume a layer down (same UploadId, no re-read of confirmed
  parts).
- Re-entering the current FSM state is an idempotent self-transition; forward phases already passed are
  skipped by order index.

Proven: after an injected crash, each chunk is pulled exactly once and confirmed objects keep their
ETag+mtime (0 re-upload).

---

## 6. Consistency & correctness notes

- **DB consistency:** single mysqli connection, `REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT
  SNAPSHOT`, `ORDER BY` PK (D-006). Proven 0 orphans on MySQL 8.0.46 and MariaDB 11.8.
- **Non-InnoDB fallback:** none exists — the dumper unconditionally relies on `CONSISTENT SNAPSHOT`
  (which only gives a consistent read on InnoDB). Non-InnoDB tables are *reported* by capabilities but
  there is no lock/mysqldump fallback. **[TODO-PROD].**
- **`DiskSpaceGuard` pre-dump:** intended but not yet wired. **[TODO-PROD].**
