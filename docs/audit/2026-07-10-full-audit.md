# SimpleAd Manager — Full-Application Audit, Roadmap & Action Plan

**Date:** 2026-07-10 · **Auditor:** Claude Code (read-only analysis pass) · **Scope:** current working tree, branch `fix/atomic-restore` (= `main` + in-review PR #15) · **Method:** inventory (Phase 0) → 12 module + 5 cross-cutting deep audits (17 agents) → 3-competitor web benchmark → adversarial verification of every candidate P0/P1 (5 refutation agents) → synthesis. Seeded from the 2026-07-02 audit; every prior finding re-verified against current code.

> **One-sentence verdict:** The platform is broad and, since the July remediation wave (PRs #6–#15), materially safer on its single most dangerous path — restore now has authorization, a per-site lock, recovery, and near-atomic staged swap — but the audit's structural theme survives intact: **safety nets that are designed but dead at runtime** (safe-update reports false success, the vulnerability feed returns empty so every site looks "clean", `health_score` has no writer) and a **Viewer-authorization gap that was fixed only in half the components**. It is no longer *reckless*; it is *fragile*, and two destructive paths — plugin updates and cross-tenant backup deletion — still need immediate work.

This document has two deliberately separated tracks: **Track 1 — Correctness & Stability** (Parts A, B, D, E) and **Track 2 — Competitive "Wow"** (Part C).

---

## Table of contents

- [Executive Summary](#executive-summary)
  - [Health scorecard](#health-scorecard)
  - [Top 5 risks to existing clients](#top-5-risks-to-existing-clients)
  - [Top 5 "wow" opportunities](#top-5-wow-opportunities)
  - [What changed since 2026-07-02](#what-changed-since-2026-07-02)
- [Part A — Per-module correctness & stability audit](#part-a--per-module-correctness--stability-audit)
- [Part B — Cross-cutting / architecture audit](#part-b--cross-cutting--architecture-audit)
- [Part C — Competitive gap analysis & "wow" opportunities](#part-c--competitive-gap-analysis--wow-opportunities)
- [Part D — Roadmap](#part-d--roadmap)
- [Part E — To-do backlog](#part-e--to-do-backlog)
- [Appendix — unverified items, assumptions, open questions](#appendix--unverified-items-assumptions-and-open-questions)

---

## Executive Summary

SimpleAd Manager is a wide, ambitious WordPress-fleet management SaaS — 91 models, 125 services, 51 jobs, 104 Livewire components, ~108 tables, plus a 44-file connector plugin (v2.15.0). Thirteen functional modules are present and, at the surface, complete: sites/connector, backups/restore, plugin & update management, security hardening & incident response, uptime/SSL/DNS, performance (PageSpeed), SEO, reports & client portal, integrations (Cloudflare/GA/GSC/Dropbox), notifications, public status pages, dashboard/health, and error-log aggregation.

The **breadth exceeds any single incumbent** (WPMU DEV, ManageWP, WP Umbrella). The **depth and reliability of each capability remain below a production bar** — because most of the value is *built but disconnected, broken at runtime, or unguarded*, exactly as the July audit found. The difference three months on: the worst catastrophes have been closed. Restore was the July audit's nightmare (no authorization, non-atomic, no lock, no recovery, no tests); it is now the module with the **most** engineering care applied — a per-site operation lock, `failed()`/`uniqueFor`, stuck-restore recovery, a mandatory full safety backup, and a journaled staged file-swap plus atomic table-rename DB import in connector 2.15.0. That work is real and largely sound.

But the audit surfaced **1 confirmed P0 and roughly 45 confirmed P1s** (after adversarial verification downgraded several candidates). The P0 and the most urgent P1s cluster in three places: **plugin/update management** (the safe-update pipeline silently records vulnerable sites as patched), **backup deletion & multi-tenant authorization** (a Viewer can irreversibly delete any tenant's backups; a maintenance-plan action writes config to *every* connected site with no tenant scope), and **observability** (the "Horizon down" alarm rides the very queue Horizon processes; the vulnerability feed swallows errors and reports all sites clean).

**Overall app-health score: 4.5 / 10** (up from 2.5 in July). Weighted by blast radius to live client sites: restore climbed from catastrophic to merely fragile, deploy safety and CI are now real, but the plugin-update destructive path is now the weakest link, the "designed-but-dead safety net" pattern is unchanged, and platform self-observability can still go blind without anyone noticing.

### Health scorecard

Each dimension 1–5 (5 = production-solid). Scores are the module agents' assessments, reconciled after adversarial verification.

| Module | Correctness | Stability | Scale-readiness | Security | Notes |
|---|:--:|:--:|:--:|:--:|---|
| Sites & Connector | 3 | 3 | 4 | 3 | Core solid; Viewer can drive WP-admin login/disconnect; agent auth structurally dead (latent) |
| Backups & Restore | 4 | 3 | 2 | 3 | Restore much improved; sync-call transport + cross-tenant delete + broken v3 selective restore remain |
| Plugin & Update Mgmt | 2 | 2 | 2 | 3.5 | **The confirmed P0 lives here**; no working rollback; updates run inline |
| Security & Incident Response | 2 | 3 | 3 | 3 | Good safety design; vuln feed returns empty; IR path crashes on missing columns (gated) |
| Uptime & DNS/SSL | 3 | 2 | 3 | 4 | Silent-failure class untouched; SSL/domain-expiry monitoring absent despite columns |
| Performance (PageSpeed) | 2 | 2 | 2 | 3 | Scheduled runs never dispatch; stale scores flow into client reports |
| SEO | 2 | 2 | 2 | 2 | Write path 100% dead (wrong auth header); a failed audit can stop a site's backups |
| Reports & Clients | 2 | 3 | 3 | 2 | Reports crash for SEO sites; permanent public link leaks vuln map + WP-user PII |
| Integrations (CF/GA/GSC/Dropbox) | 3 | 3 | 4 | 3 | Google failure opens the site circuit breaker; Viewer can rebind GA/GSC |
| Notifications | 2.5 | 2 | 2.5 | 3 | Dead channel loses alerts silently; ack tokens never delivered; Horizon-down alarm is circular |
| Status Pages | 3 | 3 | 4 | 3 | Sound visitor isolation; stale "All Operational" when monitoring is down |
| Dashboard & Health | 2 | 4 | 4 | 3 | `sites.health_score` has no writer; destructive-op audit trail loses `user_id` |
| **Cross-cutting** | | | | | |
| App-wide Security & Tenancy | 3 | 3 | 4 | 2 | ~60 unguarded mutating methods; one genuinely cross-tenant; MFA/SSO bypasses |
| Infrastructure & Docker | 3 | 3 | 3 | 4 | Charter's alarms mostly stale; self-backup unsafe; Horizon OOM budget |
| Queues & Scheduler | 4 | 3 | 3 | 4 | 6 supervisors (not ~2); no starvation; zombie-restore redelivery; evictable locks |
| Testing & CI | 2 (coverage) | 3 (quality) | 4 (CI) | 3 (static) | Blocking CI is real but `main` has no branch protection; connector fake unused |
| Architecture | 2.5 | 3 | 2.5 | 3 | God-object jobs grew; EOL framework; last unsigned connector channel (SEO) |

### Top 5 risks to existing clients

1. **P0 — Vulnerable sites recorded as patched.** `SafeUpdateService` sends a plugin *slug* where the connector requires the plugin *file*, so the update is rejected; the code ignores the result and writes `success=true` unconditionally. Its sole caller is AI incident remediation, so a security fix that never happened is logged as done. `app/Services/SafeUpdateService.php:63,78-88`.
2. **P1 — Any user can irreversibly delete any tenant's backups.** `BackupsOverview::deleteBackup()`/`bulkDelete()` do an unscoped `Backup::findOrFail`/`whereIn` with no role or ownership guard, while sibling methods guard correctly. `app/Livewire/Backups/BackupsOverview.php:299,323`.
3. **P1 — Cross-tenant config push.** `MaintenancePlans::applyPlanToAll()` iterates *all* connected sites with no `user_id` scope and no admin gate, pushing module/security/tweak configuration to every client site in the database. `app/Livewire/MaintenancePlans.php:163-171`.
4. **P1 — A killed restore silently re-runs on the live site.** A SIGKILLed `RestoreBackup` is redelivered after `retry_after` (7200s) exactly as the lock TTL (7200s) expires, and `handle()` has no `restore_status` guard, so the full restore executes again on a client site. Compounded by a 256 MB `volatile-lru` Redis that can evict the per-site lock mid-restore. `app/Jobs/RestoreBackup.php:88-143`, `config/queue.php:70`, `app/Services/Backup/SiteOperationLock.php:42`.
5. **P1 — The platform can go blind, and clients look "secure" when they aren't.** The "Horizon down" alert is dispatched onto the queue Horizon itself processes (never sends if Horizon is down), and the vulnerability feed returns `[]` on any non-2xx response and caches it 24 h, so every site reports zero vulnerabilities. `app/Console/Commands/HorizonHealthCheckCommand.php:26-38`, `app/Services/.../VulnerabilityCheckService.php:133-138`.

### Top 5 "wow" opportunities

Kept in full in [Part C](#part-c--competitive-gap-analysis--wow-opportunities); headlines:

1. **Automated restore-testing** — no incumbent verifies a backup by actually restoring it to a sandbox. SimpleAd already has the staged-restore machinery to do it. Turns "we back you up" into "we *prove* we can bring you back."
2. **Real auto-rollback safe updates** — only WP Umbrella has it; WPMU DEV/ManageWP rollback is manual. The infrastructure (`RunSafeUpdate`, health checks) exists here but is dead — wiring it is a differentiator, not a greenfield build.
3. **Client billing / subscriptions** — only WPMU DEV bundles it; absent here (no Stripe/Cashier). The agency's own revenue loop, plus `client_costs`/`client_revenues` already model the data.
4. **SLA-grade multi-location uptime + public status pages** — incumbents are single-region and mostly lack status pages; SimpleAd already *has* status pages (a rarity) and an unused `check_locations` column.
5. **Rich alerting integrations (Slack/Telegram/Discord/webhook)** — WPMU DEV and ManageWP have essentially none. SimpleAd already ships four channels; hardening delivery guarantees makes "never miss an incident" a headline.

### What changed since 2026-07-02

Verified fixed (with the PR that did it): restore authorization + cross-tenant IDOR on `openModal` (#6); minimal blocking CI + hermetic test env (#7/#13); Viewer blocked on many destructive site-detail + monitoring/SEO actions (#8/#11); graceful Horizon drain + PgBouncer restart after DDL + bounded drain timeout (#9/#12); PHPStan baseline eliminated and CI made blocking (#13); per-site `SiteOperationLock`, `failed()`/`uniqueFor`, stuck-restore recovery (#14); atomic staged restore + mandatory safety backup + connector 2.15.0 (#15, **in review, not yet deployed**).

**Three charter assumptions were disproven in code and should be corrected in institutional memory:** the PDF engine is **Gotenberg 8 (Chromium)**, not Browsershot/Puppeteer; Horizon runs **6 supervisors / 9 queues**, not ~2, so the suspected backups-vs-notifications starvation **does not exist**; and Nginx security headers, service health checks, and scripted deploy are **already present** — the "missing headers / default creds / docker cp" alarms are stale. Also confirmed: **billing/subscriptions do not exist at all**, and **domain-registration-expiry monitoring is absent** (only SSL expiry exists).

---

## Part A — Per-module correctness & stability audit
*(Track 1. Each finding: Severity · Type · Evidence · Impact on live clients · Recommendation · Safe-for-live note. IDs prefixed per module; carried findings keep their July ID, new ones use an `-A2-` suffix. Verification status shown where an adversarial pass ran.)*

### A.1 Sites & Connector — status: functional core
Scorecard: Correctness 3 · Stability 3 · Scale 4 · Security 3. Reconciliation: 2 fixed · 2 partial · 6 still-open of 10 seed findings.

- **SC-A2-01 · P1 · Fix · CONFIRMED** — Viewer/read-only users can drive destructive `SiteOverview` actions: one-click WP-admin login, credential disconnect/overwrite, API-key rotation, WP-admin-user change. `mount()` calls `authorizeSiteAccess()` (which does not block Viewers); the mutating methods have no `authorizeSiteModification`/`isViewer` guard. PR #8's authz wave missed this component. **Evidence:** `app/Livewire/Sites/Detail/SiteOverview.php:41,355,375,392` + `WithWpAdminLogin.php:11,30`. **Impact:** within-tenant privilege escalation (reach is bounded by `canAccessSite`, so not cross-tenant); a read-only user can log into and reconfigure a client's live site. **Fix:** add `authorizeSiteModification()` to each mutator (mechanical, matches the pattern already used elsewhere). **Safe-for-live:** backwards-compatible; pure guard addition.
- **SC-A2-03 · P1 · Fix** — Agent authentication is structurally dead: `AuthenticateAgent` middleware does `where('api_key', …)` against an `encrypted`-cast column, which can never match, so every agent call 401s. Latent today (no WP-side poll client ships yet) but blocks the intended pull-based agent. **Evidence:** `app/Http/Middleware/AuthenticateAgent.php:23`. **Fix:** add a deterministic `api_key_hash` lookup column. **Safe-for-live:** additive column + backfill; expand-contract.
- **SC-A2-04 · P2 · Harden** — SSRF: monitor/site URLs are fetched with no private-IP blocklist. **Evidence:** `app/DTOs/MonitorFormData.php:12` (and uptime/SEO fetchers). **Safe-for-live:** add an allowlist/blocklist filter; no data change.
- **SC-A2-05 · P2 · Fix** — SEO calls send `X-SAM-API-Key` without HMAC → 401 against the HMAC-only connector (see ARH-01). **SC-A2-02 · P2** — `rotateApiKeys()` references an undefined `$this->apiFactory` (feature dead + latent lockout design). **SC-A2-06 · P2** — concurrent connector-push race. **SC-A2-08 · P2** — no manager-side audit trail on credential/push/toggle actions.
- **Still-open seed P2/P3:** S-P2-1..5, S-P3-1/2 (unchanged; see 2026-07-02 report). Fixed since July: S-P1-3 (error-log tenant scoping), S-P1-1 SiteSettings half (authz added).

### A.2 Backups & Restore — status: creation mature, restore rebuilt, transport fragile
Scorecard: Correctness 4 · Stability 3 · Scale 2 · Security 3. Reconciliation: 4 fixed · 1 largely-fixed · 4 partial · 2 superseded · 19 still-open of 30.

**Does backup produce a restorable artifact?** Structurally verified at creation (v3-zip Level A) + weekly re-download check, but direct-to-S3 is size-checked only, dumps are not consistent snapshots, and **no restore is ever actually executed** — "probably, never proven." **Does restore work end-to-end?** The staged design (journaled file swap + `samstg_`-prefixed atomic table RENAME + mandatory full safety backup) is genuinely sound and fail-safe *per phase*. **At 100+ / multi-GB sites?** Hard ceiling from the single synchronous restore call and single-request DB import.

- **B-P1-5 · P1 · Fix · CONFIRMED (P0-candidate)** — `deleteBackup()`/`bulkDelete()` in `BackupsOverview` do `Backup::findOrFail`/`whereIn` with zero role or tenant guard; sibling methods guard, these don't. Any authenticated user, including a Viewer, can irreversibly delete **any** tenant's backups. Note: `REMEDIATION-STATUS.md`'s "fixed" claim refers to the *restore* path (B-P1-2); delete is separately tracked pending — the doc is not wrong, but the hole is open. **Evidence:** `app/Livewire/Backups/BackupsOverview.php:299,323`. **Fix:** authorize + scope to owned sites + incremental-chain guard (folds in B-P1-5's missing guard). **Safe-for-live:** guard-only.
- **B-A2-01 · P1 · Harden · CONFIRMED** — Restore is two separate synchronous POSTs (files, then DB), each with an 1800s client timeout, each `->throw()`-ing to Failed with no retry. A Cloudflare proxy terminates the idle connection at ~100s (524) while the connector keeps swapping inside the request (no `ignore_user_abort`), so the manager records a *failed* restore that actually (partly) succeeded — and files can land without the DB, leaving **new files + old DB**. **Evidence:** `app/Jobs/RestoreBackup.php:743,760,903-908`; connector `class-backup-endpoint.php:1616`. **Fix:** async job-token handshake (connector runs detached, manager polls status) or a single atomic files+DB transaction on the connector. **Safe-for-live:** connector-version-gated; needs plugin ship + capability read (see B-A2-04).
- **B-A2-02 / Q-A2-02 · P1 · Fix · CONFIRMED** — `recoverStuckRestores()` uses a 30-min `updated_at` silence threshold, but a legitimate restore inside one 1800s HTTP call emits no heartbeat, so a live restore can be marked failed and its site lock `forceRelease`d mid-swap; `forceRelease` is ownership-blind (unlike `failed()`), and a "cancelled" Pending job stays in Redis and later runs. **Evidence:** `app/Dispatchers/BackupDispatcher.php:226-264`. **Fix:** heartbeat during `sendRestoreData`; ownership check before `forceRelease`; actually delete the Redis job on cancel. **Safe-for-live:** manager-only.
- **B-A2-03 · P1 · Harden · CONFIRMED (design gap)** — Crash mid-file-swap: `journal.json` is write-only (no recovery reader), and the 1-hour trash-cleanup cron can delete the only pre-swap copy; catastrophic combined with `restoreAnyway`. **Evidence:** connector `class-backup-endpoint.php:2648-2690`, `simplead-manager-connector.php:316-339`. **Fix:** a recovery reader that replays the journal on next contact; lengthen trash TTL. **Safe-for-live:** connector-side; ship with plugin.
- **B-P1-7 · P1 · Fix** — Selective restore is broken for all v3-zip backups: the browser only knows `files.zip` and the precache dispatch sits in dead code. **Evidence:** `app/Services/Backup/BackupBrowserService.php:56`, `app/Jobs/CreateBackup.php:450`.
- **B-P1-3 · P1 · Harden** — `DiskSpaceGuard` stops all backups with only a `Log::warning`; no alert (`app/Services/.../DiskSpaceGuard.php:42-58`). **B-P1-4 · P1 · Fix** — days-retention deletes chains that still have newer incrementals (orphan cascade) (`RetentionService.php:36-77`). **B-P1-8 · P1 · Harden** — restore *failure* is now audit-logged, but start/success and deletions are not, and no `user_id` persists (see D-A2-02).
- **B-A2-04 · P2 · Harden · verified P2** — Manager sends `file_mode=staged` unconditionally without reading the connector's advertised `staged_restore` capability, so a site on connector <2.15.0 silently falls back to in-place merge — worst exactly when the pre-restore plugin push failed. Downgraded from P1: the fallback is the *legacy* behavior (loss of atomicity, not new corruption). **Evidence:** `RestoreBackup.php:894-901` vs `class-backup-endpoint.php:385`.

### A.3 Plugin & Update Management — status: partial / unsafe (houses the P0)
Scorecard: Correctness 2 · Stability 2 · Scale 2 · Security 3.5. Reconciliation: 1 fixed · 1 partial · 21 still-open of 23.

- **PM-P0-1 · P0 · Fix · CONFIRMED** — `SafeUpdateService` sends `$safeUpdate->slug` where the connector's `validate_plugin_path` requires the plugin file (`akismet/akismet.php`, not `akismet`), so the update is rejected per-plugin; `$updateResult` is never inspected and `UpdateLog` is written `success=true` unconditionally. The only consumer is AI incident remediation → vulnerable sites are recorded as patched when nothing changed. The correct pattern already exists at `PluginManagerService.php:42-58` (it inspects results). **Evidence:** `app/Services/SafeUpdateService.php:63,78-88`. **Impact:** a security remediation that silently no-ops while reporting success — the most dangerous kind of false signal on a security product. **Fix:** send the plugin file; inspect `$updateResult` before `success`; propagate failure into the incident. **Safe-for-live:** manager-only; add a regression test (the current `SafeUpdateServiceTest` encodes the bug as expected behavior — see Testing).
- **PM-P0-2 → P1 · Fix · CONFIRMED, downgraded** — All real update paths run synchronously inside Livewire requests with no health-check/rollback; `RunSafeUpdate` (the designed safety net) is never dispatched (dead code). Downgraded from P0 because bulk paths *do* fire an optional pre-update backup. **Evidence:** `app/Livewire/Updates/UpdatesOverview.php:161,206,217,267`; `app/Jobs/RunSafeUpdate.php:18` (zero dispatch sites). **Fix:** route updates through `RunSafeUpdate` on-queue (backup → update → health check → auto-rollback). **Safe-for-live:** feature-flag the new path per site; keep the old path as fallback during rollout.
- **PM-A2-01 · P1 · Fix · CONFIRMED** — AI incident `rollbackPlugin()` uses `RollbackPoint::find($id)` with no `site_id` scope and no status guard; `$id` comes straight from raw Claude tool output. A hallucinated or manipulated ID downgrades a plugin on **another tenant's** site. **Evidence:** `app/Services/IncidentResponse/IncidentActionExecutor.php:198-213`, id source `AiAgentService.php:160,299`. **Fix:** scope the lookup to the incident's site; verify ownership and status before the connector call. **Safe-for-live:** guard-only.
- **PM-P1-1 · P1 · Fix** — UI rollback lookup on `where('name', $plugin->slug)` vs display-name logs → the recovery button never finds a version (`WithPluginManagement.php:158-160`). **PM-P1-3 · P1** — `updatePluginAcrossSites` checks only `isViewer()`, no per-site `canAccessSite`; lists/stats globally scoped (`UpdatesOverview.php:232-234`). **PM-P1-5 · P1** — 30s update timeout with no `set_time_limit` in the connector → systematic false failures + retry-induced concurrent upgraders (`WordPressApiService.php:78`). **PM-P1-6 · P1** — "backup before updates" is async DB-only; the update proceeds immediately (`WithPluginManagement.php:198-204`). **PM-A2-03 · P1** — no update path acquires `SiteOperationLock`, so an update can run mid-restore/mid-backup on the same site (`SiteOperationLock.php` OPERATION_SAFE_UPDATE constant unused).

### A.4 Security Hardening & Incident Response — status: well-designed, two dead core paths
Scorecard: Correctness 2 · Stability 3 · Scale 3 · Security 3. Reconciliation: 0 fixed · 2 partial · 11 still-open of 14. Positives: no AI delete tool, forced pre-destructive backups, caps/cooldown, last-admin protection, HMAC agent auth.

- **SEC-A2-01 · P1 · Fix · CONFIRMED (code) / inference (live)** — `VulnerabilityCheckService` calls the Wordfence Intelligence per-slug feed with `Http::get` (which does not throw on 404) and returns `[]` on any non-2xx, caching the empty result for 24 h → sites falsely report "no vulnerabilities." The agent observed live 404s (external, unverifiable here); the code defect — treating error as "clean" — is certain. **Evidence:** `app/Services/.../VulnerabilityCheckService.php:133-138`. **Fix:** distinguish "no vulns" from "feed error"; alert on feed failure; never cache an error as clean. **Safe-for-live:** manager-only.
- **SEC-A2-04 · P1 · Fix · CONFIRMED (gated)** — The incident-response path queries `SecurityIssue.status`/`cvss_score`, columns that do not exist (schema has `is_fixed`/`is_ignored`/`software_slug`), throwing SQLSTATE 42703 and crashing the whole path. Gated behind `incident-response.enabled` (default **false**) — latent unless enabled in prod. **Evidence:** `IncidentResponseDispatcher.php:66-67`, `ContextGatherer.php:94-95,103`, schema `pgsql-schema.sql:2556-2572,4371-4388`. **Fix:** correct the column names; add a schema-contract test.
- **SEC-A2-02 · P1 · Harden** — IP-whitelist enforcement can 403 a live site's public front-end; no guardrail (connector `class-security-ip-manager.php:43-48`). **SEC-A2-03 · P1 · Fix** — Viewer authz still missing on Login/Captcha/Scanning mutations, including a custom-login-URL change that can lock out the client (`SecurityLogin.php:83,122`, `SecurityCaptcha.php:84`, `SecurityScanning.php:73-104`). **SEC-A2-11 · P1** — an incident is left non-terminal on worker kill; `failed()` doesn't transition `IncidentResponse` (`RunIncidentResponse.php:63-66`).
- **SEC-A2-08 · P2 · Harden · DOWNGRADED** — Default AI model `claude-sonnet-4-20250514` is hardcoded with no code fallback; env-overridable and gated behind `enabled=false`. Retirement date is external. **Recommendation:** pin to a current model and add a fallback; treat model IDs as config with a health check.

### A.5 Uptime & DNS/SSL — status: partial, silent-failure class untouched
Scorecard: Correctness 3 · Stability 2 · Scale 3 · Security 4. Reconciliation: 0 fixed · 1 partial · 23 still-open of 24.

- **U-P1-1 · P1 · Fix** — Monitor HTTP timeout (5–120s allowed) can exceed the 30s job/worker timeout, so a hung site is never detected down and the state freezes silently. **Evidence:** `app/Jobs/CheckUptime.php:29,102-108`, `config/horizon.php:217`. **Fix:** cap probe timeout below the worker timeout; add a dead-man's switch. **Safe-for-live:** config-only.
- **U-P1-2 · P1 · Fix** — The circuit breaker, opened by unrelated jobs (analytics/SEO/backup), suppresses uptime dispatch; a permanent `is_monitoring_disabled` only logs (`MonitoringDispatcher.php:36-39`). **U-P1-2b · P1** — sites without a `site_health_states` row (e.g. `BulkAddSites`) never get uptime/security checks; the row is created only in `CreateSiteWizard.php:171`. **U-P1-3 · P1** — recovery notified for incidents that were never alerted (no `notified_at` guard) → flapping "recovered" spam + inflated downtime (`NotifySiteRecovered.php:14`). **D-P1-5 · P1** — `dns_get_record()===false` treated as zero records → false "records deleted" alert (`CheckDns.php:119-125`).
- **U-A2-04 · P2 · Fix** — SSL/domain-expiry monitoring is absent despite an `ssl_expires_at` column and a scheduler comment claiming "SSL checks"; domain-registration expiry (whois/RDAP) does not exist at all. **U-A2-01 · P2** — SSRF via monitor URL survives the authz fix (no private-IP blocklist). **U-A2-03 · P2** — 2 uptime workers × 30s blocking probes ≈ 4 checks/min worst case; detection latency collapses under correlated slowness at 100+ sites.

### A.6 Performance (PageSpeed) — status: operationally broken
Scorecard: Correctness 2 · Stability 2 · Scale 2 · Security 3. Reconciliation: 0 fixed · 1 partial · 22 still-open of 23.

- **PF-P1-1 · P1 · Fix** — Scheduled performance tests never dispatch: `routes/console.php` has zero performance references and `MonitoringDispatcher` covers only uptime/security/DNS; `next_test_at` is written and never consumed. All scores in dashboards, the 25/100 health-score component, and monthly client reports come from manual runs, with no staleness alert. **Evidence:** `routes/console.php` (absence), `app/Dispatchers/MonitoringDispatcher.php:24-29`, `RunPerformanceTest.php:278-294`. **Fix:** add a performance dispatcher honoring `next_test_at`; stamp report freshness. **Safe-for-live:** additive scheduler entry; mind PSI quota at 100+ sites (batch/stagger).
- **PF-A2-02 · P2 · Efficiency** — Overview eager-loads full latest-test rows including a base64 `screenshot_final` per monitor, twice → OOM candidate at 100+ sites (`PerformanceOverview.php:41,92`). **PF-P2-4/5 · P2** — PSI errors swallowed (failed runs stamped fresh), no 429 handling; API key leakable via `error_message` into UI/logs. **PF-P2-7/8 · P2** — stuck `running` tests + orphan unique lock + infinite 2s Livewire polling.

### A.7 SEO — status: read path works, write path 100% dead
Scorecard: Correctness 2 · Stability 2 · Scale 2 · Security 2. Reconciliation: 1 fixed · 23 still-open of 24.

- **S-P1-1 · P1 · Fix** — "SEO Fix" + metadata fetch are entirely dead: every call sends the nonexistent `X-SAM-API-Key` header to the HMAC-only connector and errors are swallowed at `Log::debug`. **Evidence:** `app/Jobs/RunSeoAudit.php:46`, `SiteSeoAudit.php:517,633,672,709,750,782` (see ARH-01). **Fix:** route through the signed factory (`ManagesSeo` concern).
- **S-P1-2 · P1 · Fix** — `bulkFix` pushes crawled values as "fixes" (mass noindex→index, a `wp_update_post(post_title)` fallback that overwrites live content), synchronous, no preview/logging — one line away from live once auth is fixed (`SiteSeoAudit.php:462-533`). **Sequence the auth fix (S-P1-1) *after* S-P1-2 is made safe.**
- **S-P1-4 · P1 · Fix · CONFIRMED** — A failed SEO audit re-dispatches every 5 min (`next_audit_at` advanced only on success); `failed()` feeds the shared `CircuitBreakerService`, and after 3 breaks/24h `is_monitoring_disabled` is set, which `BackupDispatcher` honors — **silently stopping that site's backups**. **Evidence:** `SeoAuditDispatcher.php` + `console.php:51`, `CalculateSeoScores.php:64,77`, `CircuitBreakerService.php:77-94`, `BackupDispatcher.php:44-46`. **Fix:** isolate the SEO breaker from monitoring/backup gating. **Safe-for-live:** decouple the shared flag.
- **S-P1-3 · P1** — impossible crawl time budget; retry re-crawls from zero and duplicates `seo_pages` (no unique on `(seo_audit_id,url_hash)`) → corrupted issues/scores (`CrawlSitePages.php:31-37`). **S-P1-5 · P1** — Excel formula injection from crawled title/anchor/alt (PhpSpreadsheet default binder) (`ExcelExportService.php:343+`). **SEO-A2-01 · P1** — SEO crawls (900s×2, clustered at 03:00) share `supervisor-general` with security/reports/default (3 procs); one slow site blocks a worker and starves client reports for hours.

### A.8 Reports & Clients — status: frozen, crashing for SEO sites
Scorecard: Correctness 2 · Stability 3 · Scale 3 · Security 2. Reconciliation: 25 still-open of 25 (0 fixed). **PDF engine verified: Gotenberg 8 (Chromium container), not Browsershot.**

- **R-P1-1 · P1 · Fix · CONFIRMED** — `SeoGatherer` calls an undefined `renderLineChart()` (the service defines `generateLineChartPoints`), so every report for a site with ≥2 SEO audits throws, and the schedule self-deactivates after 3 consecutive failures. Clients may be silently missing reports **now**. **Evidence:** `app/Services/Reports/Sections/SeoGatherer.php:71`, `GenerateReport.php:239-247`. **Fix:** implement/rename the method; add a report smoke test. **Operational:** check prod for schedules already at `consecutive_failures >= 3` (Appendix).
- **R-P1-3 · P1 · Fix · CONFIRMED** — `/r/{report}/{token}` is outside the auth group (throttle only), the token is compared with plain `!==` (not `hash_equals`), there is no expiry/revocation, and the view renders the unpatched-vulnerability inventory + WP-user emails/usernames — permanently. **Evidence:** `routes/web.php:60`, `ReportViewController.php:13`, `client-portal/report.blade.php:347-386,891-923`. **Fix:** signed URL with expiry + revocation; `hash_equals`; redact PII/vuln detail from the public surface. **Safe-for-live:** issue new links; keep old tokens working through a grace window, then expire.
- **R-P1-4 · P1 · Fix · CONFIRMED (missing-authz)** — `ReportSchedule::destroy` unscoped, global `findOrFail` schedule hijack via a client-controllable `editingScheduleId` prop, unauthorized `deleteReport`; the app defines `canDeleteResources()` (Admin-only) but never calls it (`ReportsOverview.php:63-71`, `ReportManagementService.php:44-45`, `WithReportScheduling.php:75-97`). **R-P1-5 · P1 · CONFIRMED** — `ClientProfitability` add/delete cost/revenue methods have zero authorize; a Viewer can mutate client financials (`ClientProfitability.php:70-124`).
- **R-A2-01 · P2** — reports stuck `generating` forever on timeout (`failed()` never marks the row) (`GenerateReport.php:278-285`). **R-A2-03 · P2** — the viewer-authz PRs skipped reports entirely; Viewers can generate/delete/email client reports to arbitrary addresses.

### A.9 Integrations (Cloudflare / GA / GSC / Dropbox) — status: functional, coupled
Scorecard: Correctness 3 · Stability 3 · Scale 4 · Security 3. Reconciliation: 1 fixed · 1 superseded · 11 still-open of 13. Credential storage & OAuth remain solid.

- **I-P1-1 · P1 · Fix** — Google API failures open the **site** circuit breaker, halting uptime + security scans (and, at 3 breaks/24h, permanently disabling monitoring); a later successful GA fetch can close a circuit opened by real downtime. Coupling is wider than the seed knew (SEO jobs too). **Evidence:** `FetchAnalyticsData.php:117`, `MonitoringDispatcher.php:36-54`, `CircuitBreakerService.php:77-94`. **Fix:** per-integration breaker, not a shared site breaker. (Same root cause as S-P1-4.)
- **I-A2-01 · P1 · Fix** — Viewers can rebind/switch/disconnect GA & GSC connections and wipe all cached analytics; the authz PRs used the same pattern for Cloudflare but missed these. **Evidence:** `SiteAnalytics.php:221-228`, `SiteSearchConsole.php:223-230`. **I-P1-3 · P2 · Harden · DOWNGRADED** — `/api/webhooks/inbound` is unauthenticated and writes attacker-controlled `source`+payload to `activity_logs`; impact bounded (JSON/Blade-escaped, 60/min throttle, no priv-gain) → log pollution only (`WebhookController.php:14-27`).
- **I-A2-02 · P2** — Cloudflare purge reports false success + writes bogus audit rows (never checks status) (`CloudflareService.php:451-475`). **I-A2-05 · P2** — `DropboxDriver` has zero 429/5xx retry; one blip aborts a multi-GB backup upload (`DropboxDriver.php:436-458`). **I-A2-06 · P2** — a revoked Google token is permanent silent death; the daily validator filters `is_active=true` so it never re-alerts (`ValidateExternalConnections.php:72`).

### A.10 Notifications — status: fragile, can lose alerts silently
Scorecard: Correctness 2.5 · Stability 2 · Scale 2.5 · Security 3. Reconciliation: 0 fixed · 24 still-open · 1 regressed of 25.

- **N-P1-1 · P1 · Fix** — A dead channel loses alerts silently: the final send failure never throws/fails, escalation filters `status='sent'`, and there is no `NotificationLog` UI (`SendNotificationJob.php:96-100`, `ProcessNotificationEscalations.php:46`). **N-P1-2 · P1** — ack tokens are generated but never delivered in any message → unconditional escalation + undetected A→B/B→A rule cycles (`SendNotificationJob.php:68`, `routes/web.php:243`).
- **N-P1-3 · P1 · Fix · CONFIRMED** — The "Horizon down" alert is a queued `SendNotificationJob` on the `notifications` queue processed by the very Horizon that's down; `Cache::put(…, 3600)` suppresses retries after dispatch though nothing was sent; no external heartbeat exists anywhere in the repo. **Evidence:** `HorizonHealthCheckCommand.php:26-38`, `NotificationService.php:237`. **Fix:** send meta-alerts through a synchronous channel + an external dead-man's switch (healthchecks.io). **Safe-for-live:** additive.
- **N-A2-01 · P1 · Fix · CONFIRMED** — The new critical `restore_failed` notification ("site may be INCONSISTENT") is absent from the subscription UI list and `NotificationTemplate::EVENTS`, so `subscribedTo()` returns false and it is dropped on any channel with explicit subscriptions. **Evidence:** `NotifyRestoreFailed.php:53`, `NotificationTemplate.php:45-64`, `channel-form.blade.php:96-112`. **N-P1-4 · P1 · regressed** — the subscription UI lists 12 events; the app now emits ~26. **Alert-storm:** 50 sites down = 50×N individual critical jobs (batching is info-only), no 429 handling → overflow lost after retries.

### A.11 Public Status Pages — status: partial, sound visitor isolation
Scorecard: Correctness 3 · Stability 3 · Scale 4 · Security 3. Reconciliation: 23 still-open of 23 (module untouched since July). No site-URL leak, no XSS, visitor-side tenant isolation verified sound; DoS bounded by 30/min throttle + 60s cache except the uncached badge.

- **SP-P1-1 · P1 · Fix** — Deleting a user cascades away their status pages + all incident history (`ON DELETE CASCADE`); `deleteUser()` gives no warning. **Evidence:** `pgsql-schema.sql:8362`, `UserManagement.php:98-108`. **Fix:** soft-delete or reassign; confirmation with blast-radius. **Safe-for-live:** migration to relax the FK — expand-contract, no data loss.
- **SP-A2-01 · P2 · Harden** — When the monitoring pipeline is down, the page shows an indefinite stale "All Systems Operational" with no "last updated" rendered, though `UptimeMonitor.last_checked_at` exists. Cheap fix, high trust value (`StatusPageSite.php:59-78`). **SP-A2-03 · P3** — the site picker is unscoped to client; one mis-click attaches Client A's site to Client B's public page.

### A.12 Dashboard & Health Scores — status: rendering solid, scoring broken
Scorecard: Correctness 2 · Stability 4 · Scale 4 · Security 3. Reconciliation: 1 fixed · 2 partial · 16 still-open of 19. Positive: ~18 queries per dashboard render independent of portfolio size (no N+1 verified); no unconditional `wire:poll`; every mutating action individually authorized.

- **D-P1-1 · P1 · Fix** — `sites.health_score` has no writer anywhere, so the Healthy/Warning/Critical filter pills, the Health sort, the `/v1/sites` API, and `HasSiteScopes` all run on NULL. **Evidence:** `DashboardService.php:267-290`, `routes/api.php:28-29`. **Fix:** persist the computed score (a writer job or accessor-backed column). **D-P1-4 · P1** — monthly-snapshot `cloudflare_*`/`seo_*` columns are written by nobody → client reports show N/A monthly (`AggregateMonthlySnapshots.php:39-56`).
- **D-A2-02 · P1 · Harden** — Successful restores, safe-updates, plugin pushes, rollbacks, site delete/rename/bulk-delete produce zero attributable activity-log entries: `ActivityLogger::log()` uses `auth()->id()`, which is null in every queued job (including the new `restoreFailed`). **Evidence:** `ActivityLogger.php:25`, `RestoreBackup.php:203`, `WithBulkSiteActions.php:109-118`. **Fix:** thread the initiating `user_id` into job constructors. **Safe-for-live:** additive.

---

## Part B — Cross-cutting / architecture audit
*(Track 1. System-wide concerns audited separately from the per-module work.)*

### B.1 Auth & multi-tenancy backbone — the isolation model
Scorecard: Correctness 3 · Stability 3 · Scale 4 · Security 2.

**Model as found:** tenancy is per-user ownership (`sites.user_id`) plus an optional client-assignment pivot (`sites.client_id` ↔ `client_user`). Roles: Admin (sees all), Manager (modifies assigned sites), Viewer (read-only by design). There is **no global Eloquent tenancy scope** on `Site` or `Backup`; isolation is enforced per-query and via `WithSiteAuthorization`. The cross-tenant boundary at `mount()` (`authorizeSiteAccess`) is sound; **the gap is role enforcement on write methods** — `authorizeSiteAccess()` gates *visibility* but does not block Viewers, and only `authorizeSiteModification()` does. Note: the deployment appears to be effectively **single-organisation** (one agency, many end-clients), so most "cross-tenant" exposure is really *Viewer→write privilege escalation within the org*; the genuinely cross-owner cases are called out explicitly.

- **ASEC-A2-01 · P1 · Fix · CONFIRMED** — Viewer privilege escalation across **~60 mutating methods in 16 components**; PR #8/#11 applied the guard inconsistently. IDOR via method-arg IDs is *prevented* by site-scoped lookups — the issue is role, not object access. **Evidence (representative):** `WithBackupActions.php:180`, `SiteOverview.php:392`, `SiteCron.php:97`, `SecurityLogin.php:83`. **Fix:** centralize through policies applied uniformly to every destructive action; add a test asserting Viewer is blocked on each. **Safe-for-live:** guard-only, backwards-compatible.
- **ASEC-A2-02 · P1 · Fix · CONFIRMED (genuinely cross-tenant)** — `MaintenancePlans::applyPlanToAll()` queries `Site::where(is_connected)` globally with no `user_id` scope, no admin gate, no Viewer check, while sibling computed properties *do* scope. Any user pushes module/security/tweak config to every client site. **Evidence:** `app/Livewire/MaintenancePlans.php:163-171`, route `routes/web.php:120`. **Fix:** admin-gate the route + scope to owned sites.
- **ASEC-A2-04 · P1 · Harden** — Agent HMAC has no nonce/replay protection and no timestamp window; a captured request can be replayed. **ASEC-A2-05/06 · P1** — MFA enforcement is bypassable via a `livewire*` path exclusion, and Google SSO login skips 2FA entirely. **S-05 · P1 (needs DB confirm)** — `api_key` has an `encrypted` cast but is looked up with a plaintext `WHERE`, strongly implying agent secrets are stored **plaintext at rest** (see Appendix — requires a read-only DB check). **ASEC-A2-08 · P3** — unescaped WP.org changelog via `{!! $changelog !!}` (CSP-mitigated).

### B.2 WP agent ↔ platform protocol
- Canonical transport is `WordPressApiService` (9 `Concerns/` traits) built only via `WordPressApiServiceFactory`, HMAC-signed in `WordPressHttpClient` — **39 consumer files**; backup download/restore correctly go through it. **The last raw, unsigned manager→connector path is the SEO channel** (ARH-01 / S-P1-1): 7 call-sites send a nonexistent `X-SAM-API-Key` header, leaking the decrypted API key and failing 100%. Closing it makes the factory the single transport. Legitimately-unauthenticated raw HTTP to client sites remains for uptime probes, the crawler, favicon fetch, and the post-push opcache flush.
- **Command authorization & rollback:** the connector honors HMAC; incident-response has a deliberately restricted action set (no delete tool, forced pre-destructive backup). But **PM-A2-01** (unscoped `RollbackPoint::find`) and **PM-A2-03** (no `SiteOperationLock` on updates) leave a compromised/hallucinated command path under-guarded. **Protocol versioning is only partial:** `connector_version` and backup capabilities are negotiated and cached, but `staged_restore` is advertised and **read nowhere** (B-A2-04), and non-backup endpoints have zero version/capability gating (stale plugins just 404).

### B.3 Queue & Horizon architecture
Scorecard: Correctness 4 · Stability 3 · Scale 3 · Security 4.

**Topology (verified):** 6 supervisors / 9 queues — `uptime`(2w/30s), `sync`(3w/300s), `backups`(3w/3600s), `notifications`(3w/30s), `general`→[security,performance,reports,default](3w/600s), `incident-response`(2w/900s); 16 workers in a 1024 MB container. **No orphan queues** (all 50 jobs map to a consumed queue). **The suspected backups-vs-notifications starvation does not exist** — separate supervisors. Real contention is elsewhere:

- **Q-A2-01 · P1 · Fix · CONFIRMED** — A SIGKILLed restore is redelivered after `retry_after` (7200s) exactly as the `SiteOperationLock` TTL (7200s) expires, and `handle()` has no `restore_status` guard, so the full restore re-runs on the live site. `tries=4`; `maxExceptions=1` counts nothing on SIGKILL. **Evidence:** `app/Jobs/RestoreBackup.php:88-143`, `config/queue.php:70`, `SiteOperationLock.php:24`. **Fix:** guard at the top of `handle()` (abort if already restored/mid-restore); make `retry_after` ≠ lock TTL. **Safe-for-live:** small, ship on this branch.
- **Q-A2-04 / INF-A2-12 · P1 · Harden · CONFIRMED (escalated)** — Every safety lock (`SiteOperationLock`, unique-job, schedule mutex) is a TTL'd `Cache::add` entry on a Redis with `maxmemory 256mb --maxmemory-policy volatile-lru`. A lock left unread for a whole restore (up to 3600s) is a prime LRU-eviction candidate → **silent loss of the per-site mutex on live data** (two restores, or restore+backup, on one site). This undermines the B-P0-1 guarantee. **Evidence:** `SiteOperationLock.php:42`, `config/cache.php:18`, `docker-compose.prod.yml:285`. **Fix:** move locks to a persistent Redis DB with `noeviction`, or use Redis `SET NX` locks separate from the cache store. **Safe-for-live:** config/infra change; test lock survival under memory pressure.
- **QS-02 · P1** — Horizon-down alert queued through Horizon; scheduler healthcheck is a placebo; no external heartbeat (also N-P1-3). **QS-03 · P1** — zero `runInBackground`: `verify-restore`/`pg_dump`/favicon-backfill run inline in the scheduler and freeze all dispatch ticks for their duration. **Q-A2-05 · P2** — 2 uptime workers saturate right at the 100-site target.

### B.4 External integrations
Covered in A.9. Cross-cutting note: **the shared site circuit breaker couples unrelated subsystems** — a Google/GA failure (I-P1-1) or a failed SEO audit (S-P1-4) can disable a site's uptime *and backups*. This single design decision is the highest-leverage cross-cutting fix: split the breaker per-integration so one dependency's outage can't cascade into stopping backups.

### B.5 Backups subsystem (highest data risk)
Covered in depth in A.2. Cross-cutting verdict: **creation is trustworthy-ish, restore is now sound per-phase but not proven end-to-end, and no restore is ever actually executed as a test.** The three residual structural risks — synchronous transport behind Cloudflare (B-A2-01), zombie redelivery (Q-A2-01), and evictable locks (Q-A2-04) — are all fixable without re-architecture and should land together before the next backup-related deploy.

### B.6 Notifications
Covered in A.10. Cross-cutting verdict: **no delivery guarantee and no meta-observability.** Delivery state is Redis-only (lost on restart), dead channels fail silently, and the watchdog is circular. This is the platform's blind spot: it can stop protecting clients and not tell anyone.

### B.7 Reporting / PDF
Covered in A.8. Engine is **Gotenberg 8** (Chromium), 120s Guzzle timeout per call, cover+body+closing merged via pdfengines. Reliability risk: no per-call retry; reports stuck `generating` on timeout; and the `renderLineChart` crash (R-P1-1) can silently deactivate schedules.

### B.8 Config, secrets & environment
**No default credentials found** in repo/compose/config (pgAdmin removed); `env()` discipline is perfect (zero calls outside config). Open risks: likely-plaintext agent `api_key` (S-05, needs DB check), `.env.example` missing from the repo (blocks onboarding), and a Redis fail-open pattern.

### B.9 Infrastructure, deployment & observability
Scorecard: Correctness 3 · Stability 3 · Scale 3 · Security 4. **Charter alarms re-verified and mostly stale:** Nginx security headers present (full set + HSTS + login rate-limit); no default credentials; health checks on 8/9 services; deploy is a scripted rebuild+recreate (zero `docker cp`).

- **INF-A2-01 / INF-A2-02 · P1 · Fix · CONFIRMED** — Platform self-backup is unsafe: `pg_dump | gzip` under `exec()` (no `pipefail`) makes the pipeline exit status gzip's, masking a `pg_dump` failure; filesize is logged but never gated; retention keeps newest-by-name regardless of validity; and there is **no verified offsite copy** — dumps live on the same disk as the DB. **Evidence:** `DatabaseDumpCommand.php:47-59,111-129`. **Fix:** `set -o pipefail` / check `PIPESTATUS`; validate size + `pg_restore --list`; upload offsite; monthly restore test. **Safe-for-live:** operational, no client impact.
- **INF-A2-03 · P1 · Harden · CONFIRMED** — The `backups` supervisor budget is 3 workers × 1024 MB inside a 1024 MB container also running ~16 workers → OOM SIGKILL mid-backup (which then feeds Q-A2-01). **Evidence:** `config/horizon.php:240,295` vs `docker-compose.prod.yml:87`. **Fix:** lower per-worker memory or raise the container limit; separate the backups container.
- **P2s:** nginx removed early in deploy → a connection-refused outage window (`deploy.sh:64-67` vs `:117`); no docker log rotation; nginx never reloads renewed certs; Redis auth fail-open; flat network + unauthenticated FastCGI; Gotenberg SSRF surface; unpinned `pgbouncer:latest` with no `max_prepared_statements`.

### B.10 Testing & CI
Coverage 2 · Quality 3 · CI 4 · Static 3: 27 files / 189 tests, green in CI; Pint + PHPStan (L5, 336-line baseline) + PHPUnit (pgsql16+redis7, PHP 8.3) all **blocking**. But: **T-A2-01 · P1** — `main` has **no branch protection** (`gh api …/protection` → 404) and `deploy.sh` never checks CI, so red code can ship. **T-A2-02 · P1** — restore/create execution untested e2e; the purpose-built `FakeWordPressApiService` has **zero adopters**. **T-A2-04 · P1** — connector HMAC untested on both ends. **T-A2-05 · P1** — a near-certain `ArgumentCountError` in `GenerateReport::dispatch` (2 of 4–8 args) survived the baseline regeneration (corroborates R-P1-2). Positive: `phpunit.xml`/`bin/test` isolation is exemplary; the memory claim of "~278 Livewire tests" is false — they were deleted in `a9672fb`.

### B.11 Architecture & consistency
Layering 2.5 · Consistency 3 · Dead-code 2.5 · Dependencies 3.

- **ARH-A2-01 · P1 · Harden · CONFIRMED** — Laravel **11.48.0** is past the 11.x security-support window (ended ~2026-03) on a platform holding credentials for 100+ client sites. **Fix:** plan the Laravel 12 upgrade. **Safe-for-live:** staged.
- **ARH-01 · P1 · Fix** — the broken unsigned SEO channel (also S-P1-1); last raw manager→connector path. **ARH-02 · P1 · Harden** — god-object jobs on the destructive path (`CreateBackup` 1,209 / `RestoreBackup` 992 lines; connector backup class 3,154 lines) — PR #15 grew them instead of splitting; extract `Services/Backup/Pipelines/`. **Dead code:** 7 code artifacts (2 jobs incl. `RunSafeUpdate`, 2 services, 2 models, 1 de-facto-dead UI feature) + ~15 orphan legacy-SEO tables (~12% of schema) + the dead `DomainStatus` enum.

---

## Part C — Competitive gap analysis & "wow" opportunities
*(Track 2, kept separate from the correctness audit. Competitor capabilities verified via web research on 2026-07-10; SimpleAd coverage from the module audits.)*

**Positioning:** SimpleAd already covers a **wider** functional surface than any single incumbent — backups, security, uptime, SEO, performance, reports, status pages, and four notification channels. The strategic problem is not breadth; it is that the depth is unreliable (Part A/B). The winning play is therefore **"finish and prove what already exists"** rather than build new categories — with three genuine white-space bets (restore-testing, billing, agentic ops) where every incumbent is weak.

### C.1 Benchmark by capability

| Capability | SimpleAd coverage | WPMU DEV Hub | ManageWP | WP Umbrella | The "wow" to build |
|---|:--:|---|---|---|---|
| Bulk operations at scale | **Partial** | Full | Full (1M+ Workers) | Full | Canary/staged rollout with per-site backup + auto-halt on failure — none do staged canary |
| **Safe updates (auto-rollback)** | **Partial (dead)** | Partial (manual rollback via email) | Partial (screenshot, manual) | **Full (visual regression + auto-rollback)** | Wire the *existing* `RunSafeUpdate` + visual regression → match Umbrella, beat WPMU/ManageWP |
| Uptime & health depth + SLA | **Partial** | Basic (single US region, email-only) | Mid (60s, email/SMS/Slack) | Mature (5 regions, 1–60min) | Multi-location (unused `check_locations`) + true SLA dashboard — nobody has an SLA-target engine |
| **Backup UX (incremental, 1-click restore, retention, restore testing)** | **Partial** | Mature (hosting) | Mature | Mature (50-day fixed) | **Automated restore-testing to a sandbox — no incumbent does this**; plus configurable retention (Umbrella can't) |
| Security (malware/vuln scan, firewall, hardening, audit log) | **Partial (vuln feed dead)** | Mature (Defender) | Detect-only + Patchstack | Maturing (Patchstack) | Fix the feed first; then a *Global Protection Score* across the fleet (Umbrella shipped one Feb 2026 — parity target) |
| Performance (Lighthouse trends + recommendations) | **Partial (doesn't run)** | Moderate (Hummingbird) | **Dated (pre-Lighthouse)** | Solid (CWV monitoring, no advice) | Turn scheduling on + an *actionable* recommendation engine — the whole field only monitors |
| **Client-facing (white-label reports, portal, billing, status pages)** | **Partial** | **Mature (incl. Stripe billing)** | Mature (reports) | Reports mature, no billing/status | **Client billing/subscriptions** (only WPMU has it) + status pages (SimpleAd already has, most don't) = a full agency-in-a-box |
| Team & collaboration (roles, 2FA, activity log) | **Partial** | Mature (granular roles) | Solid (2 roles) | Mature (RBAC, TOTP) | Fix the ~60 authz gaps + real 2FA enforcement → then granular per-area roles |
| SEO / broken-link / analytics | **Partial (write path dead)** | Moderate (SmartCrawl) | Basic (rank + links) | New (link + redirect mgmt) | Fix the SEO write path; broken-link + redirect management to match Umbrella |
| Automation, scheduling & alerting integrations | **Partial** | **Weak (no Slack/webhook/API)** | **Weak (email/SMS/Slack only)** | Good (API, webhooks, Claude skill) | SimpleAd already has 4 channels + could expose a trigger API — a clear win vs WPMU/ManageWP |
| Onboarding & agent-install UX | **Partial** | Mature (creds → remote install) | **Excellent (Worker)** | Simple (key paste) | Bulk-connect flow (nobody documents one) + one-plugin agent (WPMU needs 5+) |
| Billing / subscriptions | **None** | Full | None | None | Greenfield revenue loop; `client_costs`/`client_revenues` already model the data |

### C.2 The three white-space bets

1. **"Proven restore" (restore-testing).** No incumbent restores a backup to a throwaway sandbox to verify it. SimpleAd's staged-restore machinery (connector 2.15.0) is 80% of the way there. Marketed as *"every backup is restore-tested automatically,"* this converts the audit's biggest liability (unproven restore) into the headline feature. **Precondition:** close B-A2-01/Q-A2-01/Q-A2-04 first.
2. **Agency-in-a-box (billing + white-label portal + status pages).** WPMU is the only competitor with integrated billing; none pair it with public status pages, which SimpleAd already has. Adding Stripe/Cashier subscriptions on top of the existing client portal makes SimpleAd the only tool an agency needs. **Precondition:** fix the public-link PII leak (R-P1-3) and portal defaults (R-A2-02) before scaling client-facing surfaces.
3. **Agentic operations.** WP Umbrella already shipped a Claude Code skill (Apr 2026); SimpleAd already runs AI incident-response. Finishing that (fix PM-P0-1, PM-A2-01, the retired model) and exposing a safe trigger API positions SimpleAd on the same frontier — with the safety model (forced backups, no delete tool) already designed.

**What to *not* chase:** raw bulk-update speed (ManageWP's 1M-Worker scale is unbeatable and not where the margin is) and hosting (WPMU/ManageWP bundle it; SimpleAd is host-agnostic, which is a feature, not a gap).

---

## Part D — Roadmap
*(Sequenced by real dependency and blast radius, not dogmatically by phase. Each phase lists goal, items, why-now, dependencies, client-safety, rough effort.)*

### Phase 1 — Stop the bleeding (P0 + destructive-path P1s) — ~1–2 weeks
**Goal:** no destructive action can run unauthorized, cross-tenant, falsely-successful, or twice. **Why now:** these are the findings that corrupt data, leak PII, or lie about security on live client sites.
- **Items:** A1 (fix SafeUpdate false-success, PM-P0-1) · A2 (authorize + scope backup delete, B-P1-5) · A3 (admin-gate + scope MaintenancePlans, ASEC-A2-02) · A4 (scope AI rollback to incident site, PM-A2-01) · A5 (restore-status guard + `retry_after`≠lock-TTL, Q-A2-01) · A6 (move locks off evictable cache Redis, Q-A2-04) · A7 (redact + expire public report link, R-P1-3) · A8 (fix `renderLineChart` crash, R-P1-1) · A9 (finish the Viewer-authz sweep across the ~60 methods, ASEC-A2-01).
- **Dependencies:** A5+A6 should land with, or right after, PR #15 (they harden the restore it introduces). A9 is mechanical and unblocks closing many module-local Viewer findings at once.
- **Client-safety:** all are guard/redaction/config changes — backwards-compatible, no data migration except A7's link expiry (grace window). Add a regression test per fix (several current tests encode the bug as correct — see Testing).
- **Effort:** mostly S, A9 is M.

### Phase 2 — Restore the safety nets & stop the silence — ~2–4 weeks
**Goal:** the safety features that exist actually run, and the platform tells someone when it can't. **Why now:** these are the "designed-but-dead" and "silent-failure" classes — they don't corrupt data today but they make the product untrustworthy and hide the Phase-1 class from recurring.
- **Items:** external heartbeat + synchronous meta-alert channel (N-P1-3/QS-02) · fix the vulnerability feed error-handling (SEC-A2-01) · split the shared site circuit breaker per-integration so SEO/GA failures can't stop backups (S-P1-4/I-P1-1) · wire `RunSafeUpdate` (backup→update→health→rollback) behind a per-site flag (PM-P0-2) · turn on scheduled performance runs (PF-P1-1) · add the `restore_failed` + ~14 missing events to the notification catalog (N-A2-01/N-P1-4) · persist `sites.health_score` (D-P1-1) · thread `user_id` into destructive-job audit logs (D-A2-02) · fix self-backup pipefail + offsite + restore test (INF-A2-01/02) · Horizon backup-worker OOM budget (INF-A2-03) · async restore transport to survive Cloudflare (B-A2-01).
- **Dependencies:** the per-integration breaker unblocks trustworthy uptime/backup scheduling; async restore transport (B-A2-01) pairs with the connector plugin's next version.
- **Client-safety:** additive schedulers, config, and connector changes; feature-flag the safe-update path; mind PSI/GA quotas at 100+ sites.
- **Effort:** M–L.

### Phase 3 — Harden, scale & test the destructive paths — ~1–2 months
**Goal:** the platform is safe to run bulk operations across 100+ sites and regressions are caught before deploy. **Why now:** depends on Phases 1–2 being green.
- **Items:** branch protection on `main` + CI gate in `deploy.sh` (T-A2-01) · e2e restore/create tests adopting the existing `FakeWordPressApiService` (T-A2-02) · connector HMAC + nonce/replay protection tests (T-A2-04/ASEC-A2-04) · decompose the backup/restore god objects into `Services/Backup/Pipelines/` (ARH-02) · fix agent-auth `api_key_hash` + encrypt-at-rest verification (SC-A2-03/S-05) · SSL + domain-expiry monitoring (U-A2-04) · retention/indexing on hot tables + drop the ~15 orphan SEO tables (ARH dead-code) · uptime worker scaling + multi-location (U-A2-03) · plan the Laravel 12 upgrade (ARH-A2-01).
- **Client-safety:** the god-object refactor is the riskiest — do it behind the new e2e tests; orphan-table drops are expand-contract with a verification pass.
- **Effort:** L–XL.

### Phase 4 — Wow / differentiate — ongoing
**Goal:** convert the now-solid foundation into the three white-space bets (Part C.2): automated restore-testing, agency-in-a-box (billing + portal + status pages), and agentic ops. **Precondition:** Phases 1–3; restore-testing specifically needs B-A2-01/Q-A2-01/Q-A2-04 closed first.

---

## Part E — To-do backlog
*(Flat, prioritized. P0 first. Each: ID · title · Type · Module · Priority · Effort · Risk-to-live · Deps · acceptance criteria · safe-rollout. "Old ID" cross-references the finding IDs used in Parts A/B and the 2026-07-02 audit.)*

### P0
| ID | Title | Type | Module | Effort | Risk | Deps | Acceptance criteria | Safe-rollout |
|---|---|---|---|:--:|:--:|---|---|---|
| E-01 | Fix SafeUpdate false-success (send plugin file, inspect result) | Fix | Plugin Mgmt | S | Low | — | `SafeUpdateService` sends the plugin file; `success` reflects the connector's real result; incident records failure on failure; regression test replaces the tautological `SafeUpdateServiceTest` | Manager-only; deploy behind the existing incident-response gate |

### P1 — destructive / data-integrity / leak (do in Phase 1)
| ID | Title | Type | Module | Effort | Risk | Deps | Acceptance / safe-rollout |
|---|---|---|---|:--:|:--:|---|---|
| E-02 | Authorize + tenant-scope backup delete/bulk-delete (old B-P1-5) | Fix | Backups | S | Low | — | Viewer blocked; lookup scoped to owned sites; incremental-chain guard; test. Guard-only |
| E-03 | Admin-gate + scope `MaintenancePlans::applyPlanToAll` (old ASEC-A2-02) | Fix | App-sec | S | Low | — | Route admin-only; query scoped to owned sites; test. Guard-only |
| E-04 | Scope AI `rollbackPlugin` to the incident's site + status guard (old PM-A2-01) | Fix | Plugin/IR | S | Low | — | Lookup scoped to `incident->site`; ownership + status checked before connector call; test |
| E-05 | Restore-status guard in `handle()` + `retry_after`≠lock-TTL (old Q-A2-01) | Fix | Queues/Backups | S | Med | PR #15 | A redelivered/killed restore aborts if already restored/mid-restore; e2e test simulating SIGKILL redelivery |
| E-06 | Move safety locks off the evictable cache Redis (old Q-A2-04/INF-A2-12) | Harden | Infra/Backups | M | Med | — | Locks on a `noeviction` store or Redis `SET NX`; test lock survival under memory pressure |
| E-07 | Redact PII/vuln detail + expire/revoke public report link + `hash_equals` (old R-P1-3) | Fix | Reports | M | Low | — | Signed link with expiry+revocation; PII/vuln redacted from public surface; old tokens grace-expired |
| E-08 | Fix `renderLineChart` report crash + reactivate deactivated schedules (old R-P1-1) | Fix | Reports | S | Low | — | Reports render for SEO sites; report smoke test; audit prod for `consecutive_failures>=3` schedules |
| E-09 | Complete the Viewer-authz sweep across ~60 mutating methods via policies (old ASEC-A2-01) | Fix | App-sec | M | Low | — | Every destructive Livewire method authorizes; policy-level test asserts Viewer blocked per action |
| E-10 | Guard SEO write path safety before enabling auth (old S-P1-2, sequence before S-P1-1) | Fix | SEO | M | Med | — | `bulkFix` cannot mass-noindex→index or overwrite `post_title`; preview + logging; then fix auth |

### P1 — safety-net / observability (Phase 2)
| ID | Title | Type | Module | Effort | Risk | Deps |
|---|---|---|---|:--:|:--:|---|
| E-11 | External heartbeat + synchronous meta-alert channel (old N-P1-3/QS-02) | Harden | Notif/Queues | S | Low | — |
| E-12 | Vulnerability feed: distinguish error from "clean"; alert on feed failure (old SEC-A2-01) | Fix | Security | S | Low | — |
| E-13 | Split the shared site circuit breaker per-integration (old S-P1-4/I-P1-1) | Fix | Integrations/SEO | M | Med | — |
| E-14 | Wire `RunSafeUpdate` (backup→update→health→rollback) behind a per-site flag (old PM-P0-2) | Fix | Plugin Mgmt | L | Med | E-01 |
| E-15 | Turn on scheduled performance runs honoring `next_test_at` (old PF-P1-1) | Fix | Performance | S | Low | — |
| E-16 | Add `restore_failed` + missing ~14 events to notification catalog (old N-A2-01/N-P1-4) | Fix | Notifications | S | Low | — |
| E-17 | Persist `sites.health_score` (writer job/accessor) (old D-P1-1) | Fix | Dashboard | M | Low | — |
| E-18 | Thread initiating `user_id` into destructive-job audit logs (old D-A2-02) | Harden | Dashboard | M | Low | — |
| E-19 | Self-backup: pipefail + size/`pg_restore --list` validation + offsite + monthly restore test (old INF-A2-01/02) | Fix | Infra | M | Low | — |
| E-20 | Horizon backup-worker OOM budget vs container limit (old INF-A2-03) | Harden | Infra | S | Med | — |
| E-21 | Async restore transport (job-token handshake) to survive Cloudflare 100s cutoff (old B-A2-01) | Harden | Backups/Connector | L | Med | connector ship |
| E-22 | Fix MFA `livewire*` bypass + enforce 2FA on Google SSO (old ASEC-A2-05/06) | Fix | App-sec | M | Low | — |
| E-23 | Recover-stuck-restore: heartbeat-aware detection + ownership-checked lock release (old B-A2-02/Q-A2-02) | Fix | Backups | M | Med | E-05 |

### P1 — module-local (Phase 2/3; abbreviated — full detail in Part A)
E-24 fix v3 selective restore (B-P1-7) · E-25 DiskSpaceGuard alert not just log (B-P1-3) · E-26 retention incremental-chain guard (B-P1-4) · E-27 uptime probe timeout < worker timeout + dead-man's switch (U-P1-1) · E-28 sites without `site_health_states` never monitored (U-P1-2b) · E-29 recovery-notified-without-alert (U-P1-3) · E-30 DNS false "deleted" alert (D-P1-5) · E-31 Viewer can rebind GA/GSC (I-A2-01) · E-32 dead notification channel loses alerts silently (N-P1-1) · E-33 ack tokens never delivered (N-P1-2) · E-34 fix SEO write-path auth via signed factory (S-P1-1/ARH-01, after E-10) · E-35 SEO crawl dup `seo_pages` + unique index (S-P1-3) · E-36 Excel formula injection (S-P1-5) · E-37 report IDOR/authz trio (R-P1-4) · E-38 Viewer edits client financials (R-P1-5) · E-39 status-page stale "All Operational" + last-updated (SP-A2-01) · E-40 user-delete cascades status pages/incidents (SP-P1-1) · E-41 Sites `SiteOverview` Viewer WP-admin login/disconnect (SC-A2-01) · E-42 agent-auth `api_key_hash` + at-rest encryption (SC-A2-03/S-05) · E-43 IP-whitelist can 403 live front-end (SEC-A2-02) · E-44 Viewer authz on Login/Captcha/Scanning (SEC-A2-03) · E-45 monthly-snapshot cloudflare/seo columns unwritten (D-P1-4) · E-46 update path acquires SiteOperationLock (PM-A2-03) · E-47 update-call timeout + connector `set_time_limit` (PM-P1-5).

### P2/P3 (Phase 3+; representative — see Parts A/B)
Testing: E-48 branch protection + CI gate in deploy (T-A2-01) · E-49 e2e restore/create tests adopting the connector fake (T-A2-02) · E-50 connector HMAC + replay tests (T-A2-04/ASEC-A2-04). Architecture: E-51 decompose backup/restore god objects (ARH-02) · E-52 read `staged_restore` capability before choosing file_mode (B-A2-04) · E-53 drop ~15 orphan SEO tables + dead `DomainStatus`/`RunSafeUpdate` (dead-code) · E-54 Laravel 12 upgrade (ARH-A2-01). Infra: E-55 deploy nginx-first-removal outage window · E-56 docker log rotation · E-57 nginx cert reload · E-58 `.env.example` in repo. Integrations: E-59 Cloudflare purge false-success (I-A2-02) · E-60 Dropbox 429/5xx retry (I-A2-05) · E-61 revoked-Google-token re-alert (I-A2-06). Performance: E-62 overview screenshot OOM (PF-A2-02) · E-63 PSI 429 + key-leak-in-error (PF-P2-4/5). Plus the remaining P2/P3 in the module reports.

---

## Appendix — unverified items, assumptions, and open questions

**Requires a read-only production check (could not be confirmed from code alone):**
1. **Are report schedules already silently deactivated?** R-P1-1 deactivates a schedule after 3 consecutive failures, and the crash it causes is live for any SEO-enabled site. Check `report_schedules` for `consecutive_failures >= 3` / `is_active=false` — clients may be missing reports **now**.
2. **Is `incident-response.enabled` true in prod?** It gates SEC-A2-04 (the 42703 column crash). If true, the incident-response scheduler is actively crashing; if false, the bug is latent.
3. **Are agent `api_key`s plaintext at rest?** The `encrypted` cast vs plaintext `WHERE` lookup (S-05) strongly implies plaintext storage, but confirming needs a `SELECT api_key FROM sites LIMIT 1` on a non-production copy.
4. **Is the vulnerability feed actually 404ing?** SEC-A2-01's code defect (error treated as "clean") is certain; the "every plugin 404s" observation is external and time-sensitive — verify against the live Wordfence Intelligence endpoint.
5. **Is the default AI incident model still served?** SEC-A2-08 hardcodes `claude-sonnet-4-20250514`; whether it still resolves is an external fact.

**Could not be exercised read-only (follow-up, not a guess):**
- **Does a real restore actually work end-to-end?** The staged design is sound on inspection, but no restore is executed anywhere (not even in tests). A sandbox restore of a real client backup is the only way to confirm — and is itself the seed of the "restore-testing" wow feature.
- **Connector plugin behavior on old versions** against the new manager expectations (B-A2-04) needs a live stale-plugin site to observe the in-place-merge fallback.

**Assumptions made:**
- Scope is the `fix/atomic-restore` working tree; **PR #15 is in review, not deployed** — findings that depend on connector 2.15.0 (staged restore, B-A2-01/02/03, B-A2-04) describe code that is not yet live in the fleet.
- The deployment is treated as **single-organisation** (one agency, many end-clients) based on the tenancy model; if multiple independent agencies share the instance, several "Viewer→write" findings escalate to true cross-tenant severity.
- Competitor capabilities and pricing are as published on 2026-07-10; framework/library EOL dates are from auditor knowledge and should be confirmed against upstream.
- Severity reflects blast radius to live client sites per the charter's §6 framework; findings touching data integrity, tenant isolation, backups/restore, or the agent's ability to alter a client site were defaulted to elevated priority.
