# simplead-backup V2 — PLUGIN REST PROTOCOL (`simplead-backup/v1`)

> The wire contract between the manager (`App\Backup\V2\Plugin\SimpleadBackupClient`) and the WP plugin
> (`wordpress-plugin/simplead-backup/`). Plugin version **0.4.0** (`SAM_BACKUP_VERSION`), namespace
> constant `SAM_BACKUP_REST_NAMESPACE = 'simplead-backup/v1'`. All routes mount at
> `<site>/wp-json/simplead-backup/v1/…`.

Legend: **[IMPLEMENTED+PROVEN]** unless noted.

---

## 1. Authentication — HMAC-SHA256 + mandatory nonce [IMPLEMENTED+PROVEN]

Every endpoint's `permission_callback` is `SAM_Backup_REST_Controller::check_permission()` →
`SAM_Backup_Auth::validate()`. **No WP user/capability is involved** — auth is purely API key + HMAC +
nonce.

**String to sign (exact):**

```
string_to_sign = METHOD | ROUTE | TIMESTAMP | NONCE | BODY
signature      = hash_hmac('sha256', string_to_sign, secret)   // hex
```

- `METHOD` — uppercased HTTP method.
- `ROUTE` — the WP REST route **without** `/wp-json` and **without** query string, e.g.
  `/simplead-backup/v1/capabilities` (from `$request->get_route()`).
- `BODY` — the raw request body byte-for-byte (empty string for a param-less call; the **raw chunk
  bytes** for `restore/stage-chunk`).
- Fields joined with `|`.

**Headers** (plugin's own names; the connector's `X-SAM-*` names are accepted as a fallback, but the
manager always sends the dedicated ones):

| Purpose | Header | Fallback |
|---|---|---|
| API key | `X-SAM-Backup-Key` | `X-SAM-Key` |
| Timestamp | `X-SAM-Backup-Timestamp` | `X-SAM-Timestamp` |
| Nonce | `X-SAM-Backup-Nonce` | `X-SAM-Nonce` |
| Signature | `X-SAM-Backup-Signature` | `X-SAM-Signature` |

**Windows / replay:** `TIMESTAMP_TOLERANCE = 300s` (reject if `abs(now - ts) > 300`);
nonce **mandatory** (missing → `MISSING_NONCE` 401), `NONCE_TTL = 300s`, cache key
`sam_backup_nonce_<sha256(nonce)>`. Replay defended twice: an early `get_transient` check, then an
atomic `wp_cache_add` set-if-absent after signature validation, backed by `set_transient`.

**Error codes (all 401 unless noted):** `MISSING_AUTH_HEADERS`, `MISSING_NONCE`, `INVALID_API_KEY`
(`hash_equals`), `EXPIRED_REQUEST`, `NONCE_REUSED`, `INVALID_SIGNATURE`, `SERVER_NOT_CONFIGURED` (500,
empty secret).

**Credentials (plugin side):** `SAM_Backup_Options::api_key()` → option `sam_backup_api_key` (lab
fallback to connector `sam_api_key`); `api_secret()` → `sam_backup_api_secret` (fallback `sam_api_secret`).
**Manager side:** `SimpleadBackupClient` reads `config('backup_v2.plugin.key|secret')`. Per-site
credential resolution (`SimpleadBackupClient::forSite()` decrypting from the Site row) is **[TODO-PROD]**.

---

## 2. Endpoint reference

All routes are `POST` unless noted. Session id default (plugin) when omitted:
`sess_<Ymd_His>_<6-char>`. The manager always passes `session_id = sambk_{backup_session_id}`.

### 2.1 `capabilities` (GET, POST) [IMPLEMENTED+PROVEN]

Host capability discovery. No params. Returns nested JSON:

| Group | Fields |
|---|---|
| `plugin` | `name`, `version`, `rest_namespace` |
| `php` | `version`, `sapi`, `max_execution_time`, `memory_limit`, `memory_limit_bytes`, `post_max_size`, `upload_max_filesize`, `open_basedir` |
| `wordpress` | `version`, `is_multisite`, `abspath`, `table_prefix` |
| `database` | `server_version`, `engine_family` (`mysql`/`mariadb`), `name`, `consistent_snapshot_supported`, `consistent_snapshot_note`, `non_innodb_tables[]`, `all_innodb` |
| `extensions` | `zip`, `gzip`, `zlib`, `pdo`, `mysqli`, `openssl`, `curl` |
| `shell` | `shell_exec_available`, `exec_available`, `proc_open_available`, `note` (**reported only; the engine never invokes shell functions**) |
| `disk` | `temp_dir`, `free_bytes` |
| `transport` | `multipart_upload_supported`, `streaming_gzip` |
| `backup_strategy` | `consistent_snapshot`, `recommended` |
| — | `generated_at` |

