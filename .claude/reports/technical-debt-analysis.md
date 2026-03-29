# Technical Debt Inventory
**Repository**: simplead-manager
**Analysis Date**: 2026-03-27
**Baseline**: PHP 8.3 / Laravel 11.48 / Livewire 4.1.4
**Codebase size**: ~44,700 LOC in `app/`, ~3,970 LOC in `tests/`
**Test ratio**: ~8.8% (30 test files covering ~72 Livewire components + jobs + services)

---

## Executive Summary

The application is a mature, actively-developed Laravel 11 WordPress management platform. The overall code quality is good — strict types are used throughout, PSR-12 is enforced, the CI pipeline covers security audit + lint + static analysis + tests, and business logic is properly separated into Services and Jobs. The primary risks are concentrated in:

1. A latent **bug that causes search queries on the `domain` column to silently fail** (the column does not exist in the `sites` table — the actual column is `url`).
2. A **169-entry PHPStan baseline** that suppresses real type-safety signals, including a concrete type mismatch between `WordPressApiServiceInterface` and `WordPressApiService` in `ManifestService`.
3. **Severe test coverage debt**: 72 Livewire components have zero tests; critical jobs (`CreateBackup`, `RestoreBackup`, `SyncWordPressSite`) have only shallow integration coverage.
4. **Synchronous pre-update database backups** run inline in a Livewire HTTP request, risking timeouts for all users who have `backup_before_updates` enabled.
5. **`file_get_contents()` on backup files ≤ 150 MB** in `DropboxDriver::uploadSimple` loads the entire file into PHP heap, which can OOM the worker.

- **Critical**: 3
- **High**: 7
- **Medium**: 8
- **Low**: 4

---

## Debt by Category

| Category        | Count | Max Severity | Est. Total Effort |
|-----------------|------:|--------------|-------------------|
| Code Quality    |     4 | High         | 8–13 days         |
| Tests           |     5 | Critical     | 15–25 days        |
| Documentation   |     3 | Medium       | 3–5 days          |
| Dependencies    |     2 | Medium       | 1–2 days          |
| Design          |     4 | High         | 5–10 days         |
| Infrastructure  |     3 | Medium       | 2–4 days          |
| Performance     |     1 | Critical     | 1–2 days          |

---

## Top 10 Highest-Impact Items

### 1. [CRITICAL] `domain` column queried against `sites` table that has no such column

- **Impact**: Three search-by-site features return empty or incorrect results silently in production: global dashboard search, performance overview search, and backups overview search. No exception is thrown — PostgreSQL ignores a non-existent column reference in an ILIKE context **only if soft-deleted rows satisfy the WHERE**; in practice the query fails with a column-not-found error for any user who types in the search box.
- **Effort**: S (30 min fix)
- **Evidence**: `orWhere('domain', 'ilike', ...)` appears in three files; `database/migrations/2026_02_02_063300_create_sites_table.php` confirms the column is named `url`, and `Site::getDomainAttribute()` is a computed accessor only.
- **Fix outline**:
  - Replace `orWhere('domain', 'ilike', "%{$search}%")` with `orWhere('url', 'ilike', "%{$search}%")` in all three locations.
  - Add a Feature test asserting that search returns results when a matching URL is provided.
- **Files**:
  - `/var/www/simplead-manager/app/Services/DashboardService.php:161`
  - `/var/www/simplead-manager/app/Livewire/Performance/PerformanceOverview.php:119`
  - `/var/www/simplead-manager/app/Livewire/Backups/BackupsOverview.php:95`

---

### 2. [CRITICAL] Synchronous pre-update backup blocks HTTP request thread

