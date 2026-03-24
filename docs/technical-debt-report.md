# Technical Debt Inventory — SimpleAd Manager
**Repository**: simplead-manager
**Analysis Date**: 2026-03-23
**Baseline**: PHP 8.2 (production); PHP 8.3 (CI/container)
**Framework**: Laravel 11.48.0 / Livewire 4.1.4

---

## Executive Summary

The codebase is a well-structured Laravel 11 application with a clear services pattern, proper queue usage, and functioning CI. However, several compounding debt items have accumulated over its growth phase that pose moderate risk to long-term velocity.

The most significant issues are:

1. **Zero `declare(strict_types=1)` coverage** across all 342 PHP files — a project-wide type safety gap.
2. **441 suppressed PHPStan errors** in the baseline file, masking real static analysis signal in the most-changed code.
3. **Critical test gaps** in the highest-churn, highest-risk modules: `CreateBackup`, `RestoreBackup`, `WordPressApiService`, and `SyncWordPressSite` have zero test coverage.
4. **`WordPressApiService` is manually instantiated 54 times** across jobs and services, blocking testability and making the throttle/retry state unreachable in tests.
5. **`WordPressApiService` contains 900 lines with two near-identical copy-pasted HTTP signing loops** (`request`, `requestRaw`, `streamDownload`, `streamDownloadTo`).
6. **`ReportDataGatherer` is 1 176 lines** with 20+ methods collecting heterogeneous data sections — a classic God Class.
7. **PHP version mismatch**: `composer.json` requires `^8.2`, production runs 8.2, but CI tests against PHP 8.3. The constraint should be tightened and the gap documented.
8. **`dispatch_backup.php` is committed to the repository** — a debug script with hardcoded site ID 12 and backup ID 61 that could be run accidentally in production.
9. **`composer audit` gate is absent from CI**, leaving security advisories undetected until manual intervention.
10. **Two `env()` calls outside config files** in `AppBackupService` and `DatabaseDumpCommand`.

| Severity | Count |
|---|---:|
| Critical | 3 |
| High | 5 |
| Medium | 8 |
| Low | 4 |

---

## Debt by Category

| Category | Items | Severity | Est. Total Effort |
|---|---:|---|---|
| Code Quality | 4 | High–Medium | L |
| Tests | 4 | Critical–High | XL |
| Documentation | 3 | Medium–Low | S |
| Dependencies | 2 | Medium–Low | S |
| Design / Architecture | 4 | High–Medium | L |
| Infrastructure | 3 | Critical–Medium | M |
| Performance | 2 | Medium | S |

---

## Top 10 Highest-Impact Items

### 1. [Critical] Zero `declare(strict_types=1)` across 342 PHP files

- **Category**: Code Quality / Design
- **Severity**: Critical (project-wide, affects every PHP class)
- **Effort**: M (automated fix + review pass)
- **Business Impact**: Without strict types, PHP silently coerces values. For a codebase managing backup archives and financial-adjacent data (client billing, report generation), a silently coerced integer can produce wrong byte counts, wrong retention math, or corrupt archive metadata with no error thrown.
- **Evidence**: `grep -rn "declare(strict_types=1)" app/ | wc -l` returns `0`. All 342 `.php` files in `app/` are missing the declaration.
- **Fix outline**:
  1. Run `sed -i '1s/^<?php$/<?php\n\ndeclare(strict_types=1);/' app/**/*.php` recursively (or use a Rector rule).
  2. Run `./vendor/bin/pint` to fix any ordering issues.
  3. Run PHPStan — strict types will surface additional type errors that need fixing.
  4. Add a Pint rule or Rector check to enforce the declaration in CI going forward.
- **Files**: All files under `/var/www/simplead-manager/app/` — starting with highest-churn: `app/Models/Site.php`, `app/Services/WordPressApiService.php`, `app/Jobs/CreateBackup.php`, `app/Jobs/RestoreBackup.php`.

---

### 2. [Critical] No tests for backup/restore and WordPress API — the most business-critical code paths

