# simplead-backup V2 — RESOURCE PROFILES

> How the engine bounds its footprint on shared WordPress hosts. Profiles live in
> `config/backup_v2.php` under `profiles`; the active default is
> `config('backup_v2.default_profile')` (default `low_impact`). A session records its profile in
> `backup_sessions.resource_profile` and it is echoed into `metadata.json`.

Legend: **[SEED]** = conservative starting value in config · **[TODO-PROD]** = final value to be derived
from capability discovery + latency benchmark (D-007, still open).

---

## 1. The three profiles [SEED]

Values from `config/backup_v2.php` `profiles`:

| Knob | `low_impact` (default) | `normal` | `fast` (VPS/dedicated, opt-in) |
|---|---|---|---|
| `step_seconds` (wall-clock budget per WP step) | 8 | 20 | 45 |
| `memory_budget_mb` (soft cap) | 64 | 128 | 256 |
| `min_free_disk_mb` (disk guard floor) | 512 | 512 | 1024 |
| `file_batch` (files per step) | 200 | 1000 | 4000 |
| `pause_ms` (adaptive pause between steps) | 400 | 100 | 0 |
| `max_concurrency` | 1 | 2 | 4 |

`step_seconds` is deliberately well under Cloudflare's ~100s gateway timeout so no single WP request
risks a 504. `fast` is explicit opt-in only (dedicated/VPS hosts).

These are **conservative seeds** (D-007). The intent is that the final LOW/NORMAL/FAST values are
derived from **host capability discovery** (`capabilities` endpoint: `php.max_execution_time`,
`php.memory_limit_bytes`, `disk.free_bytes`, host load) plus a latency benchmark — not hardcoded. That
derivation is **[TODO-PROD]**; today the engine uses the seed values as-is.

---

## 2. Capability discovery inputs [IMPLEMENTED+PROVEN]

`BackupRunner::capabilityCheck()` calls the plugin `capabilities` endpoint and records, into
`checkpoint.capabilities`: `plugin_version`, `wp_version`, `php_version`, `db_server_version`,
`db_engine`, `consistent_snapshot` (bool), `non_innodb_tables`. The full capability payload also exposes
the raw budget signals a future profile resolver would read:

| Signal | Source field (`capabilities`) |
|---|---|
| Max PHP execution time | `php.max_execution_time` |
| Memory limit | `php.memory_limit_bytes` / `php.memory_limit` |
| Free disk | `disk.free_bytes` |
| Zip / gzip / mysqli availability | `extensions.*`, `transport.*` |
| Consistent-snapshot capable | `database.consistent_snapshot_supported` |
| Non-InnoDB tables | `database.non_innodb_tables[]` |

The plugin also seeds its own limits via options: `sam_backup_segment_bytes` (8 MiB),
`sam_backup_time_budget` (90s), `sam_backup_files_chunk_bytes` (100 MiB), `sam_backup_files_compression`
(`store`).

---

## 3. Time / memory / disk budgets [IMPLEMENTED+PROVEN]

| Budget | Where enforced | Mechanism |
|---|---|---|
| **DB dump wall-clock** | plugin `SAM_Backup_Consistent_Dumper` | soft `time_budget` (default 90s, `config('backup_v2.plugin.db_time_budget')`). On exceed → `done=false` + `cursor`; the manager restarts with a fresh snapshot (never resumes a broken snapshot) |
| **DB segment size** | plugin dumper | rotate gzip segment at `segment_bytes` (default 8 MiB) on statement boundaries |
| **File chunk size** | plugin `SAM_Backup_File_Chunker` | pack up to `threshold` (default 100 MiB); a bigger file becomes its own `oversize` chunk |
| **Temp footprint** | plugin + pull-and-free | bounded by the **largest single chunk** (`expected_temp_peak`), not the total payload — each chunk is pulled-and-freed (`delete=1`) before the next; proven 315 MB peak on a 1.02 GB payload (D-004) |
| **Manager memory** | `HardenedMultipartUploader` | streams parts (default 16 MiB) from a file handle; flat RAM even for multi-GB objects; ZipArchive `addFile` streams (no buffering) |
| **Disk guard floor** | `min_free_disk_mb` per profile | intended pre-dump `DiskSpaceGuard` — **[TODO-PROD]** (not yet wired) |

---

## 4. Auto-suspend / impact criteria

The profiles encode the *intent* to suspend/adapt when the host is tight (`memory_budget_mb` is a soft
cap "suspend if the host memory_limit is tighter"; `pause_ms` an adaptive inter-step pause). The
success criterion for a profile on real traffic (per the rollout gates) is **zero host impact**:

- **0** upstream 5xx caused by the backup,
- **0** PHP OOM / fatal on the WP host,
- **0** disk-full events,
- each WP step completes under `step_seconds` (well under the CF ~100s ceiling).

The automatic runtime suspension based on live host load is **[TODO-PROD]** — the seeds keep every step
bounded so nothing runs unbounded before that logic is benchmarked and wired. See
[`ROLLOUT-RUNBOOK.md`](ROLLOUT-RUNBOOK.md) for the health/perf gates each rollout step must pass.

---

## 5. Overriding

| Override | How |
|---|---|
| Default profile | `BACKUP_ENGINE_V2_DEFAULT_PROFILE` env → `config('backup_v2.default_profile')` |
| File chunk target | `BACKUP_ENGINE_V2_FILE_CHUNK_MB` (default 100) |
| DB segment target | `BACKUP_ENGINE_V2_DB_SEGMENT_BYTES` (default 8 MiB) via `config('backup_v2.plugin.db_segment_bytes')` |
| DB time budget | `BACKUP_ENGINE_V2_DB_TIME_BUDGET` (default 90) |
| Multipart part size | `BACKUP_ENGINE_V2_MULTIPART_PART_MB` (default 16) |
| Compression | per-site override to `deflate` (default `store`) |

All are read via `config()` (never `env()` outside config), per project convention.
