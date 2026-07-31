All findings verified against the code. Every seam behaves exactly as the JSON describes. Producing the consolidated report.

---

# Backup-v2 Security Re-Review — Production Seams (9bd8227~1..HEAD)

Scope: S3 credential decrypt, per-site HMAC signing, restore health-gate + pre-restore safety backup, fail-closed allowlist gate, RunBackup/RunRestore session jobs. Read-only. Duplicate JSON entries consolidated; all findings below are code-grounded and confirmed.

---

## MAJOR

### M1 — Tombstone path traversal → arbitrary file deletion/move outside staging
**File:** `wordpress-plugin/simplead-backup/includes/restore/class-restore-engine.php:709` (and MIRROR loop `734-739`)

**Confirmed.** In `apply_files()` the tombstone-pruning loop is:
```php
foreach ($tombstones as $tomb) {
    @unlink($staging_dir . '/' . ltrim((string) $tomb, '/'));   // line 709
}
```
Only `ltrim('/')` is applied — **no `..`-segment rejection, no realpath containment, no `PROTECTED_FILES` check** — even though the keep-loop one line above (704) *does* apply `PROTECTED_FILES`, and the engine's own zip extractor (`898`, `905-906`) *does* reject `..`/NUL and re-check realpath containment. `staging_dir = ABSPATH.'/sam-restore-staging-{token}'`, so a tombstone of `../wp-config.php` resolves to `ABSPATH/wp-config.php` and is permanently `@unlink`ed. This is a raw unlink, **not journaled** — trash/rollback do not cover it, so it is unrecoverable. It runs in **both** modes, including the supposedly non-destructive `SAFE_MERGE` (the loop is unconditional, before the `MODE_MIRROR` branch). The MIRROR loop (`734-739`) has the same gap and additionally feeds `..` paths into `delete_unit()` (`822`), rename-moving arbitrary live files (this path is journaled → recoverable).

Tombstones are unsanitized end-to-end across the trust boundary:
- `RestorePlan::build` (`app/Backup/V2/Restore/RestorePlan.php:123-124`) copies manifest `files.tombstones` with only `trim('/')` — internal `..` survives.
- `RestoreRunner::download` (`app/Backup/V2/Restore/RestoreRunner.php:208`) forwards `$plan->tombstones` verbatim to `restore/prepare`.
- Plugin `stage`/`prepare` (`class-restore-endpoint.php:105` → engine `prepare()` line 111) only `array_map('strval')`s them into `plan.json`.

**Exploit scenario:** An attacker able to write objects into the site's S3 backup bucket (or a compromised/buggy manager) plants a manifest whose `files.tombstones` contains `../wp-config.php` (or `../../<path writable by the PHP user>`). On the next restore, the value flows verbatim to line 709, which executes `@unlink(ABSPATH/wp-config.php)` — permanently deleting live `wp-config.php` with no rollback and instantly breaking the site. Because line 709 runs in `SAFE_MERGE` too, even a "non-destructive" restore becomes an arbitrary-delete primitive.

**Severity rationale:** MAJOR, not CRITICAL: the restore endpoint is HMAC-authenticated, so triggering requires a poisoned manifest already trusted+forwarded by the legitimate manager, or a compromised manager — not an unauthenticated external attacker. But zero validation exists on either side of that boundary, so the destructive primitive is real.

**Fix:** In both tombstone loops, reject any `$tomb` containing a `..` path segment, a leading `/`, or a NUL byte; after building the candidate path, `realpath(dirname())` and assert containment under `$staging_dir` (709) / under `$this->abspath` (mirror loop 736), matching the zip extractor. Apply `PROTECTED_FILES` in the tombstone loop as the keep-loop already does at 704. Ideally normalize/validate in `prepare()` so `plan.json` can never persist a traversal path. Defense-in-depth: also reject `..`-segment tombstones in `RestorePlan::build` (`RestorePlan.php:124`) rather than only `trim('/')`.

---