`consistent_snapshot_supported` is probed by a dedicated mysqli connection running
`SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`;
`non_innodb_tables` from `information_schema.TABLES WHERE ENGINE <> 'InnoDB'`.

### 2.2 `files/inventory` [IMPLEMENTED+PROVEN]

Walk + exclude + plan chunks; persists `plan.json` server-side. Incremental when `base_manifest` present.

Request: `session_id?`, `rules[]?`, `include_defaults?` (default true), `threshold?`, `compression?`
(default `store`), `preview?`, `base_manifest[]?` (`{p, sha256?, s?, m?}`).

Response: `ok`, `session_id`, `root`, `threshold`, `compression`, `exclusion_policy_hash`, `mode`
(`full`/`incremental`), `total_files`, `total_bytes`, `excluded_files`, `excluded_bytes`, `chunk_count`,
`expected_temp_peak` (largest single chunk), `chunks[]` (`{index, file_count, size, oversize}`), `diff`
(null for full), `tombstones[]`. `preview=true` returns counts/bytes only, creates no session.

### 2.3 `files/chunk-exec` [IMPLEMENTED+PROVEN]

Materialise one chunk as `chunk_{N}.zip` (ZipArchive, STORE default). Request: `session_id`,
`chunk_index`. Response: `ok`, `session_id`, `chunk_index`, `empty`, `skipped`, `chunk_size`, `sha256`,
`file_count`, `files[]` (`{p, s, sha256, chunk_index}`).

**Empty-chunk contract:** a chunk that materialised 0 files sets `empty=true`, `chunk_size=0`, the zip
is not written — the manager skips download/upload/manifest for it. Errors: `NOT_FOUND` 404 (no plan),
`INVALID_CHUNK` 400 (index out of range), `CHUNK_FAILED` 500.

### 2.4 `files/chunk-download` (RAW BINARY) [IMPLEMENTED+PROVEN]