- **Category**: Tests
- **Severity**: Critical
- **Effort**: XL
- **Business Impact**: The backup and restore flow is the core value proposition of the product. Any regression silently corrupts customer data or causes data loss. There are zero tests for `CreateBackup`, `RestoreBackup`, `CheckUptime`, `SyncWordPressSite`, and `WordPressApiService`. These files collectively have the highest git churn (21–24 commits in 90 days).
- **Evidence**:
  - `find tests/ -name "*.php" | xargs grep -l "CreateBackup|RestoreBackup|WordPressApi|CheckUptime|SyncWordPress"` returns empty.
  - `app/Jobs/CreateBackup.php` (418 lines, 21 commits), `app/Jobs/RestoreBackup.php` (698 lines, 15 commits), `app/Services/WordPressApiService.php` (900 lines, 22 commits) — zero test coverage.
  - Test suite has 26 test files, none of which cover backup, restore, uptime, or sync flows.
- **Fix outline**:
  1. Mock `WordPressApiService` via an interface (see item 5) and write unit tests for `CreateBackup::prepare()`, `downloadData()`, `createArchive()`, `finalize()`.
  2. Write unit tests for `RestoreBackup::restoreSingleBackup()`, `mergeChunkZipsForRestore()`, `doRestore()` using temp directories and mock API.
  3. Write feature tests for `CheckUptime` verifying state transitions (up → down → recovered) and incident creation.
  4. Write a feature test for `SyncWordPressSite` verifying site attribute updates from API response.
  5. Target: happy path + at least one failure path per critical method.
- **Files**: `/var/www/simplead-manager/app/Jobs/CreateBackup.php`, `RestoreBackup.php`, `CheckUptime.php`, `SyncWordPressSite.php`, `app/Services/WordPressApiService.php`.

---

### 3. [Critical] `dispatch_backup.php` committed to repository with hardcoded production IDs

- **Category**: Infrastructure / Documentation
- **Severity**: Critical
- **Effort**: S
- **Business Impact**: A debug script committed at the repository root with hardcoded `Site::find(12)` and `backupId: 61` can be accidentally executed against production data (`php dispatch_backup.php`). It bootstraps the full Laravel app, so it will dispatch a real backup job if run in a production shell session.
- **Evidence**: `git ls-files dispatch_backup.php` confirms it is tracked. File contains:
  ```php
  $site = App\Models\Site::find(12);
  App\Jobs\CreateBackup::dispatch($site, 'full', 'manual', null, 61);
  ```
- **Fix outline**:
  1. Remove `dispatch_backup.php` from the repository (`git rm dispatch_backup.php`).
  2. Add `dispatch_*.php` to `.gitignore` to prevent future debug scripts from being committed.
  3. If this pattern recurs, use `php artisan tinker` (available locally) or a proper artisan command instead.
- **Files**: `/var/www/simplead-manager/dispatch_backup.php`.

---

### 4. [High] 441 PHPStan errors suppressed in baseline — signal is completely masked

- **Category**: Code Quality
- **Severity**: High
- **Effort**: L
- **Business Impact**: The baseline file silences all static analysis signal. The most common suppressed errors are "access to undefined property on Eloquent Model" (80+ instances) — which are real errors that will produce fatal `null` property accesses at runtime. New errors are hidden as they are added because they blend into the existing baseline. This defeats the purpose of static analysis as a CI gate.
- **Evidence**: `phpstan-baseline.neon` is 2 647 lines suppressing 441 errors. Top patterns:
  - `Access to an undefined property Illuminate\Database\Eloquent\Model::$name` (14 occurrences)
  - `Access to an undefined property Illuminate\Database\Eloquent\Model::$id` (13 occurrences)
  - `Parameter #1 $destination of StorageFactory::make() expects StorageDestination, Model|null given` (6 occurrences)
  - `Parameter $site of WordPressApiService constructor expects Site, Model|null given` (3 occurrences)
