# simplead-backup V2 — EXCLUSIONS

> The file-exclusion feature, implemented in `wordpress-plugin/simplead-backup/includes/files/`
> (`class-exclusions.php`, applied by `class-inventory.php`). This replaces the dead
> `exclude_paths/tables` of the old engine with a real, hashed, path-traversal-safe rule system.
> Class: `SAM_Backup_Exclusions` (final).

Legend: **[IMPLEMENTED+PROVEN]** (6/6 exclusion cases proven, 0 leaks into the plan — D-004).

---

## 1. Rule types [IMPLEMENTED+PROVEN]

Each rule is `{type, value, action?}` (`action` = `include` or default `exclude`; a bare string is a
`glob` exclude).

| Type | `value` shape | Matches |
|---|---|---|
| `folder` | relative folder | the folder and everything beneath (`rel === v` or `rel` starts with `v/`) |
| `file` | relative file path | exact path (`rel === value`) |
| `glob` | shell-style pattern | `**/`→`(?:.*/)?`, `**`→`.*`, `*`→`[^/]*`, `?`→`[^/]`, anchored `^…$` |
| `ext` | extension without dot | file extension, case-insensitive |
| `prefix` | string | relative path starts with the string |
| `size` | `{min?, max?}` bytes | file size within bounds (no size stat → no match) |
| `age` | `{older_than?, newer_than?}` seconds | `age = now - mtime`; older-than / newer-than window |
| include-only | any rule with `action: include` | see precedence below (allowlist) |

Age and size use the stat (`size`, `mtime`) supplied by the inventory walk — **the file is never read**
to decide exclusion.

---

## 2. Levels [TODO-PROD aggregation]

`SAM_Backup_Exclusions` applies a **single resolved rule set**; it does not itself know about levels.
The intended composition is global → client → site → schedule → manual, aggregated **manager-side** into
one resolved list handed to the plugin via the `files/inventory` `rules` param. The plugin-side
application, defaults, hashing, and path-guard are implemented and proven; the manager-side multi-level
aggregation is **[TODO-PROD]**. `BackupRunner` currently forwards `session.scope.rules` verbatim.

---

## 3. Precedence [IMPLEMENTED+PROVEN]

`is_excluded($rel, $size, $mtime, $now)`:

1. Any matching **exclude** rule → excluded immediately.
2. Else, if **include** rules exist and none matched → excluded (allowlist / include-only mode).
3. Else → kept.
4. An un-normalisable / traversal path → excluded (fail-safe).

---

## 4. Defaults [IMPLEMENTED+PROVEN]

Merged in unless the caller opts out (`files/inventory` `include_defaults=false`, i.e.
`from_rules($rules, false)`). From `default_rules()`:

```
glob  wp-content/cache/**
glob  wp-content/*/cache/**
glob  **/node_modules/**
glob  **/.git/**
ext   log
ext   tmp
ext   bak
glob  **/error_log
glob  **/debug.log
glob  wp-content/uploads/backup*/**
glob  wp-content/updraft/**
glob  wp-content/ai1wm-backups/**
glob  **/wflogs/**
```

These skip caches, VCS, dependency trees, logs, and *other backup plugins' output* (so V2 never backs up
UpdraftPlus / All-in-One-WP-Migration / Wordfence-logs artifacts recursively).

---

## 5. `exclusion_policy_hash` [IMPLEMENTED+PROVEN]

Stable fingerprint of the resolved rule set. Computed by normalising + deduplicating rules, sorting by
canonical JSON (`strcmp`), then `hash('sha256', canonical_json)`. Key properties:

- Canonical over rule types/values/bounds only — **not** over resolved timestamps, so an `age` rule
  yields the same hash regardless of when the backup runs (`wp_json_encode_compat` ksorts keys for
  reproducibility).
- Returned by `files/inventory` and stored in `backup_sessions.exclusion_policy_hash` and
  `manifest.json`.
- A change in the policy changes the hash → the orchestrator can force a fresh full when the exclusion
  scope changed (so an incremental is never diffed against a base built under a different policy).

`BackupRunner::inventory()` saves the returned `exclusion_policy_hash` onto the session; `scope_hash`
(sha256 over type + database/files + rules + exclude_tables) is computed manager-side alongside it.

---

## 6. Path-traversal guard [IMPLEMENTED+PROVEN]

`normalise_path()`: backslash→slash, collapse `//`, strip `./`, trim slashes; **any `..` segment →
`null` (rejected outright)**. A null path is treated as excluded (fail-safe). The inventory walk
independently enforces `is_within_root()` (each entry's realpath must be the root or under `root/`), so
escaping symlinks are dropped. The chunker and file-diff apply the same realpath-under-root guard before
hashing. This is the same zip-slip / traversal defence the target security model requires.

---

## 7. Preview [IMPLEMENTED+PROVEN]

`files/inventory` with `preview=true` returns counts/bytes (`total_files`, `total_bytes`,
`excluded_files`, `excluded_bytes`, `exclusion_policy_hash`) **without materialising the file list or
creating a session** — used by the UI to show what a rule set would include/exclude before committing.
Exclusion decisions in preview use the same stat-only `is_excluded` path (no file reads).

---

## 8. Database table exclusions

Separate from file rules: `files/inventory` handles files; DB table exclusions travel as
`database/dump` `exclude_tables[]` (from `session.scope.exclude_tables`, surfaced in
`manifest.database.excluded_tables`). The dumper simply omits those base tables.
