# simplead-backup V2 — INCREMENTAL BACKUP

> Incremental strategy: **files only** (changed/new/tombstones vs the chain base); the **database is a
> full logical dump at every backup** (D-012, honouring the design in
> [`TARGET-ARCHITECTURE.md`](TARGET-ARCHITECTURE.md)). No faked "incremental DB" — honest and
> universally restorable on heterogeneous shared hosting.

Legend: **[IMPLEMENTED+PROVEN]** — P3 gate: restore from full+2 incrementals identical to the bit (0
missing / 0 mismatch), tombstones applied, broken chain detected.

---

## 1. Why files-only incremental + full DB

A logical DB dump is small relative to media, universally restorable, and avoids binlog/WAL assumptions
that don't hold on shared hosts. Files, by contrast, are large and mostly unchanged between backups, so
diffing them is where incremental pays off. Every backup therefore carries its **own** full DB dump —
a restore never reconstructs a DB from a chain.

---

## 2. File diff (plugin side, `SAM_Backup_File_Diff`) [IMPLEMENTED+PROVEN]

`files/inventory` accepts a `base_manifest` (`{p, sha256, s, m}` entries flattened from the chain base).
The diff produces:

| Category | Rule | Feeds the plan? |
|---|---|---|
| `new` | path absent from base | yes (changed+new only) |
| `changed` | path in base but content differs | yes |
| `unchanged` | same content | no (never re-uploaded) |
| `tombstones` | base path absent from the current site | recorded in the manifest |

**Detection order:**
1. **Fast-path unchanged** — if base carries both size and mtime and they match the current file
   (`s === base_size && m === base_mtime`), the file is unchanged **without hashing**. (This is why the
   manifest now carries `m` per file.)
2. **sha256 authoritative** — otherwise the file is hashed and compared with `hash_equals`; a differing
   size is still confirmed by hash, so the stored sha256 is the single source of truth. A `touch` that
   didn't change content re-uploads nothing.

Only `changed + new` go to the chunker (same STORE / big-file-own-chunk / empty-chunk-skip rules as a
full). `unchanged` is dropped; deleted paths become tombstones.

---

## 3. Chain model [IMPLEMENTED+PROVEN]

A chain is one full (`full_base_id = null`) plus its ordered incrementals
(`full_base_id = full.id`, `chain_position = 1, 2, 3…`). Resolved by `App\Backup\V2\Chain\ChainResolver`:

| Method | Purpose |
|---|---|
| `resolveChain($target)` | orders `[full, inc_1, …, target]`; refuses a broken chain **before any restore** |
| `baseChainFor($incremental)` | `[full, …, inc_{pos-1}]` — the base a new incremental layers on |
| `materialize($chain, $reader)` | applies full→inc→inc: new/changed **overwrite by path**, tombstones **delete** → exact final file-state |
| `baseFileState($incremental, $reader)` | flattens `baseChainFor` into the plugin `base_manifest` format |

`materialize()` reads each member's `manifest.json` via a `ManifestReader` (`S3ManifestReader` in
lab/prod; in-memory in tests), keying files by relative path with the chunk object key attached (for
restore).

**Broken-chain detection** → `BrokenChainException`, thrown before any restore work:
- missing / not-`completed` base full (`missingBase`),
- missing/invalid `chain_position` (`missingPosition`),
- a gap or duplicate in `chain_position` (`gap`),
- a target position not reachable from the contiguous run (`unreachableTarget`),
- a member manifest missing/unreadable during `materialize`.

---

## 4. How BackupRunner runs an incremental [IMPLEMENTED+PROVEN]

1. `resolveBaseManifest()` — for a `type=incremental` session with a wired `baseManifestProvider`
   (the dispatcher/test supplies the materialised base state from `ChainResolver::baseFileState`),
   returns the base `{p, sha256, s, m}` list; else null (full / no baseline).
2. `inventory()` — sends that as `base_manifest` to `files/inventory`; the plugin plans **only
   changed+new** and reports `tombstones` + a `diff` summary. Records `checkpoint.incremental`.
3. `file_diff()` — records the diff/chain fields for the manifest (`baseline = full_base_id`,
   `tombstone_count`).
4. `database_export()` — **full DB dump as usual** (no DB diff).
5. `uploading()` — uploads only the changed+new chunks (unchanged are absent).
6. `finalize()` — writes `manifest.json` with `full_base_id`, `chain_position`, `base_manifest_ref`
   (= base full id), and `files.tombstones[]`. A new full starts a new chain.

Proven (`IncrementalHttpE2ETest`): an incremental over an **unchanged** fixture uploads **0 file chunks**
but still produces a **full DB dump**.

---

## 5. Restore from a chain [IMPLEMENTED+PROVEN]

`RestorePlan::build()` resolves the chain, `materialize()`s the final file-state, and orders the distinct
file-chunk objects so an earlier-chain chunk is extracted (and thus overwritten) **first** (latest-wins).
`keepPaths` is the exact final path set — staging is pruned to it so a chunk that also carried a
since-deleted/overwritten file can never resurrect it. Tombstones are carried for MIRROR belt-and-braces.
The **DB uses the target backup's own full dump segments**, never a reconstructed one. See
[`RESTORE.md`](RESTORE.md).

---

## 6. Retention is chain-safe [IMPLEMENTED+PROVEN]

`ChainRetentionService` treats the **whole chain** as the atomic unit: a full with an in-window
incremental is never deleted; a chain is selected only when every member is expired; the last valid
full, the last verified session, and any `protected` session (and their chains) are always kept. Dry-run
by default. See [`FINAL-ARCHITECTURE.md`](FINAL-ARCHITECTURE.md) §2.6.

---

## 7. Policy-change safety

Because the `exclusion_policy_hash` fingerprints the resolved rule set (stable across time), a changed
exclusion policy changes the hash — the orchestrator can force a fresh full so an incremental is never
diffed against a base built under a different policy (the forcing decision is manager-side policy;
the hash mechanism is implemented — see [`EXCLUSIONS.md`](EXCLUSIONS.md)).