## MINOR

### m1 — Nonce anti-replay TOCTOU on hosts without a persistent object cache
**File:** `wordpress-plugin/simplead-backup/includes/support/class-auth.php:84`

**Confirmed.** Early check is `get_transient()` (61); atomic consume is `wp_cache_add()` (84) then `set_transient()` (88). On a default WP install with no persistent object cache, `wp_cache_*` is per-PHP-request only, so `wp_cache_add()` always returns `true` for every distinct process. Durable dedup rests solely on the transient, which has a TOCTOU: two concurrent identical valid requests both pass `get_transient()` (61) before either reaches `set_transient()` (88), both pass HMAC, both `wp_cache_add()` return fresh → both execute.

**Scenario:** An on-path/TLS-terminating party captures one valid signed request and fires N copies in parallel inside the 300s window; a state-mutating route (e.g. `restore/apply`, chunk write) double-executes. Requires an already-valid captured request.

**Fix:** Use a DB-atomic set-if-absent as the authoritative guard — `INSERT` into a dedicated nonces table with a UNIQUE constraint, or `add_option($nonce_key,1)` (atomic on the options unique key); treat duplicate-key failure as `NONCE_REUSED`. Keep `wp_cache_add` only as a fast-path when `wp_using_ext_object_cache()` is true.

### m2 — Nonce TTL equals timestamp tolerance; valid window is twice as wide (clock-skew replay)
**File:** `wordpress-plugin/simplead-backup/includes/support/class-auth.php:27-28`

**Confirmed.** `TIMESTAMP_TOLERANCE = 300` and `NONCE_TTL = 300`, but the timestamp check is `abs(now - ts) <= 300` (55), i.e. a timestamp is accepted over a **600s** span `[ts-300, ts+300]`. The nonce transient lives only 300s from first arrival. If the manager's clock leads the WP server by `delta`, a request arriving at server-time `ts-delta` has its nonce expire at `ts-delta+300` while its timestamp stays valid until `ts+300` — leaving a `delta`-second gap where the timestamp still passes but the nonce is gone, so the request can be replayed. The header comment's "`NONCE_TTL >= tolerance`" (28) is insufficient; it must be `>= 2 × tolerance`.

**Fix:** Set `NONCE_TTL >= 2 × TIMESTAMP_TOLERANCE` (e.g. 600), or key the nonce until `timestamp + TOLERANCE` rather than a fixed TTL from arrival.

### m3 — restore/stage-chunk routing metadata (token/kind/seq/sha256) is outside the HMAC signature
**Files:** `app/Backup/V2/Plugin/SimpleadBackupClient.php:157-176`; plugin `class-restore-endpoint.php:119-122`; signed string built in `class-auth.php:70-76`

**Confirmed.** For `stage-chunk` the signed string is `METHOD|ROUTE|TS|NONCE|BODY` where BODY = raw chunk bytes, and `WP_REST_Request::get_route()` (72) returns the path **without** the query string. `token/kind/seq/sha256` ride as **unsigned** query params (client 157-176, read back at endpoint 119-122). So the HMAC proves "the manager sent these bytes" but not "these bytes are seq N / kind K / token T / hash H." The single-use nonce blocks replay but not modification of the one in-flight request.

**Scenario:** A TLS-terminating party on the manager→WP origin hop (relevant given the Cloudflare-proxied origin per CLAUDE.md, if that hop is plain HTTP) rewrites `seq` or `kind` on a legitimate in-flight `stage-chunk` while the HMAC still validates — writing a valid-signed chunk into the wrong sequence/kind slot and silently corrupting restore staging. Since restore writes to the live site, the payload↔position binding is security-relevant.

**Fix:** Fold a canonical `token|kind|seq|sha256` into `string_to_sign` on both `SimpleadBackupClient::sign()` and `SAM_Backup_Auth::validate()` (or sign the full request URI including query on both sides), so a tampered query fails validation.