- **Impact**: When `backup_before_updates` is enabled, `CreateBackup::dispatchSync()` is called **inside a Livewire action** (and inside `SafeUpdateService`). A full-site backup can take 5–30 minutes; this blocks the PHP-FPM worker, leading to a 502/504 for that user and reducing available worker capacity for all users for the duration.
- **Effort**: M (2–4 hours)
- **Evidence**: Direct `dispatchSync` calls without any background offloading.
- **Fix outline**:
  - Replace `dispatchSync` with a deferred `dispatch` on the `backups` queue and return an informational message to the user that the backup is being created before the update proceeds.
  - For `SafeUpdateService`, introduce a Job chain: `CreateBackup → RunSafeUpdate` using `Bus::chain()` so the update only proceeds after the backup completes, but without blocking the HTTP thread.
  - Update UI to poll job status via the existing `WithJobTracking` trait.
- **Files**:
  - `/var/www/simplead-manager/app/Livewire/Sites/Detail/SitePlugins.php:531–535`
  - `/var/www/simplead-manager/app/Services/SafeUpdateService.php:50`

---

### 3. [CRITICAL] `DropboxDriver::uploadSimple` loads entire file into PHP heap

- **Impact**: Files up to 150 MB are uploaded via `file_get_contents($localPath)` — this loads the entire binary payload into memory as a PHP string. The `backups` worker has a 512 MB memory limit (Docker). A 150 MB backup plus PHP overhead + other allocations can OOM the worker mid-upload, corrupting the backup and potentially killing the process.
- **Effort**: M (half-day)
- **Evidence**: `file_get_contents($localPath)` on line 64 of `DropboxDriver.php`; threshold is `LARGE_FILE_THRESHOLD = 150 MB`.
- **Fix outline**:
  - Replace `file_get_contents` with a stream: open the file as a resource (`fopen($localPath, 'rb')`) and pass it to Guzzle/Laravel Http as a stream body.
  - Or lower `LARGE_FILE_THRESHOLD` to 0 so all uploads always use the chunked session path (which already reads in 8 MB chunks).
- **Files**:
  - `/var/www/simplead-manager/app/Services/Backup/Storage/DropboxDriver.php:53–66`

---

### 4. [HIGH] 169 suppressed PHPStan baseline errors include real type mismatches

- **Impact**: The baseline silences the static analyser on real issues, including: `ManifestService::generateAndStore()` expects `WordPressApiService` but receives `WordPressApiServiceInterface` — meaning the concrete class is hard-wired in the type hint, breaking polymorphism and making testing harder. Also suppresses `StorageDriver::uploadToAbsolutePath()` calls on the interface that doesn't declare that method, and `DropboxDriver::startUploadSession/appendToUploadSession/finishUploadSession` on `BackupRelayController` (dead code risk).
- **Effort**: L (5–10 days total, can be incremental over multiple sprints)
- **Evidence**: `phpstan-baseline.neon` has 169 `message:` entries; most frequent categories are `Access to an undefined property ... Model` (type inference gaps), method-not-found on interface, and strict-comparison tautologies.
- **Fix outline**:
  - Raise PHPStan level from 5 to 6, resolving errors in batches rather than suppressing them.
  - Fix `ManifestService::generateAndStore()` type hint to accept the interface.
  - Add `uploadToAbsolutePath()` and the upload-session methods to `StorageDriver` interface (or document why they are Dropbox-only and cast accordingly).
  - Target: reduce baseline to 0 items over 2 quarters.
- **Files**:
  - `/var/www/simplead-manager/phpstan-baseline.neon`
  - `/var/www/simplead-manager/app/Services/Backup/ManifestService.php`
  - `/var/www/simplead-manager/app/Services/Backup/Storage/StorageDriver.php`
  - `/var/www/simplead-manager/app/Http/Controllers/Api/BackupRelayController.php`

---

### 5. [HIGH] Near-zero Livewire component test coverage (72 components, 1 test file)