- **Fix outline**:
  1. Sort baseline by file, tackle the highest-churn files first.
  2. For "undefined property on Model" errors: add `@property` PHPDoc annotations or use typed `firstOrFail()`/`findOrFail()` instead of `first()`/`find()`.
  3. For `Model|null` passed where `Site` is required: replace `find()` with `findOrFail()` or add null checks.
  4. Set a target: reduce baseline by 25% per quarter.
  5. Add a CI step that fails if the baseline line count increases: `wc -l phpstan-baseline.neon | awk '{if ($1 > THRESHOLD) exit 1}'`.
- **Files**: `/var/www/simplead-manager/phpstan-baseline.neon` (primary), top offenders: `app/Services/ReportDataGatherer.php` (43 baseline entries), `app/Livewire/Sites/Detail/SiteReports.php` (22), `app/Livewire/Sites/Detail/SitePlugins.php` (20).

---

### 5. [High] `WordPressApiService` manually instantiated 54 times — untestable, throttle state unreachable

- **Category**: Design / Architecture
- **Severity**: High
- **Effort**: M
- **Business Impact**: `new WordPressApiService($site)` appears in 54 places across jobs, services, and Livewire components. Each instantiation creates a fresh object with its own throttle state, meaning the carefully implemented backoff logic is reset on every call within a request or job. More critically, it makes every caller untestable without making real HTTP calls to WordPress.
- **Evidence**:
  - `grep -rn "new WordPressApiService" app/` yields 54 matches across: `CreateBackup.php` (3×), `RestoreBackup.php` (2×), `SitePlugins.php` (13×), and 14 other files.
  - `SitePlugins.php` instantiates a new `WordPressApiService` inside each of 13 individual action methods (activate, deactivate, delete, etc.).
- **Fix outline**:
  1. Extract a `WordPressApiServiceInterface` (or use the class itself as the contract since it has no parent).
  2. Register a contextual binding or factory in `AppServiceProvider`: `$this->app->bind(WordPressApiService::class, fn($app, $params) => new WordPressApiService($params['site']))`.
  3. In `SitePlugins`, instantiate once in `mount()` and store as a property, or inject via a method parameter.
  4. In Jobs, accept via constructor injection or a factory — this also allows mocking in tests.
- **Files**: `/var/www/simplead-manager/app/Services/WordPressApiService.php`, `app/Livewire/Sites/Detail/SitePlugins.php`, `app/Jobs/CreateBackup.php`, `app/Jobs/RestoreBackup.php`, and 10 other service files.

---

### 6. [High] `WordPressApiService::request()` and `streamDownloadTo()` contain duplicated HMAC signing and retry loops

- **Category**: Code Quality / Design
- **Severity**: High
- **Effort**: M
- **Business Impact**: The HMAC authentication logic (`X-SAM-Key`, `X-SAM-Timestamp`, `X-SAM-Nonce`, `X-SAM-Signature`) is copy-pasted across four methods: `request()` (lines 109–168), `requestRaw()` (lines 207–254), `streamDownloadTo()` (lines 686–755), and `streamDownload()` (lines 834–898). Any change to the signing algorithm or retry logic must be applied in four separate places. The 429 backoff loop is also duplicated verbatim three times.
- **Evidence**: `/var/www/simplead-manager/app/Services/WordPressApiService.php` is 900 lines. Four separate for-loops with identical `$timestamp = (string) time(); $nonce = bin2hex(random_bytes(16)); $stringToSign = implode('|', [...]); $signature = hash_hmac(...)` blocks.
- **Fix outline**:
  1. Extract a private `buildAuthHeaders(string $method, string $path, string $body): array` method.
  2. Extract a private `executeWithRetry(callable $attempt, int $maxRetries): mixed` method to handle the 429 backoff loop.
  3. `streamDownloadTo()` and `streamDownload()` are largely identical — merge them into one method with a `$deleteAfter` parameter or abstract the curl setup.
  4. Result: `request()` drops from ~60 lines to ~20; entire file drops below 600 lines.
