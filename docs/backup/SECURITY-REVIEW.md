# Backup Engine V2 — Security Review (P7 audit)

Independent review of the complete V2 backup engine (`app/Backup/V2/**`,
`app/Livewire/Backup/V2/**`, `wordpress-plugin/simplead-backup/**`,
`config/backup_v2.php`, migrations `2026_07_27_00000{1..5}`) prior to pilot.

- **Branch reviewed:** `feature/simplead-backup-production-ready`
- **Verdict:** APPROVED for pilot (gated behind default-false flags). See caveats
  in `KNOWN-LIMITATIONS.md` — the production credential/S3 resolvers are the
  remaining seam and MUST be wired before any real client site is enrolled.
- **Method:** manual code read of every security-relevant path + the full V2
  test suite (137 passed / 0 skipped) against a live WP plugin (spike-wp) and
  real S3 (MinIO), Pint, PHPStan, and `php -l` on all 23 plugin files.

## 1. Production isolation (blast radius)

Every V2 behaviour is gated behind a `config('backup_v2.*')` flag that defaults
to **false**. With defaults, V2 is inert.

| Control | Status | Evidence |
|---|---|---|
| Master kill-switch defaults off | PASS | `config/backup_v2.php` `enabled => env(...,false)` |
| Backup job refuses to run when disabled | PASS | `RunBackupSessionJob::handle()` throws `RuntimeException` if `!config('backup_v2.enabled')` |
| Restore job requires BOTH `enabled` + `restore_enabled` | PASS | `RunRestoreSessionJob::handle()` double gate |
| No V2 scheduler registered | PASS | `routes/console.php` contains **no** V2 schedule; `BackupV2ServiceProvider::boot()` registers only 4 console commands, no `Schedule::` calls |
| UI routes 404 when flag off | PASS | `EnsureBackupV2Ui::handle()` `abort(404)` when `!ui_enabled`; then `abort(403)` for non-admins |
| UI gate = flag AND admin | PASS | `BackupV2Access::allows()` |
| Reconcile command read-only by default | PASS | `reconciliation_writes_enabled => false`; `ReconcileStorageCommand` reports drift only |
| Retention dry-run by default | PASS | `retention_dry_run => true`; delete additionally needs `apply(force:true)` + `enabled` |
| Quota/notifications default to master flag | PASS | both default to `env('BACKUP_ENGINE_V2_ENABLED', false)` |

**Residual isolation note (not a blocker):** even if the flags were flipped in
production today, `RunBackupSessionJob` / `RunRestoreSessionJob` call
`S3ClientFactory::lab()` and `SimpleadBackupClient::forSite()` which currently
fall back to lab MinIO / lab creds (documented TODO seams). A premature enable
would therefore fail to reach production storage rather than mutate it — but the
resolvers MUST be wired (and re-reviewed) before pilot enrolls a real site.

## 2. Plugin endpoint authentication

| Control | Status | Evidence |
|---|---|---|
| HMAC-SHA256 on every endpoint | PASS | `SAM_Backup_REST_Controller::check_permission()` → `SAM_Backup_Auth::validate()` is the permission callback for all backup + restore routes |
| Signed string binds method, route, ts, nonce, body | PASS | `METHOD\|ROUTE\|TIMESTAMP\|NONCE\|BODY` in both `class-auth.php` and manager `SimpleadBackupClient::sign()` |
| Nonce MANDATORY (no legacy no-nonce path) | PASS | `class-auth.php` returns `MISSING_NONCE` 401 if empty |
| Timestamp window (±300s) | PASS | `TIMESTAMP_TOLERANCE = 300`, checked before crypto |
| Anti-replay | PASS | early `get_transient` reject + atomic `wp_cache_add` consume AFTER valid signature + durable `set_transient` fallback; `NONCE_TTL >= tolerance` |
| Constant-time comparisons | PASS | `hash_equals()` for key and signature |
| Restore-chunk body integrity | PASS | raw chunk bytes ARE the signed body, so a tampered chunk fails HMAC; metadata rides as query params (outside `get_route()`) |
| Per-request nonce entropy | PASS | manager sends `bin2hex(random_bytes(16))` |

**Minor:** the early replay check reads a transient while the atomic consume
uses the object cache group. On a host with **no persistent object cache**,
`wp_cache_add` is per-request, so two truly-simultaneous replays of one nonce
could both pass within the same millisecond window before either transient
lands. Sequential replay is fully blocked. Combined with the 300s timestamp
window this is a low-severity concurrency edge; recommend consuming the nonce via
`set_transient` with a `get_transient`-then-`add` compare-and-set as the primary
guard when no object cache is present. Not a pilot blocker.

## 3. Path traversal / zip-slip / injection