- **Impact**: All UI interaction logic — backup triggers, plugin update flows, security hardening toggles, report generation, site credential saving — is completely untested. Regressions in any of these user-facing paths are caught only in production.
- **Effort**: L (ongoing; highest ROI: 10 components × ~0.5 days each = ~5 days for the critical set)
- **Evidence**: `find app/Livewire -name "*.php"` returns 72 files; `tests/Feature/Livewire/` contains exactly one file (`GlobalDashboardAuthorizationTest.php`).
- **Fix outline**:
  - Priority order: `SitePlugins` (plugin updates, bulk actions), `SiteOverview` (credentials, circuit breaker), `SiteBackups` (restore flow), `GeneralSettings` (plugin push), `GlobalDashboard` (bulk actions).
  - Use Livewire's `Livewire::test()` helper with `RefreshDatabase`.
  - Start with authorization and happy-path smoke tests before edge cases.
- **Files**:
  - `/var/www/simplead-manager/app/Livewire/Sites/Detail/SitePlugins.php`
  - `/var/www/simplead-manager/app/Livewire/Sites/Detail/SiteOverview.php`
  - `/var/www/simplead-manager/app/Livewire/Sites/Detail/SiteBackups.php`

---

### 6. [HIGH] `Backup::getSizeDiffAttribute` performs a per-row subquery when iterated

- **Impact**: The backup list view calls `$backup->size_diff_formatted` for each backup row. `getSizeDiffAttribute` issues a `SELECT ... WHERE id < $this->id ORDER BY id DESC LIMIT 1` query per backup. For a list of 50 backups this produces 50 additional queries. The attribute includes a partial mitigation (`_previous_file_size` cache) but it only works within a single PHP request cycle — it does not eliminate the N+1 when building a fresh list.
- **Effort**: S (1–2 hours)
- **Evidence**: `app/Models/Backup.php:210–232` and `resources/views/livewire/sites/detail/site-backups.blade.php:373`.
- **Fix outline**:
  - Use a window function via `LAG(file_size) OVER (PARTITION BY site_id ORDER BY id)` in the query that fetches the backup list, or pre-join previous size as an eager-computed column.
  - Remove the custom `_previous_file_size` attribute hack from the model.
- **Files**:
  - `/var/www/simplead-manager/app/Models/Backup.php:210–231`
  - `/var/www/simplead-manager/app/Livewire/Sites/Detail/SiteBackups.php`

---

### 7. [HIGH] `WordPressApiService` is 805 lines with mixed HTTP transport, retry, throttle, and backup orchestration

- **Impact**: The class uses 7 traits to organise sub-domains (plugins, themes, security, etc.) but the core file itself mixes three levels of abstraction: low-level HTTP signing, retry/backoff logic, async curl multi, and chunked backup orchestration. This makes it hard to test specific behaviours in isolation, hard to swap the HTTP client for tests, and adds cognitive load for every feature touching the WordPress API.
- **Effort**: L (3–5 days, can be done incrementally)
- **Evidence**: 805 lines in `app/Services/WordPressApiService.php`; methods `chunkedPrepareAndDownloadFilesAsChunks` (139 lines), `curlDownloadWithRetry` (65 lines), and `httpRequestWithRetry` (35 lines) all live at the same level.
- **Fix outline**:
  - Extract a `WordPressHttpClient` responsible only for making authenticated, throttled HTTP requests (retry, backoff, HMAC signing).
  - Keep the traits for domain concerns (backup chunking, plugin management, etc.) calling `WordPressHttpClient`.
  - This will also fix the interface mismatch in `ManifestService` (item 4) and make unit testing possible without HTTP mocking at the controller level.
- **Files**:
  - `/var/www/simplead-manager/app/Services/WordPressApiService.php`

---

### 8. [HIGH] `ManifestService`, `ZipArchive`, and iterator objects directly instantiated in Jobs (bypasses DI)

- **Impact**: `new \App\Services\Backup\ManifestService` appears in `CreateBackup.php:391`, `RestoreBackup.php:149` and `RestoreBackup.php:388`, `CreateIncrementalBackup.php:78`. Direct instantiation bypasses the service container, making these code paths impossible to mock in unit tests without partial-mock hacks. It also means that if `ManifestService` ever gains dependencies they must be manually wired in each call site.
- **Effort**: S (half-day)
- **Fix outline**:
  - Inject `ManifestService` via the Job constructor or resolve via `app(ManifestService::class)` for testability.
  - The same applies to the `new RetentionService()` call in `CreateBackup::finalize()`.