- **Files**: `/var/www/simplead-manager/app/Services/WordPressApiService.php` (lines 94–898).

---

### 7. [High] `ReportDataGatherer` is a 1 176-line God Class with 20+ data-gathering methods

- **Category**: Design / Architecture
- **Severity**: High
- **Effort**: L
- **Business Impact**: This class gathers data for 12+ different report sections (overview, updates, uptime, backups, analytics, search console, performance, database, security, email, cloudflare, WP users). It is the second-highest churn file (14 commits in 90 days). Any new report section requires modifying this one file, causing merge conflicts and review friction. It has zero test coverage.
- **Evidence**: `wc -l app/Services/ReportDataGatherer.php` = 1 176 lines. 20 `gather*` methods. PHPStan baseline lists 43 errors in this file.
- **Fix outline**:
  1. Extract each `gather*` method into its own `*DataGatherer` class (e.g., `UptimeDataGatherer`, `BackupDataGatherer`, `AnalyticsDataGatherer`).
  2. Inject these via a `DataGathererRegistry` or collect them with a tagged service container binding.
  3. `ReportDataGatherer` becomes an orchestrator that iterates registered gatherers, calling `gather()` on each.
  4. This makes each gatherer independently testable.
- **Files**: `/var/www/simplead-manager/app/Services/ReportDataGatherer.php`.

---

### 8. [Medium] CI tests PHP 8.3 but production runs PHP 8.2 — undocumented version mismatch

- **Category**: Infrastructure
- **Severity**: Medium
- **Effort**: S
- **Business Impact**: `composer.json` requires `^8.2`, the production container runs PHP 8.3.30 (confirmed via `php -v`), and CI runs `php-version: '8.3'`. The README and `CLAUDE.md` document PHP 8.2. This discrepancy means the production runtime is untested if CI ever adds a 8.2-specific job, and any "requires 8.3" library added will silently work in prod but be undocumented.
- **Evidence**: `ci.yml` line 19, 37, 81: `php-version: '8.3'`. Container: `PHP 8.3.30`. `composer.json`: `"php": "^8.2"`. `CLAUDE.md`: "PHP 8.2".
- **Fix outline**:
  1. Decide on a canonical PHP version: update `composer.json` to `"php": "^8.3"` and update `CLAUDE.md` and `README.md`.
  2. Update the Docker base image pin in `docker/Dockerfile` to match.
  3. Alternatively, if 8.2 must be supported, pin CI to 8.2 and add an 8.3 matrix entry.
- **Files**: `/var/www/simplead-manager/composer.json`, `CLAUDE.md`, `.github/workflows/ci.yml`, `docker/Dockerfile`.

---

### 9. [Medium] No `composer audit` step in CI — security advisories go undetected

- **Category**: Infrastructure
- **Severity**: Medium
- **Effort**: S
- **Business Impact**: `composer audit` is not in the CI pipeline. Security vulnerabilities in dependencies (e.g., in `guzzlehttp/guzzle`, `laravel/framework`, or `aws/aws-sdk-php`) will not be caught automatically. Given the application handles authentication, backup archives, and signed download URLs, a known CVE in a dependency is a direct production risk.
- **Evidence**: `/var/www/simplead-manager/.github/workflows/ci.yml` — no `composer audit` step exists. The `composer` binary is not installed in the production container, so it cannot be run there either.
- **Fix outline**:
  1. Add a `security` job to `ci.yml` that runs `composer audit --format=json` and fails on high/critical advisories.
  2. Consider using `composer audit --locked` to scan `composer.lock` without requiring a full install.
  3. Example:
     ```yaml
     security:
       runs-on: ubuntu-latest
       name: Security Audit
       steps:
         - uses: actions/checkout@v4
         - uses: shivammathur/setup-php@v2
           with: { php-version: '8.3', tools: 'composer:v2' }
         - run: composer install --no-interaction --prefer-dist
         - run: composer audit
     ```
