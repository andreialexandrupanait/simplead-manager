# Changelog

All notable changes to SimpleAd Manager are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); dates are `YYYY-MM-DD`. Security- and
data-integrity-relevant work is called out because this platform manages live client
WordPress sites.

## [Unreleased]

### Fixed
- **C-06** — pinned `edoburu/pgbouncer` to the exact digest running in production
  instead of `:latest`: a silent PgBouncer version jump on container recreate could
  change pooling behavior under the app with no diff in the repo. *(Faza C, val C1-a.)*
- **C-07** — `/restore-download/{token}` now expires: files older than 45 minutes
  (the legitimate connector-fetch window is ≤30) are rejected and deleted. A worker
  killed between staging the archive and cleanup used to leave a full site backup
  downloadable by anyone holding the token until the 24h temp sweep. *(Faza C, val C1-a.)*
- **C-14** — keyword-ranking history was shifted +3 days: `FetchKeywordRankings`
  pulled the final GSC window (`now()-3d`) but stamped rows with the *fetch* date,
  so every point in `seo_keyword_rankings` sat 3 days ahead of the date its data
  describes. Rows are now labeled with the data date, and a one-time migration
  shifts existing history back 3 days. *(Faza C, val C1-a.)*
### Removed
- **C-04** — dropped 12 orphan SEO/keyword tables from an abandoned crawler/
  keyword-research design (`crawled_pages`, `site_crawls`, `seo_contents`,
  `seo_content_revisions`, `seo_alert_rules`, `backlinks`, `backlink_snapshots`,
  `tracked_keywords`, `keyword_positions`, `keyword_page_mappings`,
  `keyword_research_results`, `competitor_keyword_positions`). Verified at HEAD
  `0351c29`: no model, no app query, no incoming FK for any of them; the lone
  reference (a `keyword_positions` retention-cleanup entry, already
  `Schema::hasTable`-guarded) is removed in the same change. Deploy takes a
  `pg_dump` first (runbook §3b). Full `pgsql-schema.sql` regeneration is a
  separate follow-up. *(Faza C, val C1-a.)*

### Program: corectare completă + modul SEO/Audit unificat
- **Faza A — fundație & inventar**: baseline quality verde (Pint 783 fișiere, PHPStan 0 erori,
  PHPUnit 744/744) consemnat în `docs/plan/raport-faza-A.md`; toate cele 13 probleme cunoscute
  confirmate în cod cu file:line (nuanțe: ~9–12 tabele orfane nu 14; `SiteSeoAudit` e Livewire nu
  Job; trustProxies `env()` e revert deliberat P3-34 — fix-ul se re-proiectează cu L12);
  `docs/plan/inventar.md` cu modulele existente.
- **Faza B — research & propuneri**: 4 rapoarte de research în `docs/plan/` (r1 WPMU DEV live —
  catalog 12 Pro + Hub, gap-uri și licențiere; r2 webp-uploads v2.7.1 + plan orchestrare; r3
  autopsia modulului SEO cu verdicte MOARE/SE TRANSFORMĂ/SUPRAVIEȚUIEȘTE pe cod; r4 metodologia
  celor 82 de verificări din simplead-audit @ 9aeb9f4 + plan de port Laravel) consolidate în
  `docs/plan/propuneri.md` — STOP: așteaptă bifele proprietarului.
- **Pas 0 — setup program**: promptul-program v1.1 mutat în `docs/plan/program-prompt.md`;
  răspunsurile proprietarului la întrebările de start (site pilot notificarialimente.ro,
  Screaming Frog pe dasher cu licență disponibilă, cheia Anthropic se adaugă la Faza D)
  consemnate în `docs/plan/config.md`; `docs/plan/STATUS.md` inițializat ca punct de
  reluare între sesiuni (regula globală 10 din program).

## [2026-07-11]

### Security module — design-only features made functional (PRs #43-#47)
- **PR #43** — incident response queried columns that never existed
  (`SecurityIssue.status`, `VulnerabilityAlert.plugin_slug`/`cvss_score`, plus a bare
  `->latest()` on timestamp-less `update_logs`) and crashed with SQLSTATE 42703 the
  moment `incident-response.enabled` was turned on. Real columns + executed-query
  contract tests. *(SEC-A2-04.)*
- **PR #44** — a killed/timed-out incident-response worker left the incident
  non-terminal forever, silently extending the dispatcher cooldown; `failed()` now
  marks it failed by natural key + a 15-min stale sweep covers kill -9. *(SEC-A2-11.)*