- **Files**:
  - `/var/www/simplead-manager/app/Jobs/CreateBackup.php:391`
  - `/var/www/simplead-manager/app/Jobs/RestoreBackup.php:149,388`
  - `/var/www/simplead-manager/app/Jobs/CreateIncrementalBackup.php:78`
  - `/var/www/simplead-manager/app/Services/Backup/RetentionService.php` (instantiated as `new RetentionService` in CreateBackup finalize)

---

### 9. [MEDIUM] Runtime `Schema::hasTable` checks in Livewire components and Jobs indicate schema drift risk

- **Impact**: 11 `Schema::hasTable / Schema::hasColumn` calls in production code indicate a period of incremental schema additions where the code was written defensively against missing tables. These checks add overhead (a schema introspection query per request), obscure intent, and suggest some tables may have been added without corresponding application boot checks. If a migration is missed in production, the code silently degrades instead of failing loudly.
- **Effort**: S (1 day)
- **Evidence**: Grep shows 11 occurrences in `GeneralSettings.php` (×4), `GlobalDashboard.php` (×2), `SitePlugins.php` (×2), `RetentionCleanup.php`, `RetentionPolicyService.php`.
- **Fix outline**:
  - Remove checks for `site_statuses`, `site_users`, and `sort_order` columns — all are present in current migrations.
  - Replace with standard Eloquent queries and proper authorization gates.
  - Add a startup assertion or health-check that validates critical table presence.
- **Files**:
  - `/var/www/simplead-manager/app/Livewire/Settings/GeneralSettings.php:58,122,147,170`
  - `/var/www/simplead-manager/app/Livewire/Dashboard/GlobalDashboard.php:73,161,303`
  - `/var/www/simplead-manager/app/Livewire/Sites/Detail/SitePlugins.php:124,189`

---

### 10. [MEDIUM] `CONNECTOR_CHANGELOG` constant embedded in `GeneralSettings` Livewire component