- **Files**: `/var/www/simplead-manager/.github/workflows/ci.yml`.

---

### 10. [Medium] `env()` called directly in two `app/` files — bypasses config cache

- **Category**: Code Quality
- **Severity**: Medium
- **Effort**: S
- **Business Impact**: `env()` called outside `config/` files returns `null` when the application is bootstrapped with a cached config (`php artisan config:cache`), which is standard in production. If these code paths execute after config caching, `env('BACKUP_ENCRYPTION_KEY')` returns `null` silently, disabling encryption without any error.
- **Evidence**:
  - `app/Services/AppBackup/AppBackupService.php:599`: `$value = env($key);`
  - `app/Console/Commands/DatabaseDumpCommand.php:70`: `$encryptionKey = env('BACKUP_ENCRYPTION_KEY');`
- **Fix outline**:
  1. Add `BACKUP_ENCRYPTION_KEY` to `config/app.php` or a dedicated `config/backup.php`: `'encryption_key' => env('BACKUP_ENCRYPTION_KEY')`.
  2. Replace the `env()` calls with `config('backup.encryption_key')`.
  3. Add to CI: `grep -rn "env(" app/ --include="*.php"` check that fails on any match.
- **Files**: `/var/www/simplead-manager/app/Services/AppBackup/AppBackupService.php:599`, `app/Console/Commands/DatabaseDumpCommand.php:70`.

---

## Additional Findings (Medium / Low)

### M1. [Medium] `SitePlugins` Livewire component is 890 lines with direct API calls in action methods

The component handles plugins, themes, users, updates, bulk actions, and core updates — 30+ public methods. Each action creates a new `WordPressApiService`. This violates the Livewire pattern (UI state only) by embedding all business logic in the component.

**Fix**: Extract plugin/theme/user operations into a `PluginManagerService` and `ThemeManagerService`. The Livewire component calls service methods and handles UI feedback only.

**Files**: `/var/www/simplead-manager/app/Livewire/Sites/Detail/SitePlugins.php`

---

### M2. [Medium] `RestoreBackup` uses `ini_set('memory_limit', '1G')` — runtime config override

At line 48 of `RestoreBackup.php`: `ini_set('memory_limit', '1G');`. This should be configured at the PHP/Docker level for the `backups` queue worker, not patched at runtime inside a job.

**Fix**: Set `memory_limit = 1G` in the PHP config for the Horizon/queue containers, or set `public int $memory = 1024;` on the job if Horizon memory management is being used.

**Files**: `/var/www/simplead-manager/app/Jobs/RestoreBackup.php:48`

---

### M3. [Medium] `DashboardService` re-queries `getBackupCounts()` internally — duplicated DB work

`computeStats()` and `computeBackupStatus()` both call `getBackupCounts()` which runs three queries. `computeSummaryStats()` calls `getStats()` (which runs `computeStats()`) AND `getBackupCounts()` separately — the backup count queries execute twice for any caller of `getSummaryStats()`. All methods are behind a 60-second cache individually but the duplication is wasteful within a single request.

**Fix**: `computeSummaryStats()` should reference `$this->computeStats()['failed_backups']` directly instead of calling `getBackupCounts()` again.

**Files**: `/var/www/simplead-manager/app/Services/DashboardService.php:257–273`

---

### M4. [Medium] `CheckUptime::updateUptimeStats()` uses a raw `DB::selectOne` SQL with 9 bound parameters

The raw SQL computes uptime for 24h/7d/30d/365d windows in one query. While efficient, it bypasses Eloquent type casting and is fragile to schema changes. The use of `\Illuminate\Support\Facades\DB` with a fully-qualified namespace inside the method body is also a style inconsistency.

**Fix**: Extract into a repository or scope method on `UptimeCheck`. Use Eloquent aggregate queries or a query scope. Add the `use DB;` import at the top of the file.

**Files**: `/var/www/simplead-manager/app/Jobs/CheckUptime.php:219–242`

---

### M5. [Medium] `MaintenancePlans.php` Livewire component is 798 lines