| Control | Status | Evidence |
|---|---|---|
| Zip-slip on restore extraction | PASS | `Restore_Engine::extract_zip_into()` rejects `..`/null byte, `realpath()`-contains under staging root, writes `basename($name)` only |
| Inventory symlink escape | PASS | `SAM_Backup_Inventory::is_within_root()` drops any entry whose realpath leaves the root |
| Chunk materialisation containment | PASS | `File_Chunker::exec_chunk()` re-`realpath()`s each file under `root/` before `addFile` |
| session_id / token path traversal | PASS | `SAM_Backup_Temp::session_dir()` and `Restore_Endpoint::engine()` `preg_replace('/[^A-Za-z0-9_\-]/','')`; `chunk_index` cast to int |
| Temp isolation (not under wp-content) | PASS | `SAM_Backup_Temp::root()` = `sys_get_temp_dir()/sam_backup`, 0700 |
| SQL injection — dump identifiers | PASS | `qid()` backtick-escapes; table/column names come from `information_schema`/`SHOW`, not request input; db name `real_escape_string`ed |
| SQL injection — dump values | PASS | `real_escape_string` for text, `0x` hex for binary/BLOB, `NULL` literal |
| SQL injection — restore import | PASS | statements rewritten only to swap the first backtick table to `sambk_stg_*`; any non-rewritable, non-SET/UNLOCK statement **aborts** rather than executing raw |
| Restore table-name length safety | PASS | `prefixed_table_name()` caps at 64 chars with md5 disambiguation |

**Defense-in-depth gap (minor):** `apply_files()` builds live/staging paths from
manager-supplied `keep_paths` / `tombstones` / `mirror_roots` relative strings
and `@unlink`/`rename`s them without an explicit `..` reject. In the real flow
these paths originate from the plugin's own realpath-guarded inventory (so they
never contain `..`) and the endpoint is HMAC-authenticated, so exploitation
requires the signing secret. Recommend adding a `strpos($rel,'..')` guard in
`apply_files`/`delete_unit` as belt-and-braces. Not a pilot blocker.

## 4. Secret handling & SSRF

| Control | Status | Evidence |
|---|---|---|
| No permanent S3 credentials stored in WP | PASS | plugin never touches S3; manager PULLS objects and PUSHES chunks. Plugin holds only its own HMAC key/secret |
| Presigned TTL short | PASS | `presigned_ttl_seconds => 600`; spike proved 3s TTL → 403 after expiry, 120s → 200 |
| Support package redacts secrets | PASS | `class-admin-page.php::redact()` masks/`(redacted)`s any key/secret/token/password; api_secret shown as `(set, redacted)`; download guarded by `manage_options` + `check_admin_referer` |
| Logs carry no secrets | PASS | `BackupLogger` context is ids/state/error-code only; `SimpleadBackupClient` logs status + error code, never key/secret/body |
| SSRF surface | PASS (bounded) | manager only calls `$site->url` (admin-controlled stored value) + configured S3 endpoint; downloads `sink` to temp; no user-supplied fetch URL |
| Restore never overwrites wp-config/.maintenance | PASS | `PROTECTED_FILES` excluded from swap and mirror-delete |

## 5. Backup / restore correctness (security-adjacent integrity)

| Control | Status | Evidence |
|---|---|---|
| Verify-before-complete | PASS | `BackupRunner::verify()` HEADs + sha256-re-downloads every object before `_COMPLETE`; missing/mismatch → `CorruptBackupException` → `Corrupt`, never `Completed` |
| `_COMPLETE` written LAST | PASS | `verify()` writes/asserts complete marker as final object |
| Corrupt detection at create | PASS | `BackupVerifier::verifyOnComplete()` → `STATUS_CORRUPT` on missing/size-mismatch, only PASS stamps `verified_at` |
| DB consistency refuses inconsistent completion | PASS | `BackupRunner` throws `CorruptBackupException` if dump `done=false/consistent=false` |
| Atomic restore swap | PASS | DB multi-table `RENAME` (atomic); files journaled per-path rename + trash |
| Guaranteed rollback | PASS | `apply()` self-rolls-back mid-swap; `RestoreRunner::rollback()` idempotent + pre-restore safety backup backstop; health-check failure → rollback |
| Maintenance only in critical window | PASS | `maintenance_on()` only inside `apply()`, off on success/failure/rollback; `.maintenance` self-expires after 10 min |
| SAFE_MERGE never deletes | PASS | `delete_units` populated only in `MODE_MIRROR` |
| Empty-chunk contract | PASS | `File_Chunker` never writes an empty zip; download returns 409 EMPTY_CHUNK |
| Temp bounded | PASS | one chunk pulled-and-freed at a time; peak = max(largest file, threshold) |

## Findings summary

- **Critical:** none.
- **Major:** none in the code under review. The production credential/S3
  resolvers are unimplemented (documented TODO seams) — a functional gap, not a
  vulnerability, but a hard prerequisite before real-site enrolment.
- **Minor (hardening, non-blocking):**
  1. Nonce anti-replay concurrency edge on no-object-cache hosts (§2).
  2. `keep_paths`/`tombstones` lack an explicit `..` reject in `apply_files` (§3).

## Verdict

**APPROVED for pilot** under the default-false safety contract. The engine is
inert in production, all endpoints are HMAC+mandatory-nonce authenticated,
extraction/import paths are traversal- and injection-safe, secrets are never
persisted in WP or logs, and backup/restore integrity is enforced
(verify-before-complete, atomic swap, guaranteed rollback). The two minor
hardenings above should be scheduled; the production resolvers must be wired and
re-reviewed before any client site is turned on.