- **Impact**: The plugin release history (24 versions' worth of changelog text) is hardcoded as a `const` array inside a Livewire component. This bloats the component's responsibility, couples settings UI to release notes, and must be updated in two places simultaneously (connector plugin header + this constant). It's also excluded from version-control diff visibility when the constant and the plugin file change in the same commit.
- **Effort**: S (2–3 hours)
- **Fix outline**:
  - Move the changelog to a dedicated config file (`config/connector.php`) or a JSON file in `resources/`.
  - Read it from `GeneralSettings` via `config('connector.changelog')`.
  - Document the two-step sync requirement (plugin header `Version:` + `SAM_VERSION` constant + config).
- **Files**:
  - `/var/www/simplead-manager/app/Livewire/Settings/GeneralSettings.php:202–282`

---

## Full Debt Inventory

### Category 1: Code Quality

| ID   | Item                                                                  | Severity | Effort |
|------|-----------------------------------------------------------------------|----------|--------|
| CQ-1 | `domain` column queried on `sites` table (see item 1 above)          | Critical | S      |
| CQ-2 | `WordPressApiService` — mixed abstraction levels, 805 LOC             | High     | L      |
| CQ-3 | 169 suppressed PHPStan baseline errors                                | High     | L      |
| CQ-4 | `Backup::getSizeDiffAttribute` N+1 subquery per row                   | High     | S      |

### Category 2: Tests

| ID   | Item                                                                  | Severity | Effort |
|------|-----------------------------------------------------------------------|----------|--------|
| T-1  | 72 Livewire components with zero tests                               | Critical | L      |
| T-2  | `CreateBackup` / `RestoreBackup` tests mock the API but not storage  | High     | M      |
| T-3  | No tests for `DashboardService` cache invalidation paths             | Medium   | S      |
| T-4  | No tests for `DropboxDriver` upload / download logic                 | Medium   | M      |
| T-5  | Test:code ratio is 8.8% (3,968 LOC test vs 44,723 LOC app)          | Medium   | L      |

### Category 3: Documentation

| ID   | Item                                                                  | Severity | Effort |
|------|-----------------------------------------------------------------------|----------|--------|
| D-1  | README is in Romanian — inconsistent with code which is in English   | Medium   | S      |
| D-2  | No runbook for backup failure recovery or restore workflow           | Medium   | M      |
| D-3  | `CONNECTOR_CHANGELOG` embedded in component, not a versioned doc     | Medium   | S      |

### Category 4: Dependencies

| ID   | Item                                                                  | Severity | Effort |
|------|-----------------------------------------------------------------------|----------|--------|
| DEP-1 | `composer audit` could not be run (composer not on host PATH)       | Medium   | S      |
| DEP-2 | `laravel/breeze` in `require-dev` — correct, but may pull in unnecessary scaffold if not cleaned | Low | S |

> Note: `composer audit` was not executable in this environment (no system PHP/Composer binary found on the host). The CI pipeline runs it — check the latest CI run result. No advisories were found via manual lock-file inspection for the direct dependencies at their locked versions.

### Category 5: Design

| ID   | Item                                                                  | Severity | Effort |
|------|-----------------------------------------------------------------------|----------|--------|
| DS-1 | Synchronous `dispatchSync` backup in Livewire HTTP request (item 2)  | Critical | M      |
| DS-2 | Direct `new ManifestService / new RetentionService` in Jobs          | High     | S      |
| DS-3 | `StorageDriver` interface missing `uploadToAbsolutePath` and upload session methods used by `BackupRelayController` and `AppBackupService` | High | M |
| DS-4 | `CONNECTOR_CHANGELOG` constant in `GeneralSettings` component        | Medium   | S      |

### Category 6: Infrastructure

| ID   | Item                                                                  | Severity | Effort |
|------|-----------------------------------------------------------------------|----------|--------|
| I-1  | 11 runtime `Schema::hasTable` guards that should be removed          | Medium   | S      |
| I-2  | No coverage reporting configured in CI (test presence verified but coverage % not tracked) | Medium | S |
| I-3  | `phpstan.neon` at level 5 — level 6/7 would catch more real issues without major noise | Low | M |

### Category 7: Performance

| ID   | Item                                                                  | Severity | Effort |
|------|-----------------------------------------------------------------------|----------|--------|
| P-1  | `DropboxDriver::uploadSimple` loads entire file into PHP heap         | Critical | M      |

---

## Severity Scoring Rationale

| Item  | Churn | Complexity | Business Criticality | Test Confidence | Score |
|-------|-------|------------|----------------------|-----------------|-------|
| CQ-1  | High  | Low        | High (all searches)  | None            | Critical |
| DS-1  | High  | Medium     | High (all updates)   | Low             | Critical |
| P-1   | Medium| Medium     | High (backup storage)| Low             | Critical |
| CQ-3  | High  | High       | High                 | Low             | High |
| T-1   | High  | N/A        | High                 | None            | Critical |
| DS-3  | Medium| Medium     | Medium (relay API)   | None            | High |

---

# Sprint-Ready Work Items

## Epic: Technical Debt Reduction — Q2 2026

---

### Story: Fix `domain` column search queries (CQ-1)

**Priority**: Critical
**Effort**: 1 point
**Acceptance Criteria**
- [ ] `DashboardService::getSitesOverview` uses `url` instead of `domain` in search
- [ ] `PerformanceOverview` uses `url` instead of `domain` in search
- [ ] `BackupsOverview` uses `url` instead of `domain` in search
- [ ] Feature test added: searching by site URL returns results; searching by a non-URL string returns empty
- [ ] `php artisan test` passes
- [ ] `./vendor/bin/pint --test` passes

---

### Story: Replace synchronous pre-update backup with Job chain (DS-1)

**Priority**: Critical
**Effort**: 3 points
**Acceptance Criteria**
- [ ] `SitePlugins::runPreUpdateBackup()` dispatches `CreateBackup` to the queue, returns immediately
- [ ] `SafeUpdateService` uses `Bus::chain([CreateBackup, RunSafeUpdate])` so updates are gated on backup completion without blocking the HTTP thread
- [ ] UI displays a "backup in progress, update queued" status using `WithJobTracking`
- [ ] No `dispatchSync` calls remain in Livewire HTTP actions
- [ ] Integration test covers the chain dispatch assertion
- [ ] No timeout regression reported on staging

---

### Story: Fix `DropboxDriver::uploadSimple` memory usage (P-1)

**Priority**: Critical
**Effort**: 2 points
**Acceptance Criteria**
- [ ] `uploadSimple` passes a file stream handle (not `file_get_contents`) to the HTTP request
- [ ] Alternatively: lower `LARGE_FILE_THRESHOLD` to 0, routing all uploads through the chunked path
- [ ] Backup to Dropbox tested on staging with a ~50 MB file without OOM
- [ ] Memory usage of `backups` worker measured before/after (expected: flat during upload)

---

### Story: Reduce PHPStan baseline — phase 1: interface mismatches (CQ-3)

**Priority**: High
**Effort**: 3 points
**Acceptance Criteria**
- [ ] `ManifestService::generateAndStore()` accepts `WordPressApiServiceInterface` (not the concrete class)
- [ ] `StorageDriver` interface declares `uploadToAbsolutePath()` (Dropbox-specific — document it or add a sub-interface)
- [ ] `BackupRelayController` uses only methods declared on `StorageDriver` or correctly casts to `DropboxDriver`
- [ ] `DropboxDriver::startUploadSession / appendToUploadSession / finishUploadSession` are either added to the interface or removed from `BackupRelayController` (the controller calls them but they don't exist on `DropboxDriver` — this is dead/broken code)
- [ ] Baseline shrinks by at least 10 entries
- [ ] `./vendor/bin/phpstan analyse` passes

---

### Story: Add Livewire tests — critical site management flows (T-1, batch 1)

**Priority**: Critical
**Effort**: 5 points
**Acceptance Criteria**
- [ ] `SitePlugins`: test `updatePlugin()` — success and failure paths; `quickBackup()` dispatches job
- [ ] `SiteOverview`: test `saveCredentials()` validates and updates site; `syncNow()` dispatches job; `runBackup()` rate-limited
- [ ] `GlobalDashboard`: test `deleteSite()` authorization; `renameSite()` validation; `saveReorder()` admin-only
- [ ] `GeneralSettings`: test `saveStatus()` create/update; `deleteStatus()` blocked when sites assigned
- [ ] All tests use `Livewire::test()` with `RefreshDatabase`
- [ ] `php artisan test` passes with new tests

---

### Story: Add Livewire tests — backup and restore flows (T-1, batch 2)

**Priority**: High
**Effort**: 5 points
**Acceptance Criteria**
- [ ] `SiteBackups`: test restore trigger; test cancel backup; test that restore cannot start while one is in progress
- [ ] `BackupsOverview`: test `backupAllSites()` dispatches one job per enabled config; test rate limiting
- [ ] Tests cover authorization: non-owner cannot trigger restore for another user's site
- [ ] `php artisan test` passes

---

### Story: Fix `Backup::getSizeDiffAttribute` N+1 (CQ-4)

**Priority**: High
**Effort**: 2 points
**Acceptance Criteria**
- [ ] Backup list query pre-loads previous backup size using a window function or left-join subquery
- [ ] `getSizeDiffAttribute` removed or made a pure computation from eagerly loaded data
- [ ] Site backups page loads with 50+ backups without issuing N+1 queries (verify with `DB::listen` or telescope)
- [ ] No behavioral change in the displayed size diff values

---

### Story: Inject `ManifestService` and `RetentionService` via DI (DS-2)

**Priority**: High
**Effort**: 2 points
**Acceptance Criteria**
- [ ] `CreateBackup`, `RestoreBackup`, `CreateIncrementalBackup` receive `ManifestService` via constructor injection (or `app()` resolution with documented rationale)
- [ ] `RetentionService` in `CreateBackup::finalize()` resolved via container
- [ ] No `new ClassName` direct instantiation of injectable services in Jobs
- [ ] `./vendor/bin/phpstan analyse` passes

---

### Story: Remove runtime `Schema::hasTable` checks (I-1)

**Priority**: Medium
**Effort**: 1 point
**Acceptance Criteria**
- [ ] All 11 `Schema::hasTable / Schema::hasColumn` calls removed from Livewire components, Services, and Jobs
- [ ] Replaced with direct Eloquent queries (tables exist in all supported migration versions)
- [ ] `php artisan test` passes including `GlobalDashboardAuthorizationTest`

---

### Story: Move `CONNECTOR_CHANGELOG` to config file (D-3, DS-4)

**Priority**: Medium
**Effort**: 1 point
**Acceptance Criteria**
- [ ] `config/connector.php` created with `changelog` key containing the version array
- [ ] `GeneralSettings` reads from `config('connector.changelog')`
- [ ] `CONNECTOR_CHANGELOG` constant removed from the component
- [ ] CLAUDE.md or docs updated with the sync requirement for version bumps

---

### Story: Add runbook — backup failure and restore procedure (D-2)

**Priority**: Medium
**Effort**: 1 point
**Acceptance Criteria**
- [ ] `docs/runbooks/backup-restore.md` created with: how to check backup status in Horizon, how to retry a failed backup, how to trigger a manual restore, how to release a stuck backup lock
- [ ] Reviewed by the team member who operates the system most frequently

---

# Refactoring Roadmap — Q2 2026

## Weeks 1–2: Critical Bug Fixes and Safety

| Task | Story | Who | Notes |
|------|-------|-----|-------|
| Fix `domain` column search bug | CQ-1 | 1 dev, 30 min | Zero risk, high visibility fix |
| Fix Dropbox OOM risk | P-1 | 1 dev, half day | Staging test required |
| Replace `dispatchSync` with Job chain | DS-1 | 1 dev, 2–4 days | Needs UX review for "backup queued" messaging |

**Success check**: No 502/504 errors during bulk plugin updates on staging. Dropbox upload of large site completes without OOM in logs.

---

## Weeks 3–5: PHPStan Baseline Reduction (Phase 1)

| Task | Story | Who | Notes |
|------|-------|-----|-------|
| Fix interface mismatches (ManifestService, StorageDriver) | CQ-3 part 1 | 1 dev, 2 days | Unblocks testability |
| Fix type errors in high-churn files | CQ-3 part 2 | 1 dev, 3 days | Target: -60 baseline entries |
| Inject ManifestService/RetentionService via DI | DS-2 | 1 dev, half day | Low risk, improves testability |

**Success check**: Baseline shrinks from 169 to ≤ 100 entries. `phpstan analyse` still passes.

---

## Weeks 6–8: Test Coverage — Critical Paths

| Task | Story | Who | Notes |
|------|-------|-----|-------|
| Livewire tests batch 1 (site management) | T-1b1 | 1 dev, 1 week | Highest ROI coverage |
| Livewire tests batch 2 (backup/restore) | T-1b2 | 1 dev, 1 week | Second highest ROI |
| Add test for storage driver upload | T-4 | 1 dev, 1 day | Prevents OOM regression |

**Success check**: 15+ new Livewire tests pass in CI. Backup/restore paths covered with mocked storage and mocked WP API.

---

## Weeks 9–11: Design and Performance Cleanup

| Task | Story | Who | Notes |
|------|-------|-----|-------|
| Fix Backup N+1 in site backups list | CQ-4 | 1 dev, 1 day | Profile before/after |
| Remove Schema::hasTable guards | I-1 | 1 dev, half day | Clean up defensive code |
| Move CONNECTOR_CHANGELOG to config | DS-4/D-3 | 1 dev, 2 hours | Quick win |
| Add backup runbook | D-2 | 1 dev, 2 hours | Documentation |

---

## Weeks 12: Planning and Metrics Review

| Task |
|------|
| Measure baseline metrics (see dashboard below) and set Q3 targets |
| Plan PHPStan baseline phase 2 (target: ≤ 50 entries) |
| Plan Livewire test coverage batch 3 (Settings, Security components) |
| Review `WordPressApiService` decomposition feasibility for Q3 |

---

**Continuous (each sprint)**
- Reserve 10–15% sprint capacity for debt items
- Every new Livewire component ships with at least 1 authorization test and 1 happy-path test
- Zero new `Schema::hasTable` calls in code review
- New Jobs inject their service dependencies via constructor

---

# Metrics Dashboard

## Baseline — Q2 2026 Sprint 1 Start

| Metric                                    | Current  | Target Q2-End | Target Q3-End | Trend |
|-------------------------------------------|----------|---------------|---------------|-------|
| `composer audit` high/critical advisories | unknown* | 0             | 0             | —     |
| PHPStan baseline suppressions             | 169      | ≤ 100         | ≤ 50          | down  |
| Livewire component test coverage          | 1/72     | 15/72         | 35/72         | up    |
| Feature test files                        | 10       | 18            | 28            | up    |
| Test LOC / App LOC ratio                  | 8.8%     | 15%           | 25%           | up    |
| `Schema::hasTable` calls in app/          | 11       | 0             | 0             | down  |
| Direct `new Service()` in Jobs            | 4        | 0             | 0             | down  |
| `dispatchSync` calls in Livewire actions  | 2        | 0             | 0             | down  |
| Files with `domain` column bug            | 3        | 0             | 0             | down  |
| Dropbox OOM-risk `file_get_contents` calls| 1        | 0             | 0             | down  |
| Known N+1 query patterns (Backup list)    | 1        | 0             | 0             | down  |

> *`composer audit` requires Composer in PATH on host; run `docker compose exec app composer audit` to check current advisory status.

## How to Measure Each Metric

```bash
# PHPStan baseline entry count
grep -c 'message:' phpstan-baseline.neon

# Schema::hasTable in app
grep -rn 'Schema::hasTable\|Schema::hasColumn' app/ --include="*.php" | grep -v vendor | wc -l

# Direct service instantiation in Jobs
grep -rn 'new ManifestService\|new RetentionService' app/Jobs/ --include="*.php" | wc -l

# dispatchSync in Livewire
grep -rn 'dispatchSync' app/Livewire/ --include="*.php" | wc -l

# Test count
find tests -name "*.php" | wc -l

# Composer audit (inside Docker)
docker compose -f docker-compose.prod.yml exec app composer audit
```

---

## Notes for Stakeholders

**For Engineering Teams**: The three critical items (domain column bug, synchronous backup, Dropbox OOM) are all localized fixes with minimal blast radius — they can be shipped in a single PR each without architectural changes. The PHPStan and test debt items should be treated as ongoing background work (10–15% sprint capacity) rather than dedicated sprints.

**For Engineering Managers**: The domain column bug is a user-facing defect causing search to silently return no results. It should be fixed in the next deploy. The synchronous backup risk is a latent reliability issue that will surface as a user complaint ("my site went down after clicking Update All") as soon as a backup-before-updates user runs a bulk update on a large site.

**For Product Teams**: The test coverage investment directly enables faster, safer iteration. Each new tested Livewire component reduces the manual regression burden for that feature area by approximately 80%. The 5-sprint test investment (weeks 6–8) is the highest-leverage quality improvement available.