Similar to `SitePlugins`, this component manages plan creation, editing, module configuration, and preset application. Business logic for plan operations belongs in `ModuleConfigService`.

**Files**: `/var/www/simplead-manager/app/Livewire/MaintenancePlans.php`

---

### L1. [Low] `dispatch_backup.php` is tracked in git with hardcoded site/backup IDs (see item 3)

Already covered as Critical item 3 — removing it is the fix.

---

### L2. [Low] CI runs Pint, PHPStan, and tests but misses a `composer validate` check

`composer validate --strict` would catch malformed `composer.json` or mismatched `composer.lock` before the install step, failing fast.

**Files**: `/var/www/simplead-manager/.github/workflows/ci.yml`

---

### L3. [Low] `SiteBackups.php` (500 lines) and `SiteOverview.php` (383 lines) are approaching god-component territory

Both are in the top-10 churn files and contain inline business logic. Not yet critical but worth monitoring.

**Files**: `app/Livewire/Sites/Detail/SiteBackups.php`, `app/Livewire/Sites/Detail/SiteOverview.php`

---

### L4. [Low] `DropboxDriver.php` is 418 lines — longest storage driver by far

The S3 driver is 259 lines for equivalent functionality. The Dropbox driver likely handles chunked uploads inline. Worth extracting the chunking logic.

**Files**: `/var/www/simplead-manager/app/Services/Backup/Storage/DropboxDriver.php`

---

## Sprint-Ready Work Items

### Epic: Technical Debt Reduction — Q2 2026

---

#### Story: Remove debug script from repository

**Priority**: Critical
**Effort**: 1 point

**Acceptance Criteria**
- [ ] `dispatch_backup.php` is removed from the repository (`git rm dispatch_backup.php`)
- [ ] `dispatch_*.php` is added to `.gitignore`
- [ ] CI passes after the change

---

#### Story: Add `composer audit` to CI pipeline

**Priority**: Critical
**Effort**: 1 point

**Acceptance Criteria**
- [ ] A new `security` job in `ci.yml` runs `composer audit`
- [ ] The job fails on any high or critical advisory
- [ ] Job runs on every push to `main` and every PR

---

#### Story: Add `declare(strict_types=1)` to all PHP files

**Priority**: High
**Effort**: 2 points

**Acceptance Criteria**
- [ ] All 342 files in `app/` have `declare(strict_types=1);` as the second line
- [ ] `./vendor/bin/pint --test` passes with no violations
- [ ] `./vendor/bin/phpstan analyse` passes (or any new errors are fixed, not baselined)
- [ ] `php artisan test` passes with no regressions

---

#### Story: Replace `env()` calls in app/ with `config()` equivalents

**Priority**: High
**Effort**: 1 point

**Acceptance Criteria**
- [ ] `app/Services/AppBackup/AppBackupService.php:599` uses `config()` instead of `env()`
- [ ] `app/Console/Commands/DatabaseDumpCommand.php:70` uses `config()` instead of `env()`
- [ ] A `BACKUP_ENCRYPTION_KEY` entry exists in a config file
- [ ] CI Pint and PHPStan pass

---

#### Story: Add test coverage for backup creation happy path and failure path

**Priority**: Critical
**Effort**: 8 points

**Acceptance Criteria**
- [ ] Unit test: `CreateBackup::prepare()` creates a `Backup` record with `InProgress` status
- [ ] Unit test: `CreateBackup::prepare()` marks orphaned pending backups as `Failed`
- [ ] Unit test: `CreateBackup::finalize()` updates backup to `Completed` and updates `site.backup_ok`
- [ ] Feature test: `CreateBackup` job dispatched to sync queue completes successfully with a mocked `WordPressApiService`
- [ ] Unit test: `CreateBackup` handles `WordPressApiException` and sets backup status to `Failed`
- [ ] All tests pass in CI

---

#### Story: Add test coverage for restore single-backup flow

**Priority**: Critical
**Effort**: 5 points