- **PR #45** — removed the dead SecurityCommand/agent-pull path (-1052 lines): no
  WP-side poller ever shipped, so commands only accumulated as pending debris while
  the real enforcement ran through the signed push; agent routes/controller/middleware
  gone, "pending commands" UI counters replaced with an honest failed-settings count;
  dropped `security_commands`; deleted the unrouted SecurityComingSoon component and
  the decorative "Coming Soon" overview cards.
- **PR #46** — connector security batch: manager-side IP unbans now actually clear the
  WordPress ban option AND the brute-force transient (they used to resurrect on the
  next sync); the "Fix" button for directory listing works (fix key had no handler);
  the IP whitelist restricts login/wp-admin/XML-RPC only instead of 403-ing the whole
  public site *(E-43)*; empty `banned_ips` is treated as authoritative.
- **PR #47** — real two-factor authentication for WordPress logins (email code):
  role-targeted, hashed 10-minute codes, 5-attempt lockout, 30-day trusted devices,
  app-password/REST/XML-RPC exempt, configurable fail-open/closed on mail failure —
  replaces the "Coming Soon" badge and the dead toggle. Connector **2.17.0**.

### Fixed
- **PR #32** — deploy migrations now run against direct Postgres (`pgsql_direct`
  connection, `DB_DIRECT_HOST` baked into the prod compose env): PgBouncer transaction
  pooling broke Laravel's prepared-statement protocol on multi-statement DDL, which
  failed the 2026-07-10 deploy and required manual psql surgery.
- **PR #33** — notification pipeline (audit N-P1-1/N-P1-2, backlog E-32/E-33):
  acknowledgement links are now actually delivered in critical/warning messages
  (tokens existed but were never sent → escalation was unconditional); escalation-
  generated sends are born `escalated` so A→B/B→A rule pairs cannot loop; a dead
  channel's final attempt now throws instead of silently succeeding, and failed
  sends still escalate; `ProcessNotificationBatch` skips malformed buffer items;
  `GoogleApiService` retry callback typehint fixed (`PendingRequest`). Root-caused
  the `failed_jobs` flood: orphaned reserved jobs from the 2026-07-10 broken-deploy
  window recycling every `retry_after`=7200s — self-drained after the restart.
- **PR #36** — agent auth was structurally dead (audit SC-A2-03): the middleware
  matched plaintext tokens against the `encrypted`-cast `api_key` column (random IV ⇒
  never equal). Added deterministic indexed `sites.api_key_hash` (+ backfill, + model
  sync hook); lookup now works while keys stay encrypted at rest.
- **PR #37** — `SiteOperationLock` moved off the evictable Redis cache (audit E-06):
  volatile-lru could evict TTL'd lock keys under memory pressure, allowing a restore
  to run concurrently with a backup. Locks now live on the database store
  (`cache.site_operation_lock_store`), with new `cache`/`cache_locks` tables.
- **PR #39** — SEO `bulkFix` made non-destructive and its auth fixed (audit E-10 +
  E-34/ARH-01): no bulk noindex→index flips, scraped-empty values are never pushed
  over real content, every applied change is activity-logged, and writes go through
  the signed HMAC client (the raw `X-SAM-API-Key` path 401'd on every request).
- **PR #40** — backup chunk downloads stream to disk with a hard size cap: reading
  the response into a string killed workers with 256M memory fatals when a connector
  returned more than requested, orphaning reserved jobs (ghost
  `MaxAttemptsExceededException` failures 2h later).

### Added
- **PR #35** — `deploy.sh` CI gate (audit T-A2-01): refuses to ship when the deployed
  commit's Pint/PHPStan/PHPUnit checks are missing or red (`DEPLOY_SKIP_CI_CHECK=1`
  emergency override).
- **PR #38** — `backups:recover-stuck-restores` (audit E-23): scheduled every 15
  minutes; fails restores silent past 75 min (heartbeat = `backups.updated_at`,
  threshold > the 3600s job timeout), releases the site lock ownership-checked, and
  alerts via the activity log + `NotifyRestoreFailed`.

### Removed
- **PR #34** — 2FA leftovers from PR #31: dropped `users.two_factor_*` columns and
  removed `pragmarx/google2fa-laravel` + `bacon/bacon-qr-code` (and transitives).

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
