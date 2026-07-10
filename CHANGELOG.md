# Changelog

All notable changes to SimpleAd Manager are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); dates are `YYYY-MM-DD`. Security- and
data-integrity-relevant work is called out because this platform manages live client
WordPress sites.

## [Unreleased]

### Audit
- **Full-application re-audit (2026-07-10).** Read-only pass against the current tree
  (branch `fix/atomic-restore` = `main` + in-review PR #15). 17 module/cross-cutting
  audits, a 3-competitor benchmark (WPMU DEV, ManageWP, WP Umbrella), and adversarial
  verification of every candidate P0/P1. Single deliverable:
  [`docs/audit/2026-07-10-full-audit.md`](docs/audit/2026-07-10-full-audit.md).
  Overall health **4.5/10** (up from 2.5). Result: **1 confirmed P0** (SafeUpdate records
  vulnerable sites as patched) and ~45 confirmed P1s. Corrected three stale assumptions:
  PDF is Gotenberg 8 (not Browsershot), Horizon runs 6 supervisors (no backups/notifications
  starvation), and billing does not exist.

### Docs
- Restructured `docs/audit/`: the 2026-07-02 snapshot (root `AUDIT.md`/`ROADMAP.md`, the
  17 module reports, `README.md`, `REMEDIATION-PLAN.md`, `REMEDIATION-STATUS.md`) is
  superseded by the single 2026-07-10 document and removed from the tree (history preserved
  in git). Remediation tracking now lives in that document's Part E backlog and this
  changelog. The audit prompt is retained as `docs/audit/audit-charter.md`.

## [2026-07-10]

### Changed (in review)
- **PR #15** — atomic staged restore: connector 2.15.0 stages a file swap (journaled,
  recoverable via a trash dir) and imports the DB into temp-prefixed tables then renames
  atomically; the manager sends `file_mode` (staged for full, merge for selective), makes
  the pre-restore safety backup mandatory, and gates a `restoreAnyway()` bypass behind a
  typed site-domain confirmation logged as a critical activity. *(AUDIT B-P0-2.)*

## [2026-07-05]

### Fixed
- **PR #14** — per-site operation lock + recoverable restores: cross-class `SiteOperationLock`,
  `failed()`/`uniqueFor` on `RestoreBackup`, `recoverStuckRestores` without auto-retry,
  extended `backup:release-lock`. *(AUDIT B-P0-1 / QS-01 / B-P1-1.)*

### Added
- **PR #13** — test foundation: PHPStan baseline eliminated and CI made blocking, hermetic
  test env, `FakeWordPressApiService` connector fake. *(AUDIT Wave 0.)*

## [2026-07-02]

### Security
- **PR #6** — authorize restore + block cross-tenant IDOR on `openModal(backupId)`.
  *(AUDIT P0-1 / S-01.)*
- **PR #8** — block Viewers from destructive site-detail actions. *(AUDIT #7.)*
- **PR #11** — block Viewers on monitoring/SEO actions + scope error logs to the tenant.
  *(AUDIT #7 slice 2.)*

### Fixed
- **PR #9** — graceful Horizon drain + PgBouncer restart after DDL migrations. *(AUDIT #5 / INF-04.)*
- **PR #12** — bound the Horizon drain timeout so deploys can't hang.

### Added
- **PR #7** — minimal blocking GitHub Actions pipeline (Pint + PHPStan + PHPUnit on
  ephemeral pgsql/redis) + isolated test env.
- **PR #5** — full technical + product audit (2026-07), 17 reports. *(Superseded 2026-07-10.)*