### m4 — SAFE_MERGE restore proceeds with no pre-restore safety backup when the safety backup silently fails
**File:** `app/Backup/V2/Restore/RestoreRunner.php:182-197` (guard at 188)

**Confirmed.** `preRestore()` throws only when `mode()->requiresPreRestoreBackup()` — which is true **only for MIRROR** (`RestoreMode.php:23-26`). For `SAFE_MERGE`, if `PreRestoreSafetyBackup` returns `null` (e.g. the site was already unreachable so `BackupRunner` never reached `Completed` — `PreRestoreSafetyBackup.php:75-82`), the runner records no `pre_restore_backup_id` and proceeds to mutate live DB/files, relying solely on the plugin journal/trash — the exact insufficiency the safety backup exists to backstop (`RestoreRunner.php:367-368`). Impact is bounded (SAFE_MERGE never deletes, keeps trash+old tables until validation), but the documented backstop is silently absent.

**Fix:** Either require a completed safety backup for SAFE_MERGE too (throw on null), or explicitly gate/annotate that SAFE_MERGE may run without one and surface a loud operator warning rather than continuing silently.

---

## INFO

### i1 — Hardcoded lab MinIO credentials as fallback defaults
**File:** `app/Backup/V2/Storage/S3ClientFactory.php:40-47`

**Confirmed but inert in production.** `S3ClientFactory::lab()` hardcodes `spikeadmin`/`spikeadmin123` and `http://spike-minio:9000` as fallbacks when `config('backup_v2.lab_s3.*')` is unset. No production path calls `lab()` — the prod path `forDestination()` (65+) decrypts the site's real `StorageDestination` and never touches these defaults. Source-committed lab secrets that matter only if the lab MinIO were exposed; not a production credential leak.

**Fix (optional):** Source lab defaults from config with no hardcoded secret fallback, or leave as-is. No action required for the production seam.

### i2 — Health-gate treats 3xx as healthy; files-only restore can pass while site redirects to WP install/setup
**File:** `app/Backup/V2/Restore/RestoreHealthCheck.php:82` (and 56-58)

**Confirmed.** `siteResponds()` accepts any 2xx **or** 3xx as healthy. For a files-only restore (`plan->includeDatabase` false) the DB probe is skipped (56-58), so reachability is the entire gate. A restore leaving WordPress `302`→`wp-admin/install.php`/setup would be judged healthy and committed rather than rolled back. Low likelihood and self-limiting, but weaker than the docstring implies.

**Fix:** For files-only restores, require 2xx, or assert the redirect target is the canonical site URL (not install/setup), or still hit the connector health endpoint for a liveness signal.

---

## Seams found sound
- `forDestination()` S3 credential decrypt (`S3ClientFactory.php:65+`) reuses the exact V1 `decrypt()` path; no secret duplicated in code — sound.
- HMAC body integrity: chunk bytes are the signed BODY, so tampered chunk bytes fail validation (m3 concerns only the unsigned query metadata, not the body) — sound.
- Nonce is mandatory with no legacy no-nonce path; nonce consumed only after a valid signature (`class-auth.php:44-46, 83-87`) — sound.
- Zip extractor traversal hardening (`class-restore-engine.php:898, 905-906`) — sound (and is the correct model M1 should copy).
- MIRROR pre-restore safety-backup enforcement (`RestoreRunner.php:188`) — sound for MIRROR (gap is SAFE_MERGE, m4).

---

## DoD criterion 26 — Does post-resolver HEAD have any unresolved CRITICAL or MAJOR issues?

**YES — one unresolved MAJOR.** Finding **M1 (tombstone path traversal, `class-restore-engine.php:709` / `734-739`)** is a confirmed, unfixed arbitrary file deletion/move primitive that escapes the restore staging root and runs in both restore modes with no sanitization on either side of the manager↔plugin boundary. No CRITICAL issues. Criterion 26 is **NOT met** until M1 is remediated; the four MINOR and two INFO items do not by themselves block it.