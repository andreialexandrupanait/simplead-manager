# Acceptance Tests

> Test matrix for Snapshot-parity. Framework: **PHPUnit** (repo standard) + `Storage::fake`,
> `Queue::fake`, `FakeWordPressApiService`, mocked S3/Dropbox. **No E2E test uses a real client
> site** — pilot/sandbox sites only, behind an explicit gate.

## 1. Unit

| Area | Assertion |
|---|---|
| Manifest | build/parse round-trips; missing entry → invalid; chain ref resolves |
| Chunking | file grouped ≤ target size; large table row-range split; deterministic order |
| Hashing | sha256 stable; mismatch detected |
| Encryption | AES-256-GCM encrypt→decrypt round-trip; wrong key fails closed |
| State transitions | only legal `BackupSession`/`RestoreSession` edges allowed; illegal → exception |
| Retention | chain base preserved; protected preserved; last-verified preserved; dry-run mutates nothing |
| Chain resolution | full+increments ordered; broken chain detected |
| Idempotency | duplicate step/callback is a no-op |
| Retry | transient error → backoff; permanent → fail once |
| Cleanup | temp/staging removed on success and on failure |

## 2. Integration

| Scenario | Pass condition |
|---|---|
| S3 multipart (mock) | all parts complete; ETags verified; manifest matches |
| Interrupted upload → resume | resumes from last confirmed part, no re-upload of confirmed parts |
| Full backup | manifest + `_COMPLETE` + all objects present + checksums match |
| Incremental backup | only changed/new files + tombstones stored; references full base |
| Database-only | archive has SQL, no files |
| Files-only | archive has files, no SQL |
| Restore (full) | DB + files restored; post-restore health passes |
| Selective restore | only selected scope changes; rest untouched |
| Broken chain | detected pre-restore; refuses with clear error |
| Missing object | `completed` blocked; surfaced as `object_missing` |
| Bad checksum | backup marked `corrupt`, not `completed` |
| Legacy import | v2-zip/v3-zip indexed + restorable via `LegacyBackupReader` |
| used_bytes reconcile | derived total within ±1% of summed objects |

## 3. E2E (sandbox/pilot only)

Single-site, multisite, large uploads (>3 GB), large DB, low `memory_limit`, `DISABLE_WP_CRON`,
PHP timeout, network interruption, security-plugin/WAF conflict, shared-hosting limits. Each:
backup completes (or resumes to completion) and a proven restore reproduces the site.

## 4. Failure injection

| Inject | Expected |
|---|---|
| Kill worker mid-backup | resumes from checkpoint; no duplicate objects |
| Restart PHP mid-restore | rolls back or resumes; site never left broken |
| S3 returns 500 | per-part retry/backoff; eventual success or clean fail |
| Cloudflare 522 on WP step | step re-driven; no whole-backup failure |
| Callback lost | manager re-polls; state consistent |
| Duplicate callback | idempotent no-op |
| Duplicate job dispatch | `ShouldBeUnique` + site lock prevent double-run |
| Expired presigned URL | re-issued just-in-time; no data exposure window |
| Disk full | pre-flight guard fails closed; partial artifacts cleaned |
| Corrupt chunk | detected by checksum; backup `corrupt`, not `completed` |

## 5. Parity acceptance (maps to feature matrix)

Each feature-matrix row's "Acceptance test" column is a required check; a feature is not "done"
until its acceptance test is green in CI (or, for E2E, on a pilot site).

## 6. Characterization baseline (this phase)

Read-only tests that pin **current** behavior before any rewrite (present in `tests/` from this
commit): `BackupStatus` transitions/labels, chain resolution, retention chain-guard (dry-run),
`SqlDumpParser` validation, `SafeZipExtractor` zip-slip, sidecar round-trip. These must keep
passing through Phases 1–2 (they document the contract we are preserving), then are superseded
by the new-engine suites.

## Gate summary

- **Phase gate = its integration + failure-injection rows green.**
- **Retirement gate = full parity matrix green + proven restore demonstrated + owner approval.**