**Acceptance Criteria**
- [ ] Unit test: `RestoreBackup::restoreSingleBackup()` downloads, verifies checksum, and calls `doRestore()`
- [ ] Unit test: `RestoreBackup::restoreSingleBackup()` throws on checksum mismatch
- [ ] Unit test: `RestoreBackup::mergeChunkZipsForRestore()` correctly merges v2 format chunk zips
- [ ] Unit test: cleanup temp directory on exception
- [ ] All tests pass

---

#### Story: Add test coverage for uptime check state machine

**Priority**: High
**Effort**: 3 points

**Acceptance Criteria**
- [ ] Unit test: site transitions from `up` to `down` after `alert_after_failures` consecutive failures
- [ ] Unit test: `SiteWentDown` event is dispatched exactly once at threshold, not on every failure
- [ ] Unit test: site transitions from `down` to `up` and `SiteRecovered` event fires
- [ ] Unit test: incident is created on first failure, resolved on recovery
- [ ] All tests pass

---

#### Story: Reduce PHPStan baseline by 25% (from 441 to ~330)

**Priority**: High
**Effort**: 5 points

**Acceptance Criteria**
- [ ] `phpstan-baseline.neon` contains fewer than 330 suppressed errors (from 441)
- [ ] "Access to undefined property on Model" errors in `ReportDataGatherer` resolved via `@property` annotations
- [ ] `StorageFactory::make()` callers pass `StorageDestination` not bare `Model`
- [ ] `WordPressApiService` constructor callers pass `Site` not `Model|null`
- [ ] PHPStan runs clean in CI with the reduced baseline

---

#### Story: Extract HMAC signing and retry loop from `WordPressApiService`

**Priority**: High
**Effort**: 3 points

**Acceptance Criteria**
- [ ] A private `buildAuthHeaders(string $method, string $path, string $body): array` method exists
- [ ] A private `executeWithRetry(callable $attempt, int $maxRetries): Response` method handles 429 logic
- [ ] `request()`, `requestRaw()`, `streamDownloadTo()`, `streamDownload()` all delegate to these helpers
- [ ] No behavioral change — existing manual integration tests (smoke tests) pass
- [ ] File length drops below 600 lines

---

#### Story: Reconcile PHP version between composer.json, CI, and production

**Priority**: Medium
**Effort**: 1 point

**Acceptance Criteria**
- [ ] `composer.json` `"php"` constraint, CI `php-version`, Docker base image, and documentation all reference the same version
- [ ] `CLAUDE.md` and `README.md` reflect the canonical version
- [ ] `composer install` passes in CI

---

#### Story: Extract `ReportDataGatherer` section methods into per-section gatherer classes

**Priority**: Medium
**Effort**: 8 points

**Acceptance Criteria**
- [ ] Each `gather*Data()` method is extracted into a dedicated class (e.g., `UptimeDataGatherer`, `BackupDataGatherer`)
- [ ] Each gatherer class implements a common `DataGathererInterface`
- [ ] `ReportDataGatherer` delegates to the registered gatherers
- [ ] Existing report generation produces identical output (tested via snapshot or comparison)
- [ ] PHPStan baseline for this file reduces by at least 20 errors
- [ ] At least 3 of the new gatherer classes have unit tests

---

## Quarterly Refactoring Roadmap — Q2 2026

### Month 1 (April): Security, Safety, and CI Hardening

*Goal: Eliminate the critical risks and establish quality gates that prevent regression.*

- [ ] Remove `dispatch_backup.php` from the repository (**Critical, 1pt**)
- [ ] Add `composer audit` to CI (**Critical, 1pt**)
- [ ] Add `declare(strict_types=1)` to all PHP files (**High, 2pt**)
- [ ] Fix `env()` calls in `app/` (**High, 1pt**)
- [ ] Reconcile PHP version across composer.json, CI, Docker, docs (**Medium, 1pt**)
- [ ] Add `composer validate --strict` to CI (**Low, 0.5pt**)

