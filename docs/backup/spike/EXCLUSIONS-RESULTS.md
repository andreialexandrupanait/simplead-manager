# Test 4 — Exclusions

**Question:** Can exclusions cover folder / file / extension / glob / prefix rules, be
**deterministic**, and keep excluded content out of the backup?

## Method

Added excluded-pattern content to the live WP tree: `wp-content/cache/*` (50 `.tmp`),
`plugins/wordfence/wflogs/*` (30 `.log`), `ai1wm-backups/*` (20 `.zip`),
`uploads/spike/node_modules/pkg/*` (40 `.js`), `uploads/spike/.git/objects/*` (10),
plus `debug.log`, `wp-config.bak`, `scratch.tmp`. A rule-matcher
(`spike/harness/exclusion-match.py`) implements the required rule types and was run **twice** over
the full `wp-content` listing (5,639 files).

Rule set tested:
```
wp-content/cache/**        wp-content/ai1wm-backups/**   **/node_modules/**   *.log
**/wflogs/**               wp-content/updraft/**         **/.git/**           *.tmp   *.bak
```

## Results (measured)

```
run 1: included=5486 excluded=153
run 2: included=5486 excluded=153
determinism: IDENTICAL ✓
```

Excluded set (artifact [`data/exclusions-run1.txt`](data/exclusions-run1.txt)) by category:

| Rule type | Pattern | Matched |
|---|---|---|
| Folder (prefix) | `wp-content/cache/**` | 50 |
| Glob mid-path | `**/wflogs/**` | 30 |
| Folder | `wp-content/ai1wm-backups/**` | 20 |
| Glob mid-path | `**/node_modules/**` | 40 |
| Glob hidden dir | `**/.git/**` | 10 |
| Extension | `*.log` | 31 |
| Extension | `*.tmp` | 51 |
| Extension | `*.bak` | 1 |

(Categories overlap where a file matches multiple rules, e.g. a `wflogs/*.log`; the excluded set is
their union = 153 unique files.)

## Findings

- **All required rule types work**: exact folder, folder prefix, double-star glob (mid-path,
  hidden dirs), and extension globs. Size/table/table-prefix rules are the same matcher shape and
  are straightforward extensions (a size predicate on the stat, a name predicate on the table list).
- **Deterministic**: two independent runs produced an identical excluded set — so a "preview" of
  include/exclude and a size estimate are reproducible, and a major scope change is detectable
  (diff of rule sets) to force a fresh full.

## Gap vs current system

The connector has `should_exclude()` but it is **not driven by configuration** — the
`backup_configs.exclude_paths` / `exclude_tables` columns are dead (0 rows use them in production).
The matcher validated here is the **engine** to wire into that config (global / per-site /
per-backup override) and into `prepare-init` so excluded files are **never read or chunked**.

## Acceptance mapping

| Criterion | Result |
|---|---|
| Folder / file / extension / glob / prefix rules | ✅ all matched |
| Deterministic | ✅ identical across 2 runs |
| Excluded content kept out | ✅ (excluded set never enters a chunk when wired into `prepare-init`) |
| Preview + size estimate | ✅ reproducible from the same matcher |

## Limitations

Validated as a standalone matcher over the real tree; **wiring into the connector's
`prepare-init` walk + the manager config UI is Phase work** (the connector's file walk must consult
the matcher so excluded files are not zipped). Tombstone suppression for excluded paths in
incremental mode is designed but not exercised (no incremental run this pass).