Streams the zip. Request: `session_id`, `chunk_index`, `delete?`. Response is **raw `application/zip`**
(not JSON): buffers cleared, streamed in 512 KiB reads, `exit`. Headers `Content-Length`,
`X-SAM-Chunk-Index`, `X-SAM-Chunk-Sha256`. **Pull-and-free:** `delete=true` → `@unlink` the zip after
send (keeps the manifest fragment + `.done` marker). Errors: `NOT_FOUND` 404 (no plan), `NOT_READY` 400
(chunk not exec'd — `.done` marker absent), `EMPTY_CHUNK` 409 (empty chunk, no zip).

### 2.5 `database/dump` [IMPLEMENTED+PROVEN]

Runs `SAM_Backup_Consistent_Dumper`. Request: `session_id?`, `time_budget?` (default 90),
`segment_bytes?` (default 8 MiB), `exclude_tables[]?`. Response = dumper manifest + `session_id` +
`output_dir`, keys include `ok`, `done`, `consistent`, `database`, `server_version`, `engine`,
`tables[]`, `table_count`, `total_rows`, `segments[]` (`{file, path, gzip_bytes, uncompressed_bytes,
sha256}`), `segment_count`, `snapshot`, `cursor`. **HTTP 200 if `done`, else 202** (soft time budget
exceeded → `done=false`/`consistent=false` + `cursor`; the manager restarts with a fresh snapshot —
resume across requests would break consistency). `consistent` is true only if the single-snapshot run
finished.

### 2.6 `database/chunk-download` (RAW BINARY) [IMPLEMENTED+PROVEN]

Added in v0.2.0 (D-011a), symmetric with `files/chunk-download`. Streams `chunk_{N}.sql.gz`. Request:
`session_id`, `chunk_index`, `delete?`. Response is **raw `application/gzip`**, headers
`Content-Length`, `X-SAM-Chunk-Index`, `X-SAM-Chunk-Sha256`. **Pull-and-free** via `delete=true`.
Path-guarded (`realpath` must stay inside the session's `database/` dir); `NOT_FOUND` 404 otherwise.

### 2.7 `restore/prepare` [IMPLEMENTED+PROVEN]

Creates the restore work dir + `plan.json`. Request: `token` (**required**), `mode?` (default
`safe_merge`), `scope?`, `mirror_roots[]?`, `keep_paths[]?`, `db_tables[]?`, `tombstones[]?`. Response =
engine `prepare()` result (includes `mode`). Error `PREPARE_FAILED` 500.

### 2.8 `restore/stage-chunk` (RAW BODY) [IMPLEMENTED+PROVEN]

**The raw chunk bytes ARE the signed body** — a tampered chunk fails HMAC. Metadata rides as **query
params** (not part of the signed route): `token`, `kind` (`files`|`database`), `seq` (int), `sha256`.
Body = the chunk bytes (`application/octet-stream`). The engine copies to staging and verifies the
landed sha256 (`hash_equals`), idempotent. Errors: `BAD_REQUEST` 400 (missing token/kind/sha256),
`EMPTY_CHUNK` 400 (empty body), `WRITE_FAILED` 500, `STAGE_FAILED` 422 (sha mismatch / stage error).

### 2.9 `restore/apply` [IMPLEMENTED+PROVEN]

The only mutating window; maintenance mode on for exactly this call. DB import + `sambk_stg_*`→live
RENAME swap, then journaled file swap. Request: `token`. Response = engine result (`db`, `files`,
`applied`). Error `APPLY_FAILED` 500 (each phase self-rolls-back before rethrow).

### 2.10 `restore/commit` [IMPLEMENTED+PROVEN]

Drops retained `sambk_old_*` tables + trash + staging. Request: `token`. Error `COMMIT_FAILED` 500.

### 2.11 `restore/rollback` [IMPLEMENTED+PROVEN]

Reverse the journal + rename `sambk_old_*` back over live (return to pre-apply). Request: `token`.
Idempotent no-op if nothing was applied. Error `ROLLBACK_FAILED` 500.

### 2.12 `restore/status` (GET, POST) [IMPLEMENTED+PROVEN]

Request: `token`. Returns the engine `status.json` (`prepared`/`applied`/`committed`/`rolled_back`/…).

### 2.13 `diagnostic` (GET, POST) [IMPLEMENTED+PROVEN]

Not used by the backup flow. Returns `ok`, `plugin`, `version`, `temp_root`, `temp_exists`,
`temp_free_bytes`, `api_key_set`, `log_tail` (50 lines), `time`. Same HMAC auth.

---

## 3. Manager client mapping

`App\Backup\V2\Plugin\SimpleadBackupClient` implements both `PluginClient` and `RestoreClient`:

| Client method | Endpoint |
|---|---|
| `capabilities()` | `POST capabilities` |
| `filesInventory($sid, $rules, $opts)` | `POST files/inventory` |
| `filesChunkExec($sid, $i)` | `POST files/chunk-exec` |
| `filesChunkDownload($sid, $i, $deleteAfter)` | `POST files/chunk-download` (sink to temp) |
| `dbDump($sid, $params)` | `POST database/dump` |
| `dbChunkDownload($sid, $i, $deleteAfter)` | `POST database/chunk-download` (sink to temp) |
| `restorePrepare/StageChunk/Apply/Commit/Rollback/Status` | `POST restore/*` |

Downloads sink to a `tempnam` file and wrap it in a `DownloadedChunk` (size + local sha256 + reported
`X-SAM-Chunk-Sha256` + HTTP status); a non-2xx download body is surfaced as a `PluginClientException`,
never stored as chunk data. `connectTimeout(15)`, request timeout `config('backup_v2.plugin.timeout')`
(default 120s).

---

## 4. Contracts summary

- **Empty-chunk:** files chunk with 0 files → `empty=true` (exec) / `EMPTY_CHUNK` 409 (download); never
  downloaded, never in the manifest. Restore empty body → `EMPTY_CHUNK` 400.
- **Pull-and-free (`delete=1`):** on both chunk-download endpoints only — the streamed artifact is
  unlinked after send, keeping WP temp bounded by the largest single chunk (`expected_temp_peak`).
- **Consistency gate:** `database/dump` returns `done`/`consistent`; the manager refuses a
  `done=false`/`consistent=false` dump (→ `corrupt`).
- **Chunk integrity:** every streamed chunk carries `X-SAM-Chunk-Sha256`; the manager re-hashes locally
  and re-verifies again from S3 (`upload_verifying`).