**Outcome**: CI catches security advisories, type errors, and accidental debug artifacts automatically. No new type coercion surprises from silent PHP coercions.

---

### Month 2 (May): Test Coverage for Critical Paths

*Goal: Build a safety net around the highest-risk code before refactoring it.*

- [ ] Add backup creation tests (happy path + failure) (**Critical, 8pt**)
- [ ] Add restore single-backup tests (**Critical, 5pt**)
- [ ] Add uptime check state machine tests (**High, 3pt**)
- [ ] Add unit test for `WordPressApiService::request()` HMAC signing correctness (**High, 2pt**)

**Outcome**: Core business flows (backup, restore, uptime) have automated regression coverage. Refactors in Month 3 can proceed with confidence.

---

### Month 3 (June): Code Quality and Architecture

*Goal: Reduce the debt that slows feature development — duplication, god classes, tight coupling.*

- [ ] Extract HMAC signing and retry loop from `WordPressApiService` (**High, 3pt**)
- [ ] Reduce PHPStan baseline by 25% (**High, 5pt**)
- [ ] Add PHPStan baseline size check to CI (fail if baseline grows) (**Medium, 1pt**)
- [ ] Extract `ReportDataGatherer` section methods into per-section gatherer classes — start with 3 highest-churn sections (**Medium, 8pt**)
- [ ] Refactor `SitePlugins` Livewire component: extract `PluginManagerService` (**Medium, 5pt**)

**Outcome**: The highest-complexity files are smaller, independently testable, and not blocked on a single author's knowledge of the internals.

---

## Metrics Dashboard Baseline

| Metric | Current (2026-03-23) | Target (end Q2 2026) | Trend |
|---|---|---|---|
| `composer audit` high/critical issues | Unknown (not in CI) | 0 (gated in CI) | — |
| PHPStan baseline errors | 441 | ≤ 330 | — |
| PHPStan level | 5 | 5 (maintain) | — |
| `declare(strict_types=1)` coverage | 0 / 342 files (0%) | 342 / 342 (100%) | — |
| `env()` calls in `app/` | 2 | 0 | — |
| Test files | 26 | 32+ | — |
| Critical flow test coverage (backup/restore/uptime) | 0% | Meaningful coverage | — |
| Pint violations | 0 (clean) | 0 (maintain) | Stable |
| `dispatch_backup.php` in repo | Yes | Removed | — |
| Largest single file (app/) | 1 176 lines (`ReportDataGatherer`) | ≤ 700 lines | — |
| `WordPressApiService` length | 900 lines | ≤ 600 lines | — |
| `SitePlugins` length | 890 lines | ≤ 500 lines | — |

---

## Notes on Analysis Methodology

**What was scanned manually** (dev dependencies not installed in production container):
- Git log churn analysis (90-day window)
- PHPStan baseline file analysis (441 errors, 2647 lines)
- Code review of top-20 churn files
- `grep`-based pattern analysis for `env()`, `new WordPressApiService`, `declare(strict_types)`, `DB::`, `ini_set`, test coverage gaps
- `composer.lock` version inspection
- CI pipeline review

**What requires a dev environment to re-run**:
- `./vendor/bin/phpstan analyse` — live analysis against current code
- `./vendor/bin/pint --test` — style violations (currently clean per last CI run)
- `php artisan test` — test suite execution (requires running PostgreSQL at `pgsql:5432`)
- `composer audit` — security advisories against current `composer.lock`

**Fowler Quadrant classification of top items**:
- Strict types absence: **Reckless + Inadvertent** (project grew without the convention being enforced)
- 441 suppressed PHPStan errors: **Prudent + Deliberate** (moved fast to ship, now needs payback)
- Missing backup tests: **Reckless + Inadvertent** (grew organically, tests not written alongside features)
- `WordPressApiService` duplication: **Reckless + Inadvertent** (copy-paste under time pressure)
- `ReportDataGatherer` god class: **Prudent + Deliberate** (centralised for convenience, now a bottleneck)
