# SimpleAd Manager — Full-Application Audit, Roadmap & Backlog
**2026-07-11 · read-only analysis pass · supersedes the 2026-07-10 audit (removed; history in git)**

Generated via an exhaustive multi-agent pass: a current-state map, 25 module/cross-cutting deep audits, adversarial verification of every Critical/High finding, and competitor research across the WordPress-management landscape. **265 findings** total; **45 Critical/High** survived adversarial verification (35 confirmed, 10 downgraded, 0 refuted). All claims cite `file:line`; the codebase is the source of truth. Read-only — no code, data, or deploy was touched.

## Post-audit decisions (owner, 2026-07-11)

These decisions were taken after the audit and OVERRIDE the roadmap/wow recommendations where they conflict. Competitor *facts* below are left intact; only SimpleAd's own recommendations are re-scoped.

1. **No billing / subscriptions.** SimpleAd is an **internal tool** — the agency uses it to monitor its own clients, not a product sold per-seat. All Stripe/billing/monetization items are **dropped** (Future Module #5, roadmap Phase 2 item 4, exec-summary wow #2). "Client-facing" shrinks to **flawless white-label reports + status pages** — the surfaces clients actually receive — with no client login/checkout.
2. **Backups — option B chosen: fix the existing incremental logic AND build the WP-CLI fast path.** Constraint: **no SSH to all clients**, and `shell_exec` is disabled on many target hosts. Therefore WP-CLI is **opportunistic, not universal** — the connector detects at runtime whether the host allows running WP-CLI (`proc_open`/`shell_exec` + a `wp-cli.phar`), uses it as the fast path where available, and falls back to the hardened pure-PHP/REST chunked path everywhere else. The hosting fleet is **mixed** (a meaningful subset has shell), so both paths get built fully with capability detection reported back per-site. See the revised Future Module #2.
3. **Malware scanning — still pending.** Build-a-scanner vs. integrate-a-feed (Patchstack/WPScan → virtual patching) remains an open decision; virtual patching is the cheaper recommended direction but not yet chosen.

---

## Table of Contents
- [Executive Summary](#executive-summary)
- [Part A — Per-module correctness & stability audit](#part-a--per-module-correctness--stability-audit)
- [Part B — Cross-cutting / architecture audit](#part-b--cross-cutting--architecture-audit)
- [Part C — Competitive gap analysis & wow opportunities](#part-c--competitive-gap-analysis--wow-opportunities)
- [Future modules](#future-modules)
- [Part D & E — Roadmap and backlog](#part-d--e--roadmap-and-backlog)
- [Appendix](#appendix)

---

## Executive Summary

### Executive Summary — SimpleAd Manager Full Audit (2026-07-11)

## Verdict

SimpleAd Manager is a genuinely capable production SaaS: all 25 audited modules are implemented end-to-end, the backup/restore subsystem has been meaningfully hardened (staged atomic swap, integrity verification, restore testing), and recent remediation sweeps (PRs #14–#52) fixed real problems. However, the app's dominant failure mode is **silent degradation** — transient errors permanently disable monitoring, syncs, and backups with no alert (is_connected trap, Google token deactivation, GET-signing bug killing error logs fleet-wide, db:dump false success) — which is the most dangerous posture possible for a tool clients pay to *watch* their sites. Second, the authorization model is inconsistent: the write path is well-guarded, but confirmed gaps allow a read-only Viewer to mint WP-admin logins, read connector secrets, and push config to arbitrary tenants' sites. Third, several safety guarantees the product advertises are not actually enforced — the global Updates page bypasses safe updates, the pre-update backup can silently skip, and retention can destroy in-window restore points. The core is sound; the gap between *promised* safety and *enforced* safety is where existing clients are exposed today.

## Health Scorecard

| Module | Correctness | Stability | Scale | Security |
|---|---|---|---|---|
| Sites & Dashboard | 3 | 3 | 4 | 2 |
| Uptime monitoring | 3 | 3 | 3 | 3 |
| Performance (Lighthouse/PSI) | 3 | 3 | 3 | 4 |
| SEO audit + broken links | 2 | 3 | 3 | 2 |
| Security & Tweaks hub | 3 | 3 | 4 | 3 |
| Updates + SafeUpdate | 3 | 3 | 3 | 3 |
| Backups — site backups | 3 | 3 | 3 | 4 |
| Backups — restore + app backups | 3 | 3 | 3 | 4 |
| Reports (HTML/PDF) | 3 | 3 | 4 | 3 |
| Maintenance Plans + modules | 3 | 3 | 3 | 2 |
| Clients + client portal | 4 | 4 | 4 | 4 |
| DNS + domain expiry | 3 | 3 | 4 | 4 |
| Error logs + PHP error logs | 3 | 4 | 3 | 3 |
| Activity + audit logs | 3 | 3 | 4 | 3 |
| Notifications + escalations | 3 | 4 | 4 | 3 |
| Google integrations (GA4/GSC) | 3 | 3 | 4 | 3 |
| Cloudflare | 3 | 3 | 4 | 3 |
| Incident Response (AI) | 3 | 3 | 4 | 3 |
| Auth & multi-tenancy | 3 | 4 | 3 | 2 |
| Connector protocol + rollback | 3 | 3 | 3 | 3 |
| Horizon / queues / scheduler | 4 | 3 | 3 | 5 |
| Backup/restore subsystem integrity | 3 | 3 | 3 | 4 |
| External integrations resilience | 3 | 3 | 3 | 4 |
| Config, secrets, Docker, deploy | 4 | 3 | 3 | 4 |
| Observability + self-protection | 3 | 4 | 4 | 4 |

**Overall application health: 6.5 / 10.** Averages: Correctness 3.1, Stability 3.2, Scale 3.5, Security 3.4. Feature completeness and architecture would score higher (~8); the score is dragged down by the silent-failure posture, the authz gaps concentrated in Sites, SEO, Maintenance Plans and multi-tenancy (all scoring Security 2), and unenforced safety invariants in the backup/update pipelines.

## Top 5 Risks to Existing Clients

1. **A client site can lose all restorable backups without anyone noticing.** Chain-aware retention deletes the base full of a still-valid incremental chain, then `cleanupOrphans` cascades and destroys the in-window incrementals (`RetentionService`, both count- and days-mode — two independently confirmed High findings). Compounded by zero-change daily incrementals failing verification outright. Runs after *every* backup finalize. Interim mitigation: disable `incremental_frequency` or switch affected sites to high count-based retention until the fix ships.

2. **A live restore can be killed mid-flight and the site left half-restored.** `BackupDispatcher::recoverStuckRestores` blind-force-releases the site lock after 30 minutes of silence — inside the restore's legitimate 30-minute silent HTTP window — letting a backup start against a half-restored site; a separately confirmed redelivery-guard hole then silently re-runs the killed restore in full ~2 hours later. Both contradict the PR #38 recovery design that already exists (`RecoverStuckRestores`, 75-min + ownership-checked).

3. **Updates run against client sites with no safety net despite "safe updates" being on.** The global `/updates` page (single, per-site, and fleet-wide plugin update) completely bypasses `safe_updates_enabled` and takes no pre-update backup; even on the safe path, the safety backup silently skips under lock contention (`dispatchSync` + `release()` no-op) and `RunSafeUpdate` never acquires the SiteOperationLock — so a safe update can run concurrently with a restore. Separately, the connector's `.htaccess` multi-setting rollback restores a *partially-modified* file, which can leave a client site 500ing until manual repair.

4. **Tenant isolation and role boundaries are broken in confirmed, exploitable ways.** A read-only Viewer can mint WP-admin auto-login URLs and switch the impersonated admin (`WithWpAdminLogin`), read decrypted connector API secrets (Connect Plugin modal), and the SEO page renders the decrypted `api_key` into HTML three times; `CopySettingsModal::apply()` is a cross-tenant write IDOR pushing security/tweak config to arbitrary site IDs; the global dashboard shows every tenant's sites to any Manager/Viewer. All fixes are additive guards, shippable immediately.

5. **The product silently stops watching sites — and the platform silently stops backing itself up.** One transient sync failure permanently flips `is_connected=false`, halting backups, security scans, performance tests and sync with no notification and no reconnect probe; E-27 (uptime timeout blackout) and E-30 (DNS false "records deleted" alerts flowing into client reports) remain open from the prior audit; a GET-signing HMAC bug has broken error-log ingestion fleet-wide (2,176 production 401s over 30 days); Google connections permanently self-deactivate on a transient 429. Meanwhile `db:dump` reports success when `pg_dump` fails (pipeline exit-code bug) and app self-backups may never leave the host — the platform's own disaster recovery is unproven.

## Top 5 "Wow" Opportunities

1. **Safe Updates v2 + disposable staging** — before/after screenshots with pixel-diff thresholds, hold-for-approval with side-by-side diff UI, pre/post Lighthouse deltas (the performance pipeline already exists), and one-click staging built on the existing backup+restore machinery: restore to a sandboxed subdomain, run the safe update there, promote. Merging staging with safe updates is beyond anything WP Umbrella, ManageWP or WPMU DEV ship.

2. **Backup engine overhaul — hardened incrementals + opportunistic WP-CLI fast path** *(chosen 2026-07-11)* — fix the broken incremental chain logic (P0-03/04), then make backups fast and robust: connector-reported file/table deltas on the existing S3+Dropbox pipeline, plus an opportunistic WP-CLI path the connector uses only where the host allows it (no SSH needed), with automatic fallback to the pure-PHP path. Matches WP Umbrella/BlogVault on speed and restore-point density while running on *any* host. *(Billing was considered and dropped — internal tool.)*

3. **Virtual patching + fleet-orchestrated edge WAF** — match the plugin/theme/core inventory SimpleAd already collects against a Patchstack/WPScan CVE feed, then push per-vulnerability virtual patches, geoblocking and bot rules to Cloudflare per site (integration already exists), with a connector fallback ruleset. "Exposed vs patched vs virtually-patched" per client, without building a malware scanner.

4. **"Last proven-restorable backup: X hours ago" as a marketable guarantee** — make the existing restore-testing module continuous (auto-test every Nth backup in an ephemeral container, HTTP + screenshot + Lighthouse diff), add forever-incremental deltas reusing the integrity-scan hashes, and surface the verified-restore-point badge in client reports and status pages. No competitor offers a restorability guarantee. (Prerequisite: fix the retention findings in Risk #1 first.)

5. **Monitoring as a client-visible asset: multi-region confirm-before-alert + SLA contracts + subscriber status pages** — 2-of-3 second-region probe confirmation to kill false positives, per-client SLA targets with automatic monthly attainment statements and pre-emptive breach alerts ("12 more minutes of downtime misses 99.9%") through the existing report pipeline, plus email/RSS/webhook subscribers and AI-drafted public post-mortems (from the existing AI incident-response module) on status pages — a WP-space first.

---

## Part A — Per-module correctness & stability audit
*Track 1 — Correctness & Stability. One subsection per module.*

## Sites & Dashboard

**Footprint.** `app/Livewire/Dashboard/GlobalDashboard`, `app/Livewire/Sites/{SitesList,CreateSiteWizard,BulkSettings}`, `app/Livewire/Sites/Detail/{SiteOverview,SiteSettings,ManageSiteTags,SiteTodoFeed}`, `app/Livewire/Components/{SiteCard,GlobalSearch}`, `app/Services/{DashboardService,HealthScoreService}`, jobs `SyncWordPressSite`, `FetchSiteFavicon`, `RecordHealthScores`; tables `sites`, `site_plugins`, `site_themes`, `site_users`, `health_score_history`, `site_statuses`, `tags`.

**Status: Complete** — the live surface works and is well cached/eager-loaded, but two of its components are dead code and three authorization gaps cluster here. All findings below are VERIFIED in code unless marked otherwise.

### Findings (most severe first)

- **[High / Fix] Viewer can mint WP-admin auto-login URLs.** `WithWpAdminLogin::openWpAdmin()` and `setWpAdminUser()` (`app/Livewire/Traits/WithWpAdminLogin.php:12,30`) have no guard; the host components' `mount()` checks only *view* access (`SiteOverview.php:41`). A read-only Viewer or client-assigned user can obtain a one-time administrator login URL to a client's production WordPress and choose which admin to impersonate. *Safe-for-live:* add `authorizeSiteModification()` — pure additive guard, ship immediately.

- **[High / Fix] Transient sync failure silently stops backups.** `SyncWordPressSite` sets `is_connected=false` on any exception (`app/Jobs/SyncWordPressSite.php:246-254`; tries=3, backoff 30/60/120s). Every scheduled pipeline requires `is_connected=true` — backups (`BackupDispatcher.php:43`), WP sync (`DataSyncDispatcher.php:86-97`), performance/security (`MonitoringDispatcher.php:48,81`), health scores (`RecordHealthScores.php:40`). Nothing ever re-probes a disconnected site (`CircuitBreakerService::checkHalfOpen`, :134-139, only flips circuit state) and no disconnect notification exists. ~4 minutes of unreachability → silent, permanent loss of backup coverage until a human clicks "Sync now". Only uptime checks survive (no `is_connected` filter). *Safe-for-live:* additive reconnect-probe job + disconnect notification + dashboard tile; mark disconnected only in `failed()`.

- **[High / Fix] Connector API key+secret exposed to read-only users.** `SiteOverview::openConnectModal()` (`SiteOverview.php:344-356`) has no modification guard and copies the decrypted `api_key`/`api_secret` (encrypted casts, `Site.php:193-194`) into public Livewire properties sent to the browser. Durable credentials that authorize full connector actions on the client site. *Safe-for-live:* guard + mask; never round-trip the secret.

- **[Medium / Fix] "Rotate API keys" is broken.** `SiteOverview.php:407` reads undefined `$this->apiFactory` (property exists only on services); the `catch (\Throwable)` at :419 turns it into a misleading "Key rotation failed" toast. A security control that has never worked in this path. Runtime exception type is INFERENCE (Livewire `__get`); the failure itself is certain. *Safe-for-live:* one-line fix to `app(WordPressApiServiceFactory::class)`; verify connector rotate semantics on a low-value site first.

- **[Medium / Fix] Viewer can create sites and clients.** `CreateSiteWizard::createSite()/createClient()` (`CreateSiteWizard.php:156,139`) perform no role check; route has only `auth` middleware (`web.php:79`); `SitePolicy::create` exists but is never invoked. Site creation spawns monitors/jobs via plan application (`Site.php:228-241`). Gap in the PR #19 viewer sweep. *Safe-for-live:* additive `authorize('create', ...)`.

- **[Medium / Fix] Three conflicting health-threshold systems.** Filters hardcode 90/70 (`DashboardService.php:267-276,409`; `SitesList.php:51-54`) while the `HealthLevel` enum is 75/50 (`HealthLevel.php:14-16`) — used by the SiteCard badge (`SiteCard.php:17`), the donut chart (`DashboardService.php:377-386`) and canonical-but-unused scopes (`HasSiteScopes.php:19-30`). A site at 80 shows a green "Healthy" badge yet filters as "Warning"; pill counts contradict the chart on the same screen. *Safe-for-live:* read-path only; unify on the enum scopes.

- **[Medium / Harden] Empty connector inventory wipes local rows.** `SyncWordPressSite.php:102-104,134-136,179-181` — `whereNotIn(..., [])` after a 200-with-empty-list response deletes all `site_plugins` (including stored `license_key`s, :92-96), `site_themes`, `site_users` for the site. *Safe-for-live:* skip deletion when the fetched list is empty.

- **[Medium / Harden] Dashboard & global search are unscoped for non-admins.** `getSitesOverview`/stats/alerts have no user filter (`DashboardService.php:230-295`) and `GlobalSearch` exposes all sites, clients, PHP errors and activity (`GlobalSearch.php:39-120`) — while `SitesList` (`:40`) and `WithBulkSiteActions::scopedSiteQuery` (`:17-21`) scope non-admins to `user_id`, and `canAccessSite` (`User.php:112-129`) defines per-user access. Mutations are policy-guarded (`GlobalDashboard.php:165-227`), so this is intra-team information disclosure, not cross-tenant action — but it contradicts the codebase's own model. Also the `dashboard:stats` cache key is global (`DashboardService.php:26`), so scoping requires per-user keys. *Safe-for-live:* read-path filtering; decide the visibility model explicitly first.

- **[Medium / Harden] Near-zero test coverage.** No test references `GlobalDashboard`, `DashboardService`, `CreateSiteWizard`, `SitesList`, `SiteOverview` or `HealthScoreService`; only one `RecordHealthScores` test (`tests/Feature/Jobs/HealthScoreAndEventsTest.php:18`). Every authz gap above was catchable by a viewer-guard matrix.

- **[Low / Fix] Dead components: `SitesList` and `BulkSettings`.** `/sites` and `/bulk-settings` redirect away (`web.php:78,124`); nothing else mounts either component (~600 lines dead; `BulkSettings::apply()` even lacks a viewer guard — unreachable today). Side effect: the only tag-filter UI lives in dead `SitesList` (`:21-41`), so **Tags (PR #25) are assignable but unusable as a filter anywhere in the live UI**. Correct the Phase-0 map accordingly.

- **[Low / Fix] `escapeLike` corrupts its own escapes** — backslash replaced last (`WithTableFilters.php`, `DashboardService.php:331-334`); `GlobalSearch` doesn't escape at all (`GlobalSearch.php:40`). Search-correctness only (bindings prevent injection).

- **[Low / Fix] Deleted site URLs can never be re-added.** `unique:sites,url` (`SiteWizardFormData.php:12`) collides with soft-deleted rows (`Site.php:122` SoftDeletes). Offboard/re-onboard dead-end; use `withoutTrashed()`.

- **[Low / Harden] Stale health scores.** `RecordHealthScores` runs nightly and skips disconnected sites (`console.php:80`, `RecordHealthScores.php:40`); filters read the persisted column (`DashboardService.php:267-292`), so buckets lag up to 24h, new sites sit at NULL, and a dropped site keeps its last (green) score — compounding the `is_connected` trap.

- Minor: `checkNow` consumes a rate-limit slot even when no uptime monitor exists and no-ops silently (`GlobalDashboard.php:183-198`); `saveReorder` issues one UPDATE per site with no transaction (`GlobalDashboard.php:305-321`) and reorder mode loads all sites with 12 eager loads (`:66`, perPage 10000) — acceptable admin-only cost at 100+ sites; favicon fetching follows HTML-declared hrefs to arbitrary hosts and accepts SVG into Imagick (`FetchSiteFavicon.php` fetchFromHtml/looksLikeImage) — blind SSRF/parse surface, block private ranges and drop SVG; wizard `checkConnectivity` curls a user-supplied URL synchronously for up to 10s in the request worker (`CreateSiteWizard.php:66-113`).

### Performance at 100+ sites
Generally sound: paginated overview with per-parent eager-load limits (Laravel 11) and `withCount`s (`DashboardService.php:232-257`), 60s-cached stats/trends, batched issue lookups (`getSitesWithIssues`, :428-504). Watch: `computeAlerts` runs one `exists()` per failing-backup site (:200-209, bounded); `Site::saved` invalidates the dashboard cache on every save (`Site.php:221-223`) — cheap forgets, fine.

### Scorecard
| Correctness | Stability | Scale | Security |
|---|---|---|---|
| 3 | 3 | 4 | 2 |

**Competitive note (→ Part C):** surface connection status as a first-class dashboard tile/filter and port tag filtering into the dashboard — both signals already exist in the data model.

## Uptime monitoring

**Status: Complete** · Correctness 3 · Stability 3 · Scale 3 · Security 3

**Footprint (VERIFIED).** `MonitoringDispatcher` (every minute, `routes/console.php:17-21`) selects due active monitors and dispatches `CheckUptime` on the dedicated `uptime` queue (2 workers, `config/horizon.php:208-219`). The job probes via HTTP (Cloudflare-challenge-aware, `app/Jobs/CheckUptime.php:148-150`), persists `uptime_checks`, drives the Up/Degraded/Down state machine, opens/resolves `uptime_incidents`, and fans out `SiteWentDown`/`SiteRecovered` to notification, status-page, and AI-incident-response listeners (`app/Listeners/*`). UI: `UptimeOverview`, `ConfigureMonitor`, `SiteUptime`, `UptimeBar`, `ResponseTimeChart`. Solid fundamentals: `ShouldBeUnique` dedupe (`CheckUptime.php:23,39`), encrypted auth secrets (`UptimeMonitor.php:131-132`), LIKE-escaped search, rate-limited manual checks (`SiteUptime.php:84-89`), FK cascades, and the external dead-man heartbeat (`routes/console.php:166-171`).

### Findings

**U-01 · High · Fix — E-27 still open: probe timeout ≥ job/worker timeout ⇒ silent monitoring blackout.** Monitor timeout is user-settable 5–120s (`MonitorFormData.php:21,49`) while the job and supervisor are hard-capped at 30s (`CheckUptime.php:29`, `horizon.php:217`). A hanging client site gets the check SIGKILLed before `saveCheck()`/state update: no check row, no incident, **no down alert**, and since `next_check_at` only advances post-probe (`CheckUptime.php:220`) the dispatcher relaunches a failing job every minute, burning one of two uptime workers. Fix: derive job timeout from monitor timeout (+buffer), verify `retry_after`, and advance `next_check_at` in `failed()`. Safe-for-live: code-only, backwards-compatible.

**U-02 · Medium · Fix — E-29 still open: recovery alerts for never-alerted blips.** Incidents are created on the *first* failed check (`CheckUptime.php:270-278`) but down-alerts wait for the threshold (`:283`); recovery notifies unconditionally (`NotifyIncident.php:33-76`, no `notified_at` guard). One transient blip ⇒ a client-visible "recovered after 5m" for a non-outage, plus phantom status-page resolution and inflated downtime in reports (`UptimeGatherer.php:38-43`). Fix: gate recovery on `incident->notified_at`. Safe-for-live: guard only, no schema change.

**U-03 · Medium · Fix — E-28 still open: sites lacking a `site_health_states` row are never checked.** Dispatcher requires `whereHas('site.healthState')` (`MonitoringDispatcher.php:67-70`); the row is created only by the wizard (`CreateSiteWizard.php:171`) or lazily after a *successful* check (chicken-and-egg via `CheckUptime.php:76` → `CircuitBreakerService.php:197-203`); `Site::created` doesn't create it (`Site.php:228-240`). Fix: `orWhereDoesntHave` in the gate + backfill. Safe-for-live: additive.

**U-04 · Medium · Fix — bulk monitor creation skips site ownership.** `addMonitorsForAllSites` guards only viewers, then creates monitors + immediate probes for *every* site (`UptimeOverview.php:133-150`), unlike every sibling action which calls `authorizeSiteModification`, and unlike `SitesList`'s non-admin scoping (`SitesList.php:40`). PR #19 sweep escapee.

**U-05 · Medium · Harden — read-scope drift.** Overview list/counts are global for any authenticated user (`UptimeOverview.php:41-58,165-183`); `ConfigureMonitor::openModal` and `openMaintenanceModal` load any monitor with no authz (`ConfigureMonitor.php:26-46`, `UptimeOverview.php:91-99`). Intra-team read exposure only, but inconsistent with the ownership model.

**U-06 · Medium · Harden — scale hotspots at 100+ sites.** (a) Overview mounts a `UptimeBar` Livewire child per row (blade `:196`, 50/page) each querying 24h of checks (`UptimeBar.php:19-23`) — 50 queries, up to ~72k rows/render at 1-min intervals. (b) `ResponseTimeChart` 30d hydrates up to 43k check models into the Livewire payload (`ResponseTimeChart.php:33-38`). (c) Every check re-aggregates a 365-day window (`CheckUptime.php:229-265`). Fix with grouped/bucketed SQL and daily rollups.

**U-07 · Medium · Harden — zero tests.** No test file references `CheckUptime`/`UptimeMonitor`/`UptimeOverview`/`ConfigureMonitor`. The alerting state machine has no regression net; prerequisite for landing U-01..03 safely.

**U-08 · Low (cluster) —** degraded blip flips `Site.is_up` false on dashboards before the alert threshold (`CheckUptime.php:67-72,206-212`); `ping` type accepted but unimplemented, and keyword+HEAD guarantees false downs (`MonitorFormData.php:15` vs `CheckUptime.php:89-182,157-174`); `url` column `varchar(255)` vs form `max:2048` ⇒ 500 on long URLs (`pgsql-schema.sql:4262`); maintenance-window early return re-queues a no-op job every minute for the whole window (`CheckUptime.php:46-48`); `uptime_365d` actually reflects the 45-day retention window (`RetentionPolicyService.php:13-21`); monitor URLs are unrestricted server-side fetches with a keyword read-oracle (mild SSRF; `MonitorFormData.php:12`, `CheckUptime.php:100-174`).

**U-09 · Tech debt (VERIFIED dead):** `check_ssl`, `ssl_expiry_threshold`, `uptime_checks.ssl_expires_at`, `check_locations`, `require_all_locations_down`, `auth_*`, `http_headers/body`, `accepted_status_codes` have no UI writer (`ConfigureMonitor.php:63-74`) and no readers; SSL tables were dropped (`schema:8577`) and **no SSL-certificate-expiry monitoring exists anywhere in the app** despite the scheduler comment claiming it (`routes/console.php:16`). Dead `$options` array at `CheckUptime.php:101-104`.

**Competitive note (Track 2).** Single probe location (`location='primary'`, `UptimeMonitor.php:78-80`) means a manager-side network outage false-alarms the entire portfolio; there is no re-alert for long outages and no SLA export. Multi-location confirmation (schema already anticipates it), re-alert cadence, and a cheap TLS-expiry probe (columns already exist) are the highest-leverage wow items vs ManageWP/WP Umbrella.

*Inference flagged:* whether `monitoring.heartbeat_url` and `HORIZON_UPTIME_WORKERS` are actually configured in production env could not be verified read-only.

## SEO audit + broken links

**Status: Partial** — the audit pipeline (crawl → analyze → score → diff) works end-to-end and the DB-backed redirects module is solid, but an entire tier of remediation actions is dead code against live client sites, and the page leaks a credential.

**Footprint (VERIFIED).** Livewire: `app/Livewire/Sites/Detail/SiteSeoAudit.php` (849 lines), `SiteRedirects.php`, `app/Livewire/Seo/{SeoOverview,SeoQuickAudit}.php`. Jobs (all on the `performance` queue → shared 3-worker `general` supervisor, `config/horizon.php:259-262`): `RunSeoAudit` → chained `CrawlSitePages` → `AnalyzeSeoPages` → `CalculateSeoScores` (`RunSeoAudit.php:69`), plus daily `CheckBrokenResources` re-checks (`routes/console.php:70`). Services: `app/Services/SeoAudit/*` (5) + `RedirectSyncService`. Scheduling: `SeoAuditDispatcher` every 5 min with circuit-breaker gating and a 30-min stale-audit sweep (`app/Dispatchers/SeoAuditDispatcher.php:18-55`). Tables: `seo_audits|pages|issues|links|images|monitors|keyword_rankings`, `site_redirects` — all FK ON DELETE CASCADE (schema.sql:8034-8146); 90-day retention for completed audits (`RetentionPolicyService.php:103-109`). Connector: `class-seo-endpoint.php` (12 routes), `class-redirects-endpoint.php` + front-end handler (`simplead-manager-connector.php:169-199`), all behind HMAC (`class-rest-api.php:96-110`).

### What is right (VERIFIED)
- The redirects module proper is well built: authz on every mutator (`SiteRedirects.php:65,87,99`), path normalization identical on both sides (`SiteRedirect::normalizePath` ↔ `SAM_Redirects_Endpoint::normalize_path`), full-set idempotent replace capped at 1000 rules, exact-path matching with a self-redirect loop guard and admin/REST/cron exclusions on the WP side.
- `bulkFix` was correctly hardened by PR #39 (signed HMAC client, never pushes scraped-empty values — `SiteSeoAudit.php:505-516`).
- Dispatcher hygiene is good: circuit-breaker gating, running-audit dedup, stale sweep, `ShouldBeUnique` on crawl jobs.

### Findings

**F-SEO-01 · High · Fix — Decrypted site API key embedded in page HTML.** `site-seo-audit.blade.php:337,375,418` interpolate `$site->api_key` (encrypted cast, `Site.php:193`) into browser-side `fetch()` calls, exposing it via view-source to every user incl. viewers. It cannot sign requests alone (HMAC uses the separate `api_secret`, `WordPressHttpClient.php:94-97`) but is half the credential. *Safe-for-live:* delete the panel (it is dead anyway, F-SEO-02); optionally rotate keys via the existing rotation endpoint.

**F-SEO-02 · High · Fix — All single-page fix actions + connector enrichment are guaranteed-401 dead paths (E-34 only half fixed).** `pushMetaFix/pushRobotsFix/pushCanonicalFix/pushOgFix/toggleSearchVisibility` (`SiteSeoAudit.php:665,704,741,782,814`) and `RunSeoAudit.php:46` send only `X-SAM-API-Key` — a header the connector never reads anywhere (verified by grep); auth mandates `X-SAM-Key`+timestamp+signature (`class-authentication.php:22-32`). PR #39 (0cb9e83) migrated only `bulkFix`. Consequence: every per-page fix button fails against live sites, and `seo_plugin`/`search_visibility`/`redirect_info` never populate (error swallowed at debug level, `RunSeoAudit.php:66-67`). *Safe-for-live:* route all six through the signed `WordPressApiServiceFactory` client like `bulkFix`; no connector change needed.

**F-SEO-03 · Medium · Fix — Redirects page suggests broken links from the wrong audit.** `SiteRedirects.php:44` uses `latest('scanned_at')` without `completed()`; failed/running audits keep `scanned_at` NULL (`SeoAudit::markAs`) and Postgres sorts NULLs first on DESC — after one failed audit the suggestion panel permanently reads an empty audit. One-line fix.

**F-SEO-04 · Medium · Harden — Unguarded SSRF surface.** Broken-link/image checks HEAD/GET arbitrary URLs harvested from client content (`CrawlSitePages.php:289-294,574-577`; `CheckBrokenResources.php:75-105`); sitemap-index fetches arbitrary `<loc>` (`CrawlSitePages.php:680`); Quick Audit crawls any `required|url` input (`SeoQuickAudit.php:107-139`) with results rendered back. No private-IP/scheme guard exists in `app/`. A compromised client site can point the manager at internal services (gotenberg:3000, redis, metadata). *Safe-for-live:* additive egress-guard service, log-only first.

**F-SEO-05 · Medium · Fix — Viewer gap in SeoQuickAudit.** `runQuickAudit` (:105) and `deleteProspect` (:180, ends in `forceDelete`) have no role guard, unlike the PR #11/#19-swept `SiteSeoAudit`/`SiteRedirects`. Viewers can crawl arbitrary URLs and destroy prospect data.

**F-SEO-06 · Medium · Harden — Crawl retry non-idempotent + budget exceeds timeout.** `CrawlSitePages` tries=2, timeout=900s, but worst case is 2000 pages × 15.2s plus 500 external checks × ~5.1s (`config/seo.php:6-7`); no unique `(seo_audit_id,url_hash)` index (schema.sql:7116-7140) and no cleanup on restart → a timeout-retry doubles every page/link/image row and corrupts that audit's counts/score. *Safe-for-live:* purge the in-flight audit's child rows at start of `handle()`.

**F-SEO-07 · Medium · Harden — bulkFix runs synchronously in the web request.** Unbounded issue loop × (15s timeout + 200ms) per page (`SiteSeoAudit.php:489-544`); FPM abort mid-run leaves live-site changes with **no activity-log record** (log written only after the loop, :546). Should be a queued job per project convention.

**F-SEO-08 · Medium · Fix — Scoring contradicts its own anti-inflation fix.** `AnalyzeSeoPages.php:641-643` groups counts "to prevent per-page issues destroying scores", but `ScoringService.php:15-16` still sums penalties per issue **row** (High=8 each) — 50 pages missing descriptions = −400 → category pinned to 0. Scores are non-discriminating in client reports. *Safe-for-live:* per-group penalties; new audits only, note the trend discontinuity.

**F-SEO-09 · Low · Fix — Negative `scan_duration`.** `CalculateSeoScores.php:43` uses `now()->diffInSeconds($created_at)` — reversed vs the codebase convention (`CreateBackup.php:580`) and signed under Carbon 3.11 (composer.lock:2982). (Sign behavior inferred from Carbon 3 semantics; confirm on one prod row.)

**F-SEO-10 · Low · Harden — Normalizer drops query strings** (`UrlNormalizerService.php:9-23`): query-distinct URLs conflate in dedup and link cross-referencing — blind spots on query-driven sites. Within-audit hash only; safe to change for new audits.

**F-SEO-11 · Low · Harden — Silently partial link coverage.** Caps (500 external links / 100 images) and never-crawled internal targets leave `is_broken=false` with no "unchecked" state, while timeouts/429s are flagged broken (`CrawlSitePages.php:280-333`) — simultaneous under- and over-reporting to clients.

**F-SEO-12 · Low · Harden — Duplicate redirect systems + dead connector surface.** The `/seo/redirects` CRUD writing directly into Rank Math/Yoast/Redirection tables (`class-seo-endpoint.php:389-557`) is called only by the dead blade panel; `/seo/update-alt-text` has zero manager-side callers. Two write paths for redirects invite drift with the option-based managed set.

### Async/queue, scale, tests
- Failure handling is present on all four chain stages (`failed()` marks the audit Failed + circuit-breaker), and the 30-min stale sweep self-heals orphaned Pending rows — good.
- Scale: `AnalyzeSeoPages` loads every page incl. raw JSON-LD into memory (`AnalyzeSeoPages.php:49`) — bounded by max_pages but heavy at 2000; 100-site weekly audits clustering at the default 03:00 preferred time will occupy the 3 shared `general` workers for hours (crawls run up to 15 min each), delaying security/reports/default jobs.
- Tests: only `SiteSeoAuditBulkFixTest` (6) and `SiteRedirectsTest` (2). Zero coverage of crawl/analyze/scoring/dispatchers/Quick Audit — and zero on the five broken single-fix actions, which is exactly why F-SEO-02 shipped unnoticed.

**Scorecard:** Correctness 2 · Stability 3 · Scale 3 · Security 2.

**Competitive note (→ Part C):** the crawler itself is already deeper than ManageWP/WP Umbrella broken-link checkers (structured-data validation, hreflang, canonical chains, duplicate content). The WOW gap is closing the loop: broken-link → one-click redirect exists but is hobbled by F-SEO-03; per-page fixes exist but are dead (F-SEO-02); alt-text auto-fix has a ready connector endpoint (`/seo/update-alt-text`) with no UI. Fixing the dead tier is cheaper than building anything new.

## Security & Tweaks hub

**Footprint.** Manager: `app/Livewire/Security/{SecurityDashboard,PresetManager}`, `app/Livewire/Sites/Detail/Security/*` (8 components), `app/Livewire/Sites/Detail/Tweaks/*` (4); services `SecuritySettingsService`, `SiteTweaksSettingsService`, `SecurityPresetService`; jobs `PushSecuritySettings`, `PushSiteTweaksSettings`; table `security_settings` (shared by both security and tweak categories via `SecurityCategory` enum), `security_presets`, `security_banned_ips`, `security_ip_lists`, `sites.security_hardening_score`. Connector v2.17.0: `class-security-hardening.php`, `class-security-htaccess.php`, `class-security-login.php`, `class-security-two-factor.php`, `class-security-captcha.php`, `class-security-ip-manager.php`, endpoints `security-settings` / `security-state` / `site-tweaks`. Live path = manager pushes HMAC REST (no pull queue).

**Status: Complete, recently overhauled (PRs #43-#52), broadly functional.** Authorization is applied on nearly every mutating Livewire action via `WithSiteAuthorization` (mount → `authorizeSiteAccess`, writes → `authorizeSiteModification`), the dashboard is tenant-scoped (`scopedSiteQuery()` restricts non-admins to `user_id`), the E-43 whitelist-scoping fix is present (`class-security-ip-manager.php:52` protects auth surfaces only), and IP unban is a real WP round-trip. But the deep trace surfaced correctness and fail-safe gaps.

### Findings

- **High — .htaccess multi-setting rollback restores a partial file, not the original.** `class-security-htaccess.php:30-67`: `apply_settings` loops each key calling `add_section`/`remove_section` (:72-101), and each helper calls `create_backup($contents)` (:209-211) on the *already-modified* running content. Since a push always sends all htaccess keys at once (`PushSecuritySettings::buildPayload` :100-140), `.sam-bak` ends up holding the state before the *last* change. When `self_check()` fails, `restore_backup()` (:216) restores that partial state, not the pristine original — the client site can stay 500ing. Fix: snapshot the original once per batch and restore from it.

- **Medium — Viewer authz guard missing on `SecurityIpManagement::verifySettings()`.** `SecurityIpManagement.php:215-217` opens straight into `try{}` with no `authorizeSiteModification`, unlike every sibling (`SecurityHardening.php:115`, `SecurityLogin.php:169`, and its own `addIp:91`/`saveFirewallSettings:193`). The method mutates `applied_at`/`failed_at` and can trigger a re-push, so a Viewer can perform a write. Cross-tenant is blocked by `mount()`; this is a role-guard bypass. One-line fix.

- **Medium — CAPTCHA fails open.** `class-security-captcha.php:~228-240`: on `is_wp_error` or empty body, `verify_token()` returns `true`, so any provider outage silently disables captcha on login/registration/reset/comments. Make it fail-closed (at least for login/registration) and log the event.

- **Medium — 2FA default fail-open + zero score credit.** `class-security-two-factor.php:74-85` defaults `fail_mode` to `open`, so broken SMTP bypasses 2FA for admins. And `SecuritySettingsService` `SCORE_WEIGHTS` (:50-65) omits `two_factor_auth` and `custom_login_url` (both valid keys, :36-37), so enabling real 2FA scores nothing while weaker htaccess toggles do. Reweight (recalc scores after deploy) and surface the fail-mode choice.

- **Medium — no `failed()` handler on either push job.** `PushSecuritySettings.php:77-84` rethrows on exception; `markAllFailed` (:225) only runs on a non-2xx HTTP response. When the connector is unreachable, retries exhaust and settings stay `Pending` forever with a stale score and understated "Needs Attention" counts. Add `failed()` to flip settings to Failed and recompute the score. Same in `PushSiteTweaksSettings`.

- **Low — activity_log auto-marked applied.** `PushSecuritySettings.php:42-44` credits `activity_log_config` (5 pts) with no verification.

- **Low — preset snapshots carry a site's encrypted CAPTCHA secret.** `SecurityPresetService::createFromSite:27-46` snapshots `setting_value` verbatim; `applyPreset` + `PushSecuritySettings:142-149` decrypt and push one site's captcha secret to other sites. Exclude credentials from snapshots.

- **Low — self_check homepage-only + web-readable `.htaccess.sam-bak`.** `class-security-htaccess.php:227-241` only probes the homepage (misses admin/path breakage); the `.sam-bak` basename isn't matched by `block_default_files` (:248-256), so it may be downloadable.

- **Low — thin tests.** Only 4 hub test files (~24 tests: `SecurityDashboardTest`, `SecurityIpUnbanTest`, `SecurityLoginTwoFactorTest`, `SecurityOverviewPageTest`). No coverage for the push jobs, score calc, preset apply, htaccess rollback, or the Hardening/Captcha/IpManagement/Tweaks components — the missing IpManagement guard would have been caught by a blanket Viewer-forbidden test.

**Scale (100+ sites):** healthy. `SecurityDashboard::sites()` uses `withCount` + a correlated last-sync subquery + `paginate(50)` (no N+1); push jobs are `ShouldBeUnique` (`push-security-{site}`) so a 12-toggle save or a preset-across-50-sites collapses to one job per site.

## Updates + SafeUpdate

**Status: Complete** — the opt-in safe-update pipeline (PR #28: pre-update DB backup → update → rollback point → health check → visual regression → auto-rollback) is genuinely implemented and unit-tested (`tests/Feature/Services/SafeUpdateServiceTest.php`, 8 tests; `SafeUpdateFlowTest.php`). The connector's rollback endpoint really reinstalls prior versions (`class-rollback-endpoint.php`). But the safety guarantees only hold on one narrow path: single plugin updates from the site detail page. Every other update surface bypasses or weakens them.

**Scorecard:** Correctness 3 · Stability 3 · Scale 3 · Security 3

### Findings

**High**

- **Global /updates page bypasses safe updates entirely (Fix, P1).** `updateSingle`, `updateAllForSite` and `updatePluginAcrossSites` (`app/Livewire/Updates/UpdatesOverview.php:136-297`) never check `safe_updates_enabled` and never dispatch a pre-update backup — only `WithPluginManagement::updatePlugin` (:25-29) routes through the pipeline. A site that opted into safe updates still gets raw inline updates from the bulk surface. *Safe-for-live:* additive branch in the Livewire layer, no schema/connector change.
- **`updatePluginAcrossSites` has no per-site authorization (Fix, P1).** Only an `isViewer()` check (`UpdatesOverview.php:232-234`); the loop (:259-288) never calls `authorizeSiteModification`, unlike its siblings (:152, :192). Non-admin users are scoped via `User::canAccessSite` (`app/Models/User.php:112-124`), so a manager assigned to one client can update plugins fleet-wide. Survived the PR #19 authz sweep. *Safe-for-live:* server-side guard, skip unauthorized sites.

**Medium**

- **Manual plugin rollback is dead code in practice (Fix).** `rollbackPlugin` queries `UpdateLog where('name', $plugin->slug)` (`WithPluginManagement.php:201-206`) but logs store `name` = human name, `slug` = slug (`PluginManagerService.php:44-49`) — the lookup virtually never matches, so the button always reports "No previous version found." One-line fix: query `slug`.
- **Core update always reports success (Fix).** `PluginManagerService::updateCore` returns hardcoded `success => true` and logs `coreUpdated` regardless of the connector result (`PluginManagerService.php:410-417`); consumed as a success flash in `SitePlugins.php:249-261`. A failed core (security) update reads as done.
- **"Pre-update" backup races the update (Fix).** `runPreUpdateBackup` dispatches `CreateBackup` async to the backups queue, then the bulk/core update runs inline immediately (`WithPluginManagement.php:241-247`, :163; `SitePlugins.php:253`). The restore point may capture post-update state. The safe pipeline gets this right with `dispatchSync` (`SafeUpdateService.php:58`).
- **Safe update never takes the site operation lock; nested backup can silently no-op (Harden).** `OPERATION_SAFE_UPDATE` exists (`SiteOperationLock.php:39`) and `CreateBackup` accepts a parent `heldLockToken` for exactly this case (`CreateBackup.php:74-80`), but nothing in `RunSafeUpdate`/`SafeUpdateService` acquires or passes it. A safe update can overlap a restore; and if a scheduled backup holds the lock, the dispatchSync'd backup returns without doing anything (`BackupJobTrait.php:154-192`) and the update proceeds backup-less — `SafeUpdateService.php:56-59` never verifies a backup was produced.
- **Successful safe update leaves stale state (Fix).** On `completed`, `SafeUpdateService.php:134-140` never updates `SitePlugin.version/has_update`, decrements `pending_updates_count`, or dispatches `SyncWordPressSite`. The UI keeps offering the update; a second click reruns the whole pipeline (duplicate backup/log/rollback point).
- **Auto-rollback is silent (Harden).** The health-check/visual-regression failure branch — including a performed rollback — sets `failed` without notifying (`SafeUpdateService.php:141-162`); only exception paths alert (`RunSafeUpdate.php:41-63`). The `executeRollback` result is discarded (:152). "We rolled back your client's site" should page someone.
- **30s HTTP timeout vs unbounded connector update work (Harden).** Update calls use the default 30s (`ManagesPlugins.php:17-25`, `WordPressApiService.php:80`) while the connector's `update_plugins` refreshes transients and runs upgrades with no `set_time_limit` (`class-plugins-endpoint.php:180-215`; rollback gets 300s). On slow hosts the manager times out, the update lands anyway — unverified, no rollback point, recorded as failed. Also makes `updateAllForSite` (one inline HTTP call per plugin, in a Livewire request) a gateway-timeout hazard at real portfolio scale.
- **Rollback only works for wordpress.org-hosted code (Harden).** Download URLs are hardcoded to `downloads.wordpress.org` (`class-rollback-endpoint.php:71,128`); rollback points are still minted for premium/custom plugins (`RollbackService.php:19-30`), so auto-rollback for WooCommerce extensions, ACF Pro etc. 404s and leaves the site on the broken version.

**Low**

- No sweeper for `safe_updates` rows stuck in `backing_up`/`updating`/`health_checking` after a hard worker kill (nothing analogous to `backups:recover-stuck-restores`; retention only purges terminal states, `RetentionPolicyService.php:80`).
- `executeRollback` marks the point `used` and defaults the log to `success ?? true` before validating the payload (`RollbackService.php:38-53`).
- Updates listing/stats are fleet-wide for every authenticated user regardless of site assignment (`UpdatesOverview.php:31-48, 57-117`).
- `pending_updates_count` drifts: decremented for plugins only, floor-less, never on safe-update success; self-heals at next sync (`SyncWordPressSite.php:195-201`).

### Queue/async notes
`RunSafeUpdate` runs on the `default` queue under supervisor-general (timeout 600, matching the job's `$timeout = 600`), `tries = 1`, `ShouldBeUnique` per SafeUpdate id — sane. Idempotency relies on each UI click minting a new row; there is no status guard in `runSafeUpdate` against re-running a terminal row.

### Test coverage
Good on the service happy/failure paths (SafeUpdateServiceTest incl. auto-rollback and connector-rejects-slug; RollbackServiceTest; PluginManagerServiceTest) and the flag-routing flow. **Zero tests** for `UpdatesOverview` (`updateAllForSite`, `updatePluginAcrossSites` — the two highest-blast-radius actions in the module).

### Competitive note (→ Part C)
The visual-regression + auto-rollback pipeline already exceeds ManageWP Safe Updates on paper — but only for single plugin updates. Extending it to themes/core/bulk (the machinery supports all three: `SafeUpdateService.php:69-74`, connector rollback for `plugin|theme|core`) plus surfacing before/after screenshots in client reports is the standout WOW candidate here.

## Backups — restore + app backups + verification

**Status: Complete** (site restore) / **Broken in its primary scenario** (app self-restore). Scorecard: Correctness 3 · Stability 3 · Scale 3 · Security 4.

### What is verifiably good

The site-restore path is real and has been seriously hardened since the last audit, not just patched:

- **Mandatory safety backup** before every restore, with an explicit typed-domain bypass only after a *failed* safety backup (`app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:27-31, 247-277`), and the bypass is loudly logged into the restore log and activity feed (`app/Jobs/RestoreBackup.php:155-160`).
- **Cross-class site mutual exclusion** on a non-evictable DB cache store (`app/Services/Backup/SiteOperationLock.php:22-27, 101-104` — E-06 closed), token-ownership release semantics, re-run guards, and unique-job TTLs that cannot wedge forever (`RestoreBackup.php:44-53`).
- **Replica-aware download fallback** across storage destinations with checksum verification at every hop, for all three formats (legacy v2-zip, v3-zip, multipart-v3 with per-file SHA256 manifest) (`RestoreBackup.php:244-284, 342-351, 481-487`).
- **Connector-side atomic staged swap** for full file restores plus samold_* table-swap DB restore that re-preserves the connector's API keys and activation after the restored DB overwrites them (`wordpress-plugin/.../endpoints/class-backup-endpoint.php:1633-1640, 1706-1727`) — selective restores correctly forced to merge mode (`RestoreBackup.php:922-926`).
- **Post-restore verification**: cache clear, Elementor repair + deactivate/reactivate cycle, loopback diagnostic, paused-extension detection — all best-effort with results appended to the completion message (`app/Services/Backup/PostRestoreVerifier.php`).
- **Two-level backup verification**: Level A at creation and a weekly Level B sweep that re-downloads samples from real storage and runs full integrity checks (checksums, ZIP CHECKCONS, SQL dump structural parse) (`app/Services/Backup/IntegrityVerifier.php`, `app/Console/Commands/VerifyBackupRestoreCommand.php`), plus an on-demand "Test restore" job (`app/Jobs/RunBackupVerification.php`).
- **Authorization** is solid: `BackupPolicy` (viewer-deny, owner-or-admin) plus an explicit backup↔site binding check on every restore entry point (`RestoreConfirmation.php:99-110`); the unauthenticated `/restore-download/{token}` route uses a 256-bit random token, format-validated and throttled (`routes/web.php:39-51`).
- **Real test coverage** on the risky logic: `tests/Feature/Jobs/RestoreBackupLockTest.php` (8 tests on lock/redelivery/failed semantics), `RecoverStuckRestoresTest`, `RestoreConfirmationTest` (12), unit tests for IntegrityVerifier, SqlDumpParser, SiteOperationLock, PostRestoreVerifier.

E-05 is effectively closed: Redis `retry_after=7200` (`config/queue.php:70`) exceeds the 3600s job timeout, matches the lock TTL, and the job carries an explicit redelivery guard — *but see finding 2*.

### Findings

**1. [High · Fix] Two conflicting stuck-restore recovery mechanisms; the dominant one can kill a live restore and unlock the site under it.** `BackupDispatcher::recoverStuckRestores()` runs **every minute** and marks any restore with 30 minutes of row-silence as Failed, then **blindly** `SiteOperationLock::forceRelease()`s the site (`app/Dispatchers/BackupDispatcher.php:226-228, 249-250`; scheduled at `routes/console.php:31-35`). But a healthy restore is legitimately row-silent for up to 30 minutes inside a single `sendRestoreData` HTTP call (timeout 1800s, no progress writes between the 55% and 65% marks — `RestoreBackup.php:766-787, 928-932`). On a large/slow site — the exact known problem area — the dispatcher can declare a still-running restore dead and free its lock, letting a scheduled backup or a second operator-triggered restore start **concurrently with the restore still executing** on the live client site. It also pre-empts (30 < 75 min) and contradicts the carefully designed PR #38 command, whose own comments forbid blind force-release (`app/Console/Commands/RecoverStuckRestores.php:29, 63-71`), reducing it to near-dead code and double-notifying. *Safe-for-live:* delete the dispatcher copy (or raise its threshold above the 3600s job timeout and adopt ownership-checked release); pure manager-side removal, trivially revertable.

**2. [High · Fix] Redelivery guard hole: a timeout-killed restore silently re-runs in full ~2h later.** The re-run guard refuses redelivered attempts only when the row is still `InProgress` (`RestoreBackup.php:108-115`). Both recovery sweeps flip the row to **Failed** and release the lock — so when Redis redelivers the reserved job at `retry_after=7200s` after a worker kill (tries=4 means a timeout does not permanently fail the job), the guard passes and the **entire restore re-runs unattended** on the live site, two hours after the operator was told it failed and possibly after manual fixes or new client content. This is precisely the hazard the job's own docblock forbids (`RestoreBackup.php:37-43, 92-99`); the existing test covers only the `InProgress` case (`RestoreBackupLockTest.php:89`). Since a legitimate lock-busy requeue can only ever observe status `Pending` (set at `RestoreConfirmation.php:353-359`; `InProgress` set only post-lock at `RestoreBackup.php:147`), the fix is a strictly tighter conditional: on `attempts() > 1`, proceed only from `Pending`. *Safe-for-live:* backwards-compatible in-job guard change, no migration.

**3. [High · Fix] App self-restore is effectively broken against the live database.** The app dump is created without `--clean/--if-exists` (`app/Services/AppBackup/AppBackupCreator.php:273-281`) and restored with `psql --set ON_ERROR_STOP=1` into the same populated database (`AppBackupRestorer.php:50-58`): the first `CREATE TABLE` hits "relation already exists" and aborts (VERIFIED flags; runtime behavior INFERENCE — needs one staging run to confirm). Even with fixed flags the design is unsound: it runs **synchronously inside a Livewire HTTP request** with Horizon workers still writing (`app/Livewire/Settings/ApplicationBackup.php:283-322`), no maintenance mode, over the pooled (PgBouncer) connection rather than `pgsql_direct` (`config/database.php:110`). The platform's own disaster-recovery button is a safety net that almost certainly fails when pulled. *Safe-for-live:* rebuild as a console/queued flow (maintenance mode + horizon:pause + direct connection + `--clean --if-exists`), validate on a staging DB, hide the button until proven.

**4. [Medium · Harden] A killed app-backup permanently blocks all future app backups.** `AppBackupCreator` refuses to start while any row is `in_progress` (`AppBackupCreator.php:31-33`), but a pcntl timeout kill (job timeout 1800s, `CreateAppBackup.php:22`) or deploy container-recreate skips the catch block and leaves the row stuck forever — no sweep recovers it (`AppBackupCleanupCommand` does retention only). Platform self-backup silently stops until manual DB surgery. *Safe-for-live:* additive stale-row sweep mirroring `RecoverStuckRestores`.

**5. [Medium · Fix] Selective restore (tar path) can silently succeed while restoring nothing.** The tar extraction ignores exit code and stderr entirely (`RestoreBackup.php:868-885`); a failed extraction produces an empty selective archive that is sent and marked "Selective restore completed (N files restored)". Zip-format inner archives are unaffected. *Safe-for-live:* throw on non-zero exit and assert the selective archive entry count; converts silent false-success to loud failure.

**6. [Medium · Harden] No disk-space guard on restore; chain restores amplify disk 3-4× site size.** `DiskSpaceGuard` gates only backup dispatch (`BackupDispatcher.php:34-36`); `restoreFromChain` keeps every per-chain extract dir plus a merged dir plus a re-zipped `files.zip` (`RestoreBackup.php:557-683`), and `sendRestoreData` `copy()`s the archive again (`:916-919`). Full disk mid-restore fails at the worst moment and can pause backups fleet-wide. *Safe-for-live:* additive pre-flight space check + incremental temp cleanup + hardlink instead of copy.

**7. [Medium · Harden] Temp cleanup misses every non-`backup-*` prefix.** `CleanupBackupTemp` sweeps only `backup-*` dirs and `php*` files (`CleanupBackupTemp.php:44-46, 66`); orphaned `restore-*`, `restore-{token}`, `verify-*`, `app-backup-*`, `app-restore-*` debris from killed workers accumulates forever. *Safe-for-live:* widen the prefix list; the 24h age cutoff already protects live runs.

**8. [Medium · Harden] DR circularity and locality in platform self-backup.** `env.encrypted` is encrypted with the APP_KEY it contains (`AppBackupCreator.php:319-321`) — undecryptable after true host loss; the independent daily pg dump never leaves the host (`DatabaseDumpCommand.php:26`, scheduled `routes/console.php:117-120`). *Safe-for-live:* independent, vaulted encryption key for new env backups (keep APP_KEY decryption for old ones) + off-site upload step for db:dump.

**9. [Low · Harden] Secret-on-cmdline inconsistency.** `PGPASSWORD=<pw>` inline in `sh -c` in app backup/restore (`AppBackupCreator.php:273-292`, `AppBackupRestorer.php:50-68`) — a pattern `DatabaseDumpCommand.php:34-45` already deliberately fixed with `.pgpass`; openssl key passed via `-pass pass:` (`DatabaseDumpCommand.php:76-80`). Container-local exposure only.

**10. [Low · Harden] pg_dump/psql use the pooled connection, not `pgsql_direct`** (`AppBackupCreator.php:267`, `AppBackupRestorer.php:46`, `DatabaseDumpCommand.php:19`) — the same fragility class that broke migrations pre-PR #32 (INFERENCE that dumps currently succeed; a one-line host-source change removes the risk).

**11. [Low · Wow] Verification coverage is thin at scale and never performs a real restore.** Weekly Level B samples 3 backups (`routes/console.php:153`) — <0.5% coverage at 100+ sites with daily backups — and by design proves restorability *without applying* (`BackupVerifier.php:12-17`). The Track-2 differentiator: automated sandbox restore drills (throwaway container, DB import, HTTP probe) with a per-site "last proven restore" badge; none of WPMUDEV/ManageWP/WP Umbrella offer this.

### Multi-tenancy & security verdict

No cross-tenant leakage found in this module: backup↔site binding is enforced on every restore entry point, `BackupPolicy` denies viewers/non-owners, app-backup pages sit behind `role:admin` (`routes/web.php:192, 213`), and the unauthenticated restore-download token is 256-bit, short-lived, and throttled. The `.env` viewer exposes all platform secrets in-browser but is admin-gated by route — acceptable, worth an audit-log entry when used.

### Unverifiable read-only (follow-ups)

- Whether an actual staged restore succeeds end-to-end on a large production-size site (needs a controlled drill).
- Runtime confirmation that app-DB restore fails on a populated DB (one staging run).
- Whether pg_dump through PgBouncer has ever produced a subtly bad dump (compare a pooled vs direct dump on staging).

## Reports (HTML/PDF)

**Status: Complete (with defects).** Purpose: scheduled + manual per-site maintenance reports — data gathered by 18 section gatherers (`app/Services/Reports/Sections/`), rendered to Blade, converted to PDF via **Gotenberg 8** (`app/Services/GotenbergService.php` — cover/body/closing rendered separately at 120s Guzzle timeout each, then merged), stored on the local disk, emailed with attachment + 7-day signed download link + permanent token view link. Footprint: `ReportDispatcher` (every 5 min, `routes/console.php:38`), `GenerateReport`/`NotifyUpcomingReport` jobs (queues `reports`/`notifications`; `reports` runs on the `general` supervisor, `config/horizon.php:259`), `ReportGeneratorService` + `ReportDataGatherer` + `ReportManagementService` + `ReportRecommendationService`, Livewire `ReportsOverview`/`SiteReports`/`ReportView`/`ReportRecommendationsManager` + three traits, tables `reports` (`data_snapshot` jsonb), `report_schedules`, `report_templates`, `report_recommendations`, `recommendation_templates`, `site_report_configs`.

**What is solid (VERIFIED):** dispatcher claims schedules atomically before dispatch (`app/Dispatchers/ReportDispatcher.php:47-56`); the job has a dedup window, `was_sent` idempotency guard, PDF magic-byte verification before attaching, and a `finally` safety net advancing `next_run_at` (`app/Jobs/GenerateReport.php:78-95, 184, 263-275, 288-325`); public view link uses `hash_equals` (PR #18); portal report access correctly verifies the report belongs to the portal client (`app/Http/Controllers/ClientPortalController.php:46-49, 63-67`); email download links are 7-day `temporarySignedRoute` (`app/Mail/ReportGeneratedMail.php:53-57`).

### Findings

- **High / Fix — "Generate All" is fatally broken and unguarded.** `ReportsOverview::generateAllReports()` dispatches `GenerateReport::dispatch($site, $template)` (`app/Livewire/Reports/ReportsOverview.php:58`) but the constructor requires `$periodStart`/`$periodEnd` with no defaults (`app/Jobs/GenerateReport.php:47-56`) → `ArgumentCountError` on click, zero jobs queued. The method also has no authorization (any Viewer can invoke). *Safe-for-live:* additive fix to a dead path; no migration.
- **High / Harden — viewer write-guard gaps missed by the PR #19 sweep.** `WithReportDistribution::sendReport/bulkSend/bulkDelete` (`app/Livewire/Traits/WithReportDistribution.php:26-61`) and `WithReportGeneration::confirmGenerate/proceedToRecommendations/toggle/remove/addCustomRecommendation` (`app/Livewire/Traits/WithReportGeneration.php:61-149`) never call `authorizeSiteModification` — a Viewer can bulk-delete report PDFs, email reports to arbitrary external addresses, and rewrite client-facing recommendations. Site scoping is intact (role escalation only, not cross-tenant); `ReportRecommendationsManager` guards every one of the same actions (`app/Livewire/Sites/Detail/ReportRecommendationsManager.php:41-216`). *Safe-for-live:* guard-only change.
- **Medium / Fix — catch-all `Throwable` defeats `$tries=2`, and 3 failures silently kill a schedule.** `app/Jobs/GenerateReport.php:231-263` swallows every exception, so retries/backoff never engage; auto-deactivation after 3 consecutive failures (`:245-251`) emits only a log warning — no admin notification, no UI surfacing. A client silently stops receiving reports (this exact cascade happened pre-PR #18 via the SeoGatherer crash). *Safe-for-live:* rethrow infra-class errors + notify on deactivation; success path untouched.
- **Medium / Fix — the Infrastructure section is unreachable; two gatherers are dead code.** The PDF requires `$data['ssl']/['domain']/['email']` (`resources/views/reports/maintenance-report.blade.php:40-44`) but `ReportDataGatherer`'s whitelist never produces those keys and `findGatherer('email')` matches no gatherer (`app/Services/ReportDataGatherer.php:40-56, 83-95`); `DnsGatherer` (`dns`) and `ErrorLogGatherer` (`error_logs`) are instantiated (`app/Services/ReportGeneratorService.php:199-200`) but never invoked. The template UI still sells SSL/Domain/Email Deliverability toggles and defaults `infrastructure` on (`app/Livewire/Traits/WithTemplateForm.php:103-107, 265`). Silent missing deliverable.
- **Medium / Fix — recipient emails unvalidated; one bad address aborts delivery to all recipients.** `saveSchedule` validates 4 fields only (`app/Livewire/Traits/WithReportScheduling.php:91-96`), addresses are comma-split unchecked (`app/Services/ReportManagementService.php:22-25`), and the send loop's single try/catch (`app/Jobs/GenerateReport.php:205-229`) stops at the first transport rejection with only a warning — report shows "completed", client gets nothing, monthly.
- **Medium / Fix — `ShouldBeUnique` can silently swallow a scheduled report.** `next_run_at` is advanced *before* dispatch, so if the site+template unique lock is held by an in-flight manual job (`app/Jobs/GenerateReport.php:31, 60-63`; `app/Dispatchers/ReportDispatcher.php:47-56`) the scheduled dispatch is dropped and that month's report never exists; the in-job safety net can't run. The 1-hour DB dedup already covers duplicates — the unique lock is redundant risk.
- **Low / Fix — draft-recommendation linking is a site-global grab.** `GenerateReport.php:130-132` links *all* unlinked drafts (including `is_included=false`) to whichever same-site report finishes first; concurrent template runs or an ad-hoc manual run can consume recommendations approved for the scheduled monthly report (approval reads at `app/Services/ReportDataGatherer.php:122-134`).
- **Low / Harden — timeout budget mismatch + no stuck-report recovery.** Job timeout 300s vs worst-case 4×120s of Gotenberg calls (`GenerateReport.php:35`; `GotenbergService.php:21`); on timeout the row stays `generating` forever (no sweeper exists — contrast `backups:recover-stuck-restores`) and manual retries duplicate rows (dedup is schedule-scoped, `reportId` property loss across retries).
- **Low / Fix — three different download access rules on the same data.** Bulk zip requires strict ownership so Admins 403 (`app/Http/Controllers/BulkReportDownloadController.php:17`); single download allows admin-or-owner but omits the client-assignment path of `User::canAccessSite` (`app/Http/Controllers/ReportDownloadController.php:16-21` vs `app/Models/User.php:112-129`). Fails too-strict — friction, not leakage. Bulk zip also leaks temp files on mid-zip failure and embeds unsanitized site name in the filename (`:30, :45`).
- **Low / Harden — tech debt.** `gatherSiteDataForRecs` duplicated verbatim across trait and component (`WithReportGeneration.php:160-238` ↔ `ReportRecommendationsManager.php:226-304`); `maintenance-report-v2.blade.php` referenced nowhere (dead view); `loadTemplate` unscoped `findOrFail` lets users load others' recommendation templates (`ReportRecommendationsManager.php:185` — delete *is* scoped `:211`); weekly `calculateNextRun` uses `now()->next()` which skips a same-day-later-time first run (`app/Models/ReportSchedule.php:97-99`); no retention for report PDFs or `data_snapshot` jsonb (absent from `RetentionPolicyService`).

**Data & tenancy:** portal and per-site surfaces correctly scoped (VERIFIED above). `ReportsOverview::render()` lists all reports platform-wide to any authenticated user — consistent with the platform's single-team read norm (`BackupsOverview`, `DashboardService` are equally unscoped), so not reported as leakage. Permanent, non-expiring public view links and WP-user PII in `data_snapshot` remain the known product-decision item flagged in PR #18 — still open, not re-reported.

**Queue/async:** `reports` shares the `general` supervisor with security/performance/default (`config/horizon.php:259`) — acceptable; report generation is not time-critical. **Scale:** per-site bounded queries in gatherers; dispatcher `each()` over due schedules is fine at 100+ sites. **Tests:** only two authorization tests touch reports (`tests/Feature/Authorization/ReportsAuthorizationTest.php`, `ViewerWriteGuardTest.php`); the dispatcher claim logic, job idempotency, `calculateNextRun`, gatherers, and Gotenberg path have zero coverage — the module's riskiest gap given its history of silent-failure regressions.

**Scorecard:** Correctness 3 · Stability 3 · Scale 4 · Security 3.

## Clients + client portal

**Status: Complete.** Footprint: `Client`/`ClientCost`/`ClientRevenue` models, `client_user` assignment pivot, 4 Livewire components (`app/Livewire/Clients/`), `ClientPolicy`, public token portal (`ClientPortalController` + `resources/views/client-portal/`), routes `web.php:172-176` (authed) and `web.php:248-250` (public, throttled).

**What is verifiably solid** (all VERIFIED in code):
- Portal auth model is sane: 64-char random token, DB-unique (`pgsql-schema.sql:5290-5294`), auto-generated on create (`Client.php:47-51`), gated by `portal_enabled` (`ClientPortalController.php:15-17`), throttled 60/min view + 10/min download (`web.php:248-250`). Brute force is infeasible at this length.
- Cross-client report isolation holds on both view and download: report `site_id` checked against the token-resolved client's sites, 403 otherwise (`ClientPortalController.php:46-49,64-67`). `Client` and `Site` soft-deletes mean trashed clients/sites drop out of the portal automatically via global scopes.
- No raw Blade output anywhere in `client-portal/` (zero `{!!` in both views) — no stored-XSS path from snapshot data.
- Internal CRUD uses `ClientPolicy` consistently in `ClientDetail`/`ClientForm`/`ClientsList` (delete admin-only, `ClientPolicy.php:47-50`); the E-37/E-38 report/financial authz holes were fixed in PR #18 and regression-tested.
- `OpenApiService::lookupCui` is regex-validated, fixed-host, rate-limited (`OpenApiService.php:15-50`) — no SSRF from the CUI lookup.
- Scale: portal page is bounded (one client's sites, eager-loaded at `ClientPortalController.php:19-21`; reports capped at 20); `ClientsList` paginates and computes status counts in a single filtered aggregate (`ClientsList.php:87-99`). No N+1 found at 100+ sites.

**Findings** (severity · type):

1. **`sites.client_id` FK is `ON DELETE CASCADE`** (Medium · Harden) — `pgsql-schema.sql:8270-8274`. The app only soft-deletes clients today (no `forceDelete` anywhere in `app/`), but any future purge job or manual SQL cleanup of a client row hard-deletes every site beneath it, cascading into backups/monitors/reports for live managed sites. *Safe-for-live:* single expand-contract migration to `ON DELETE SET NULL` (column already nullable), run via `pgsql_direct`, restart PgBouncer; rollback = restore old constraint.
2. **Zero portal test coverage** (Medium · Harden) — no test in `tests/` touches `client-portal`, `portal_token`, or `ClientPortalController`; only the `ClientProfitability` viewer guard is tested (`ReportsAuthorizationTest.php:56-84`). An unauthenticated internet-facing surface has no regression net over its three security gates. *Safe-for-live:* test-only addition.
3. **`ClientProfitability` bypasses `ClientPolicy`** (Low · Harden) — `mount()` has no authorize (`ClientProfitability.php:37-40`); mutations check only "not Viewer" (`:43-49`) instead of `update` on the client. Contained in practice (only rendered inside view-authorized `ClientDetail`, `client-detail.blade.php:162`; Livewire snapshots prevent arbitrary mounting) but the test at `ReportsAuthorizationTest.php:70-84` codifies the weaker check. *Safe-for-live:* tighten to policy calls + adjust test; removes no legitimate access.
4. **Archived clients keep a live portal; portal defaults on** (Low · Fix) — status is never checked (`ClientPortalController.php:15-17`), and every client gets `portal_enabled=true` + token at creation (`Client.php:47-51,76-78`). Offboarding via `status=archived` does not revoke access to current site health/PHP/WP versions and report PDFs. *Safe-for-live:* add status condition / auto-disable on archive; check for intentionally archived-but-active portals first (one SELECT).
5. **`ClientsList` scope ignores `client_user` assignments** (Low · Fix) — non-admin list scope is site-ownership only (`ClientsList.php:77-82`) while `ClientPolicy::view` also grants assigned users (`ClientPolicy.php:24-25`); assigned users can open a client by URL but never see it listed. Fails closed — inconsistency, not leakage. *Safe-for-live:* read-path query widening to match policy.
6. **`WithSorting` passes user-controlled `sortBy` straight to `orderBy`** (Low · Harden) — `WithSorting.php:41-44`, consumed at `ClientsList.php:108`; `?sortBy=bogus` → SQL error/500 (identifier wrapping prevents injection on Postgres; direction is builder-validated). Cross-cutting to every list using the trait. *Safe-for-live:* allowlist fallback, purely additive.
7. **Portal `viewReport` lacks the `data_snapshot` guard the public token view has** (Low · Fix) — `ClientPortalController.php:40-55` vs `ReportViewController.php:19-21`; pending/failed reports render an empty white-label shell. *Safe-for-live:* one 404 guard.
8. **Portal toggle / token regeneration not activity-logged** (Low · Harden) — `ClientDetail.php:29-45`; no `ActivityLogger` usage in the module. No audit trail for enabling a public data surface or rotating its credential. *Safe-for-live:* additive logging.
9. **Portal recomputes health score in Blade** (Low · Harden) — `show.blade.php:36` calls `HealthScoreService::calculate()` per site while the persisted `sites.health_score` is canonical elsewhere; client-visible number can disagree with the dashboard. Perf is fine (relations eager-loaded; no queries in `calculate`). *Safe-for-live:* display-only switch to the persisted column.
10. **Profitability type/interval inputs unvalidated** (Low · Fix) — `ClientProfitability.php:79-125` persists free-string `type`/`interval`; `summary()` treats any non-`yearly` interval as monthly (`:57-61`), silently skewing MRR/margin. Internal-only. *Safe-for-live:* add `in:` rules/enums after checking existing rows.

**Inventory correction (VERIFIED):** the Phase-0 map lists `client_user` among unreferenced orphan tables — it is in fact live: `User::assignedClients()` / `Client::assignedUsers()` `belongsToMany` default to that pivot (`User.php:104-107`, `Client.php:102-105`) and it backs `ClientPolicy`, `UserManagement` assignment UI, and `ErrorLogsOverview` scoping. Do not include it in any orphan-table cleanup (E-53).

**Test coverage:** financial viewer-guard only; portal and client CRUD untested. **Competitive note (→ Part C):** portal is read-only status+reports; no client login, no per-client user accounts, no billing/subscriptions (confirmed absent), no custom domain/branding beyond logo — WPMUDEV/ManageWP client-portal parity is a WOW candidate.

**Scorecard:** Correctness 4 · Stability 4 · Scale 4 · Security 4.

## DNS + domain expiry

**Status: Complete** — two distinct flows, both wired end-to-end. (1) **DNS/DKIM record monitoring**: `MonitoringDispatcher` (every minute, `app/Dispatchers/MonitoringDispatcher.php:94-101`) dispatches `CheckDns` for due `dns_monitors`; the job resolves A/AAAA/MX/NS/CNAME/TXT + DMARC + DKIM (selector discovery from manual list, Cloudflare API, Postmark, and a 22-entry fallback list — `app/Services/DnsSelectorDiscoveryService.php:12-18`), diffs against `current_records`, persists `dns_changes`, and notifies. (2) **Domain-registration expiry**: daily 04:30 scheduler closure (`routes/console.php:190-201`) re-checks each site weekly via `CheckDomainExpiry` → `DomainExpiryService` (RDAP via rdap.org with a label walk-up for registrable-domain resolution), writing `sites.domain_*` columns (migration `2026_07_10_000004`) consumed by the site todo feed (`app/Services/SiteTodoService.php:85-89`), site card, and notifications. Correction to the E-53 backlog: `DomainStatus` is **no longer dead code** — it is the live status enum for this module since PR #26.

**Scorecard:** Correctness 3 · Stability 3 · Scale 4 · Security 4.

### Findings

- **[High · Fix] E-30 confirmed still open — resolver failure read as "records deleted".** `dns_get_record() === false` (transient failure) is stored as an empty record set (`app/Jobs/CheckDns.php:119-125`), so one blip creates a bogus `dns_changes` row, an "DNS Records Updated" alert, SPF/DMARC/DKIM flipping to *Missing* on the site card, **and a false change embedded in the client's report** (`app/Services/Reports/Sections/DnsGatherer.php:32-53`); the next good check produces a second bogus change back. *Safe-for-live:* job-logic-only fix (treat `false` as "skip this type in comparison"), no schema, instant rollback.
- **[Medium · Fix] Transient RDAP failure wipes expiry data for a week.** `CheckDomainExpiry` unconditionally overwrites `domain_expires_at`/`domain_registrar` (NULL on error, `app/Jobs/CheckDomainExpiry.php:44-50`) and stamps `domain_checked_at`, so the 7-day gate (`routes/console.php:194`) blocks retry — a single rdap.org 429 erases an *ExpiringSoon* warning during the 30-day window. *Safe-for-live:* preserve last-known values on `Error` and retry errors next day; job-only change.
- **[Medium · Fix] New sites never get a DNS monitor.** `CreateSiteWizard::createSite()` stores `maintenance_plan_id` but never materializes plan modules (`app/Livewire/Sites/CreateSiteWizard.php:156-176`); the only `DnsMonitor` creation paths are the **manual, unscheduled** `dns:backfill-monitors` command (`app/Console/Commands/BackfillDnsMonitors.php:27`) and explicit plan re-apply/module toggle (`app/Services/ModuleConfigService.php:279-341`). Sites onboarded since the last backfill are silently unmonitored. *Safe-for-live:* call `applyPlan`/`configureModule('dns')` at creation (rows are created with jitter); run backfill once after deploy.
- **[Medium · Harden] Timeout → every-minute re-dispatch loop.** `CheckDns` updates `next_check_at` only after all lookups (`CheckDns.php:56`; timeout 90s, tries 1 at `:24-26`); a pcntl timeout kill skips the catch, leaving the monitor past-due, so the every-minute dispatcher re-queues a 90-second blocking job forever — on the shared **general** supervisor (`default` queue, `CheckDns.php:34`), starving security scans/reports. Mechanics VERIFIED; frequency INFERENCE. *Safe-for-live:* stamp attempt time before lookups or add a `failed()` hook that bumps `next_check_at`.
- **[Medium · Harden] Monitoring failure is invisible.** The catch path only logs and advances the clock (`CheckDns.php:95-102`); `dns_monitors` has no error/failure-count columns and `DnsOverview` shows no failure state — a permanently failing monitor looks healthy with a fresh `last_checked_at`. *Safe-for-live:* additive nullable `last_error`/`failed_checks` columns + badge (remember the PgBouncer-restart-after-DDL deploy step).
- **[Low · Fix] Acknowledge is decorative.** `has_changes` is recomputed per check (`CheckDns.php:59`) and drives the dashboard feed (`app/Services/DashboardService.php:445-447`), while `acknowledge()` only stamps the `dns_changes` row (`app/Livewire/Dns/DnsOverview.php:75-82`) — unreviewed changes vanish from the feed at the next 6-hour check; acked ones linger. Drive the feed from unacknowledged `dns_changes` instead.
- **[Low · Harden] No retention for `dns_changes`.** Absent from `RetentionPolicyService::CATEGORIES` (`app/Services/RetentionPolicyService.php:12-112`); unbounded growth, amplified by the E-30 churn.
- **[Low · Harden] Host derivation duplicated 4×, one unanchored.** `DomainExpiryService.php:33` uses unanchored `replaceFirst('www.', '')` vs anchored regex in `BackfillDnsMonitors.php:26` and `ModuleConfigService` (dns case), while a proper helper sits unused (`app/Models/Traits/HasDomainExtraction.php:33-52`). Consolidate.
- **[Low · Fix] Soft-deleted-site scoping inconsistent in `DnsOverview`** — monitors tab filters `deleted_at` (`DnsOverview.php:211-212`) but `stats()`, the changes tab, and `recheckAll()` don't (`:33-66, :192-194, :88`); display-only, no tenant risk (single-team model; mutating actions `acknowledge`/`saveSelectors`/`rediscoverSelectors` all enforce `authorizeSiteModification`, and `recheckAll` blocks viewers — `DnsOverview.php:80,96,145,86`). SSRF surface is nil: expiry queries only `rdap.org` with a host-derived path.
- **[Low · Harden] Test gap:** only `tests/Feature/Jobs/DomainExpiryTest.php` (3 tests) exists; `detectChanges`/`normalizeForComparison` (`CheckDns.php:229-277`), DKIM discovery, and Livewire actions are untested — land tests with the E-30 fix.
- **[High · Fix · ADJACENT, out of module]** Found while tracing DNS retention: `RetentionPolicyService.php:90` still prunes **`security_commands`**, dropped by `2026_07_11_000004` (PR #45); `RetentionCleanup` has no per-table try/catch (`app/Jobs/RetentionCleanup.php:72-88`), so nightly retention will abort at the `security_hardening` category and the later `failed_jobs`/`seo` categories never prune. Belongs to Settings/Retention — remove the entry and add per-table error isolation, deployed with/before the drop migration.

**Competitive note (Part C):** none of WPMUDEV Hub / ManageWP / WP Umbrella monitor DNS, DKIM, or domain-registration expiry — this module is already a differentiator. WOW candidate: expected-record pinning (alert only on deviation, structurally killing false positives) plus DMARC/SPF deliverability grading in client reports.

## Error logs + PHP error logs

**Footprint.** Connector endpoint `GET /error-logs` (`wordpress-plugin/simplead-manager-connector/includes/endpoints/class-error-logs-endpoint.php`) tail-reads the last 512KB of `ini_get('error_log')` and `wp-content/debug.log`, regex-parses standard PHP log lines, dedups by `md5(level+message)`. Manager side: `app/Jobs/FetchPhpErrorLogs.php` (queue `default` → `general` supervisor, `ShouldBeUnique`, tries=1, timeout=60), dispatched per connected site every 6h with 0–120s jitter (`routes/console.php:174-181`); `app/Models/PhpErrorLog.php` (`php_error_logs`, composite index `site_id/is_resolved/level` via migration `2026_05_15_000001`); UI `app/Livewire/ErrorLogs/ErrorLogsOverview.php` (`/error-logs`, `routes/web.php:156`); consumers: `DashboardService.php:440`, `SiteOverview.php:266`, `Reports/Sections/ErrorLogGatherer.php`, `Components/GlobalSearch.php:94`.

**Status: Complete.** VERIFIED end-to-end: connector parse → HMAC-authenticated fetch (shared permission stack, `class-rest-api.php:96`) → upsert-by-hash with fatal notifications (`FetchPhpErrorLogs.php:52-101`) → tenant-scoped list UI with resolve action → report section. No stubs, no dead paths.

### Findings (Track 1)

1. **HIGH / Fix (cross-cutting, found via this module's retention check)** — Nightly `RetentionCleanup` crashes on the dropped `security_commands` table. `RetentionPolicyService.php:90` still lists it; migration `2026_07_11_000004` dropped it; `RetentionCleanup.php:170-179` raw-DELETEs with no `hasTable` guard or per-table try/catch. Consequence: the 03:00 job dies mid-run → `failed_jobs`/`seo` categories never prune, expired client backups (`RetentionCleanup.php:97`) and rollback points never clean, `retention_last_run_*` goes stale; the Settings retention stats UI also queries the missing table (`RetentionPolicyService.php:193-201`). *Safe-for-live:* one-line config removal + `hasTable` guard; no migration, instant rollback.
2. **MEDIUM / Fix** — Count inflation + resolved-error resurrection. The connector has no ingestion cursor (re-parses the same 512KB window every call, `class-error-logs-endpoint.php:69-81`); the manager **adds** the returned count and force-resets `is_resolved=false` on every fetch (`FetchPhpErrorLogs.php:60-64`). Counts inflate ~4x/day with zero new occurrences; "resolve" undoes itself within 6h; reports rank top errors by the inflated count (`ErrorLogGatherer.php:49`). Recurring fatals after resolution never re-notify (notification only on first-ever hash insert, `FetchPhpErrorLogs.php:78-92`). *Safe-for-live:* manager-side watermark (only bump when entry `last_seen` > stored `last_seen_at`) is backwards-compatible with all connector versions; connector `since` cursor rides the pending fleet push.
3. **MEDIUM / Fix (connector)** — Chronology by `strcmp` on `DD-Mon-YYYY` strings (`class-error-logs-endpoint.php:37-39,48`) is wrong across month/year boundaries → wrong "latest N" selection and wrong `last_seen`, which the manager persists and `ErrorLogGatherer.php:28` uses for report-period bucketing. Fix: epoch-parse before compare; bundle with the fleet plugin push.
4. **MEDIUM / Harden** — `php_error_logs` is absent from `RetentionPolicyService::CATEGORIES` (`RetentionPolicyService.php:12-112`) and has no other deletion path (repo-wide grep). Dynamic message fragments defeat hash dedup, so growth is unbounded at 100+ sites; resolved rows live forever. *Safe-for-live:* add a category (prune by `last_seen_at`, start resolved-only) — reuses the existing batched deadline-bounded deleter; operator-tunable via the existing Settings UI.
5. **MEDIUM / Fix (security)** — `GlobalSearch.php:94-97` queries `PhpErrorLog` with no tenant scoping, mounted in the header for every authenticated user (`page-header.blade.php:174`) — directly bypassing the non-admin scoping `ErrorLogsOverview.php:29-42` deliberately added in PR #11 (comment cites "file paths, SQL fragments"). Sites/plugins/clients queries in the same component are equally unscoped (other modules' remit). Internal team-level leak only — the client portal does not render this component. *Safe-for-live:* reuse the `accessibleErrorLogs()` predicate; read-only query narrowing.
6. **LOW / Harden** — `resolve()` (`ErrorLogsOverview.php:57-65`) checks `canAccessSite` but not the viewer write-guard used by the PR #8/#11/#19 sweep (`WithSiteAuthorization.php:33-35`); viewers can hide fatals from the unresolved stats and dashboard issues feed.
7. **LOW / Fix** — ILIKE escaping order bug (`ErrorLogsOverview.php:86`): backslash is escaped **after** `%`/`_`, doubling the just-added escapes, so searching literal `%`/`_` (common in SQL-fragment messages) mismatches. Parameter-bound — no injection. Escape `\\` first.
8. **LOW / Harden** — Silent failure: `catch (\Throwable) → Log::warning`, tries=1 (`FetchPhpErrorLogs.php:24,102-104`). A site whose fetch fails every run for weeks reads as "no errors". A single bad entry (e.g. `file` path >255 chars stored untruncated into `varchar(255)`, `FetchPhpErrorLogs.php:71` vs `pgsql-schema.sql:1955`) aborts the rest of that batch. Truncate `file`, per-entry try/catch, surface consecutive-failure counts in the site to-do feed.

**Async/queue:** VERIFIED sane — per-site `ShouldBeUnique` id, jittered dispatch, isolated failures, no cascade; `default` queue lands on the `general` supervisor (`config/horizon.php:259`). Non-atomic read-modify-write upsert (`FetchPhpErrorLogs.php:55-64`) is protected in practice by the unique lock; INFERENCE: safe unless the same site is dispatched from a second path (none exists today).

**Scale (100+ sites):** fetch fan-out is fine (≤100 short jobs/6h). The concern is table growth (finding 4) feeding the joined, sorted, ILIKE-searched global list (`ErrorLogsOverview.php:79-96`) and unbounded-time dashboard aggregate (`DashboardService.php:440`).

**Tests: none.** Zero coverage for `FetchPhpErrorLogs`, `ErrorLogsOverview`, or connector parsing — only an unused fake stub (`tests/Fakes/FakeWordPressApiService.php:441`). The count/resurrection semantics (finding 2) are exactly the kind of logic a small unit test would have caught.

**Competitive note (→ Part C):** WP Umbrella's PHP-error monitoring is real-time-ish with per-error alert throttling and error grouping by normalized message (stripping dynamic fragments); a 6-hour poll plus raw-message hashing under-groups and under-alerts. WOW candidate: connector-side push-on-fatal (admin-ajax cron or shutdown handler) + normalized fingerprinting.

**Scorecard:** Correctness 3 · Stability 4 · Scale 3 · Security 3.

## Activity + audit logs

**Scope:** manager-side activity feed (`activity_logs`, `App\Services\ActivityLogger`, `App\Livewire\Activity\ActivityTimeline`) and the WP security-audit pipeline (connector `SAM_Audit_Logger` + `/audit-logs` endpoint → `App\Jobs\PullSecurityActivityLogs` → `App\Services\SecurityActivityService` → `security_activity_logs` → per-site `SecurityActivity` page).

**Status: Complete** (both halves function end-to-end) — but the pull pipeline has real correctness gaps. **Scorecard: Correctness 3 · Stability 3 · Scale 4 · Security 3.**

**How it works (VERIFIED):** ~40 typed helper methods write `activity_logs` synchronously (`app/Services/ActivityLogger.php:13-35`); the global timeline paginates with date/type/severity filters and correct LIKE-escaping (`app/Livewire/Activity/ActivityTimeline.php:92-106`). On each 6-hourly site sync (`app/Dispatchers/DataSyncDispatcher.php:83-97`, `app/Jobs/SyncWordPressSite.php:228`), `PullSecurityActivityLogs` computes a per-site watermark (max `occurred_at`), calls the HMAC-signed connector `/audit-logs?since=...`, and bulk-inserts up to 1,000 mapped rows in 500-row chunks. Retention is properly wired on both ends: manager `RetentionPolicyService` covers both tables (`app/Services/RetentionPolicyService.php:58,89`); the connector self-purges at 90 days (`class-audit-logger.php:92-101`). Indexes on both tables are appropriate for the query shapes; FKs are sane (`activity_logs` SET NULL, `security_activity_logs` CASCADE).

### Findings

1. **HIGH / Fix — Watermark poisoning: one bad remote row silently and permanently stalls a site's audit ingestion.** Remote values flow unvalidated from the connector into typed Postgres columns (`app/Jobs/PullSecurityActivityLogs.php:69-81`; `app/Services/SecurityActivityService.php:21-45`): `ip_address` is `inet`, `event_type` is varchar(50) while the connector's `action` allows 100 chars (`class-audit-logger.php:23`), `occurred_at` is an unparsed remote string. A single malformed row fails the whole batch insert; the watermark never advances, so every subsequent pull refetches the same poison batch — a permanent, per-site, warning-level-only failure. Separately, a future-dated `occurred_at` (WP host clock skew, or a compromised site) jumps the watermark past all real events (`created_at > since`, `class-audit-logger.php:111`). *Safe-for-live:* manager-side validation/clamping only, no connector change, no migration.

2. **MEDIUM / Fix — Burst loss: newest-500-DESC with no pagination gaps the trail exactly when it matters.** The connector returns the newest 500 rows (`class-audit-logger.php:104-125`); the manager makes one request per 6h sync. Any site exceeding 500 audited events in the window permanently loses the older ones — sites under attack are the most likely to gap. Secondary: exclusive `>` on second-resolution DATETIME can skip same-second events flushed after the pull. *Safe-for-live:* add ASC/cursor pagination to the connector (rides the self-update channel; version-gate the loop), or re-pull while `count == limit`.

3. **MEDIUM / Fix — The Failed Logins panel is dead: the connector never emits `failed_login`.** `getFailedLoginStats` queries `event_type='failed_login'` (`app/Services/SecurityActivityService.php:60-90`; surfaced at `SecurityActivity.php:41-45`), but the only table writer is the pull job, and the connector's `record_failed_login` writes only a brute-force transient — never the audit table (`class-security-login.php:109-126`; no `failed_login` in `class-audit-logger.php:133-202`). Live effect: **0 failed logins shown during an active brute-force** — a misleading security signal. *Safe-for-live:* additive, rate-capped connector logging in the next release; panel stays zero (no regression) until fleet updates.

4. **MEDIUM / Harden — Global `/activity` timeline bypasses per-site access control.** No user scoping on feed or stats (`ActivityTimeline.php:41-49,92-106`), while `User::canAccessSite` (`app/Models/User.php:112-128`) restricts non-admins and the per-site page enforces it (`SecurityActivity.php:37`). Restricted managers/viewers see all sites' events plus every user's login IP/user-agent (`ActivityLogger.php:360-425`). Internal-only exposure (no portal path), but inconsistent with the PR #19 authz model. *Safe-for-live:* read-path `whereIn` filter for non-admins; admins unaffected.

5. **LOW / Harden — No dedup guard on ingestion.** `PullSecurityActivityLogs` lacks `ShouldBeUnique` (contrast `SyncWordPressSite.php:15,24`) and the table has no unique key; overlapping pulls double-insert and inflate counts. *Safe-for-live:* one-line `ShouldBeUnique`; do **not** add a DB unique constraint to the live table without deduping first.

6. **LOW / Harden — `since` cursor format is fragile.** ISO8601-with-offset string compared against naive-UTC MySQL DATETIME (`PullSecurityActivityLogs.php:39` → `class-audit-logger.php:111`); parsing is MySQL-version-dependent, and correctness silently depends on `APP_TIMEZONE=UTC` (default at `config/app.php:68`; prod `.env` unverifiable read-only — flagged as INFERENCE). Send `->utc()->format('Y-m-d H:i:s')` instead.

7. **LOW / Fix — Type filter drift.** `ActivityTimeline` TYPES (`ActivityTimeline.php:24-36`) misses types actually written (`user`, `database`, `seo`, `seo_fix`, `webhook`, `incident_response`, `error_log`, `dns`, …); those events are only findable under "All". Type/severity are free strings — no enum, contrary to project convention. String-backed enum fixes this with zero migration.

8. **LOW / Harden — No dedicated tests.** Nothing in `tests/` targets this module; only three incidental `assertDatabaseHas('activity_logs', …)` assertions elsewhere (`RestoreBackupLockTest.php:129`, `RestoreConfirmationTest.php:133`, `SiteSeoAuditBulkFixTest.php:110`). Findings 1-4 live in untested code.

**Not re-reported:** command-queue ingestion paths (removed, PR #45); connector 2.17.0 fleet-rollout gap is tracked globally.

**Competitive note (→ Part C):** ManageWP/WP Umbrella surface a client-visible, filterable per-site activity log incl. failed-login/attack timelines; fixing findings 2-3 plus exposing the WP audit trail in client reports is a cheap WOW candidate.

## Notifications + escalations

**Status: Complete** — multi-channel (Slack/Telegram/Discord/email/custom webhook) with in-app notifications, Redis batching for info-level events, ack tokens, escalation rules, quiet hours, templates, and per-user/per-channel event preferences. The pipeline was substantially hardened by PR #33: ack links are now actually delivered (`app/Jobs/SendNotificationJob.php:53-61`), failed sends throw and escalate (`SendNotificationJob.php:105-119`, `ProcessNotificationEscalations.php:46-49`), and escalation-generated notifications are born `escalated` so A→B/B→A rule pairs cannot loop (`SendNotificationJob.php:97`) — the loop suspected in project memory is VERIFIED fixed; what remains is retry amplification (below). Meta-alerts bypass the queue via `dispatchSync` so "Horizon is down" still sends (`NotificationService.php:237-244`) — good design. Scheduler wiring is sound: batch every minute, escalations every 5 min, digest daily, all `onOneServer` (`routes/console.php:210-226`). Targeted tests exist (`tests/Feature/Jobs/NotificationEscalationPipelineTest.php`, 8 tests: ack token embedding, failed-send escalation, born-escalated guard, malformed buffer items, ack endpoint).

**Scorecard:** Correctness 3 · Stability 4 · Scale 4 · Security 3

### Findings

- **HIGH / Fix — Recovery alerts are unroutable on subscription-filtered channels.** `NotifyIncident` emits event `site_up` (`app/Jobs/NotifyIncident.php:39`) but the channel-subscription UI and templates are built exclusively from `NotificationTemplate::EVENTS`, which only knows `site_recovered` (`app/Models/NotificationTemplate.php:47`; `resources/views/livewire/settings/components/channel-form.blade.php:96-98`). Any channel with explicit `event_subscriptions` delivers "site down" but silently never delivers the recovery (`NotificationChannel::subscribedTo`). No emitter of `site_recovered` exists (VERIFIED by grep); `site_degraded`, `email_blacklisted`, `content_stale`, `connector_update_failed` are likewise selectable-but-never-emitted dead options. *Safe-for-live:* alias `site_up`↔`site_recovered` in `subscribedTo()` during transition, then rename the emitted event; no schema change.
- **MEDIUM / Fix — Retry-amplified duplicate escalations.** `SendNotificationJob` (tries=3) creates a `NotificationLog` row on every attempt (`SendNotificationJob.php:83-102`) and throws on failure (`:118`); each stale `failed` row later fires a false "[ESCALATION] Delivery … FAILED" even if a retry succeeded (`ProcessNotificationEscalations.php:46-53`). Up to 3 failed rows / 3 escalations per alert on a dead channel. This is the residual noise behind the memory note. Fix by logging once per notification (idempotency key or final-attempt-only failure row). *Safe-for-live:* job-logic only, revertible.
- **MEDIUM / Fix — Quiet hours destroy non-critical alerts with zero trace.** Early return happens before channel dispatch AND before in-app creation (`NotificationService.php:46-48, 207-209` vs `:95-101`). Overnight recoveries (`success`), vulnerabilities, DNS changes, PHP fatals (`warning`) are lost forever — not deferred, not logged, no in-app row. *Safe-for-live:* always write in-app; buffer channel sends until quiet-hours end (reuse the Redis buffer); quiet hours default off today.
- **MEDIUM / Fix — Missing authz on `NotificationDropdown` backup actions.** `retrySiteBackup()`/`retryFailedBackups()` dispatch `CreateBackup` with no viewer guard and no `canAccessSite` check (`app/Livewire/Components/NotificationDropdown.php:111-142`) — missed by the PR #19 81-guard sweep; contrast `BackupsOverview` (`:240,:335`) and `WithSiteAuthorization`. A viewer can trigger full backups on arbitrary client sites. *Safe-for-live:* add the standard guard, zero data risk.
- **MEDIUM / Harden — GET-mutating ack endpoint + unfurl auto-ack (INFERENCE on crawler behavior; design VERIFIED).** `GET /notifications/ack/{token}` acknowledges immediately (`routes/web.php:245`; `NotificationAckController.php:13-17`) and the URL is embedded in Slack/Discord text (`SendNotificationJob.php:59-61`). Telegram suppresses previews; Slack/Discord senders do not — a preview crawler fetching the link auto-acks and permanently suppresses escalation (`ProcessNotificationEscalations.php:50`). *Safe-for-live:* two-step confirm (GET page → POST), plus one-line unfurl suppression as immediate mitigation.
- **MEDIUM / Fix — Email channel: 'sent' means only 'queued'.** `Mail::to()->queue()` returns success instantly (`EmailNotificationSender.php:27-29`) so SMTP failures never mark the log failed and never trigger the failed-send escalation built in PR #33; the ack URL is appended to `$message`, which the email path ignores (`SendNotificationJob.php:77-79`) — email recipients can never acknowledge. *Safe-for-live:* send synchronously inside the already-queued job; pass ack URL via mailable args.
- **MEDIUM / Fix — Dead settings.** `notify_down`/`notify_recovery`/`notify_degraded` are saved (`NotificationSettings.php:44-46`) and consumed nowhere — `NotifyIncident` dispatches unconditionally and no degraded path exists. The UI promises control it doesn't have. *Safe-for-live:* honor flags (defaults preserve current behavior) or remove toggles.
- **LOW / Harden —** (a) Dedup key is event+site only with non-atomic `Cache::has`+`put` and the deduped second event leaves no in-app record (`NotificationService.php:51-53, 319-330`); use `Cache::add` + content hash. (b) Decrypted channel configs (webhook URLs = secrets) cached plaintext in Redis 10 min (`NotificationChannel.php:63-70`, redis default `config/cache.php:18`). (c) Webhook sender: no SSRF guard and dynamic `->$method()` from config — admin-only surface (`WebhookNotificationSender.php:21-54`; route gate `web.php:192-194`). (d) `in_app_notifications` has no automated retention (only user-clicked `deleteOld`, `NotificationCenter.php:71-79`; retention map covers `notification_logs` only, `RetentionPolicyService.php:61-67`) and `SendDailyDigest` mails every user with no opt-out (`SendDailyDigest.php:34-40`).

**Multi-tenancy:** channels are global by design (single-team model — no `user_id` on `notification_channels`); in-app rows are correctly scoped to the site owner and all reads/mutations in `NotificationCenter`/`NotificationDropdown` filter by `auth()->id()` (`NotificationCenter.php:40-95`). No cross-tenant leakage found. Per-event preferences for app-level events silently no-op in queue/scheduler context (`auth()->id()` null, `NotificationService.php:222-231`) — acknowledged in code, acceptable.

**Scale (100+ sites):** per-event queries (template + channels + per-channel preference lookup) are small constants; escalation scan is bounded (`limit(10)` per rule per 5 min — a large incident storm on a dead channel drains slowly but safely); `notification_logs` has usable indexes (`(status,created_at)`, `(channel_id,created_at)` in schema) and is retention-managed. Adequate.

**Test coverage:** good on the PR #33 hardening paths; none on quiet hours, dedup, grouped batching output, sender formatting, or the retry-duplication scenario above.

## Google integrations (Analytics + Search Console)

**Status: Complete.** OAuth connect → property picker → scheduled sync → cache → UI/reports all work end-to-end. The weak spots are silent-failure paths, not missing functionality. All claims below are VERIFIED in code unless marked otherwise.

**Footprint.** Services: `app/Services/GoogleApiService.php` (token refresh + retrying HTTP client), `GoogleAnalyticsService.php` (GA4 Data API, 11 report methods), `GoogleSearchConsoleService.php` (GSC v3 + URL Inspection, 14 methods). Jobs: `FetchAnalyticsData`, `FetchSearchConsoleData` (both `sync` queue, `ShouldBeUnique`, tries=2, backoff 30/60), `FetchKeywordRankings` (daily 04:00, `routes/console.php:73-77`). Dispatch: `app/Dispatchers/DataSyncDispatcher.php:33-68` (per-connection `next_sync_at`/`interval_minutes`, gated on connector circuit + monitoring flag). Models/tables: `google_connections` (tokens, encrypted casts), `analytics_connections`/`search_console_connections` (unique per site, FK CASCADE both directions — `pgsql-schema.sql:5170-5174,7406-7418`), `analytics_cache`/`search_console_cache` (6h TTL rows), `seo_keyword_rankings`. UI: `SiteAnalytics`, `SiteSearchConsole`, `SiteSeoAudit` (keyword tracking), `IntegrationsSettings` (account management, admin-gated). OAuth: `GoogleAuthController` inside the `role:admin` settings group (`routes/web.php:234-235`).

**What's solid.** Read-only scopes only (`analytics.readonly`, `webmasters.readonly` — GoogleAuthController:40-46); tokens encrypted at rest; retry with 429/5xx allowlist (GoogleApiService:59-74); analytics failures isolated per-domain so a Google outage can never open a site's connector circuit (CircuitBreakerService:20-23,161-190 — the E-13/PR #20 split holds here); dispatcher queries are index-backed (`analytics_connections_is_active_next_sync_at_index`); GA property listing paginates with a hard 20-page cap; keyword inserts are chunked. No cross-site data mixing found: caches are keyed by `site_id` with FK CASCADE, and connection rows are unique per site. (Tenancy is the single-team model — any team member sees all sites — so "tenant isolation" reduces to site-scoping, which is correct.)

### Findings

- **HIGH (Fix): Transient refresh failure permanently and silently kills a Google connection.** Any failed token-refresh response — including Google-side 429/500/503, no retry on this call — sets `is_active=false` (`GoogleApiService.php:44-47`). From then on: the daily validator only checks `is_active=true` connections so it never reports it (`ValidateExternalConnections.php:72`), and all three fetch jobs mark themselves *complete* with "Skipped — no active connection" (`FetchAnalyticsData.php:54-58`, `FetchSearchConsoleData.php:54-58`, `FetchKeywordRankings.php:43-45`). One shared Google account typically serves many sites, so a single blip silently freezes GA+GSC+keywords fleet-wide, and client reports keep rendering the stale cache (below). *Safe fix:* deactivate only on `invalid_grant`/4xx; rethrow transient errors; notify on deactivation. Manager-only, no schema change.
- **MEDIUM (Fix): OAuth `state` check bypassable.** `abort_unless($request->get('state') === session()->pull('google_oauth_state'), 403)` passes when both are `null` (`GoogleAuthController.php:62`) — a crafted `/google/callback?code=…` link with no state param clears the check for any admin who didn't just start the flow (login-CSRF → attacker-controlled analytics source → report poisoning). Mitigated by `role:admin` + `throttle:10,1`; still fix: require non-empty state + `hash_equals`. Also harden the unchecked `userinfo` response (`:81-83` → 500 on failure).
- **MEDIUM (Fix): Trend comparison is dead code.** `AnalyticsCache` keeps exactly one row per `(site_id, date_range)` via `updateOrCreate` (`FetchAnalyticsData.php:85-97`), so `trendAnalysis()`'s query for a row fetched >7 days before the current one can never match (`SiteAnalytics.php:272-276`) — change-% is permanently null, silently. (Bonus: `:274` mutates the Carbon attribute in place.)
- **MEDIUM (Fix): Range-switch fetches silently dropped.** `uniqueId()` is `analytics-{site_id}` with no range (`FetchAnalyticsData.php:38-41`; same in `FetchSearchConsoleData.php:38-41`). A user-requested 7d/custom fetch dispatched while the scheduled 28d job holds the unique lock is discarded, yet the UI flashes "refreshing" (`SiteAnalytics.php:113-115,137-139`) — spinner never resolves.
- **MEDIUM (Harden): Client reports use the rolling 28d cache regardless of report period, with no staleness guard.** `AnalyticsGatherer.php:28-36` and `SearchConsoleGatherer.php:27-38` take the latest `28d` cache row ignoring `$periodStart/$periodEnd` and `expires_at`. Combined with the HIGH finding, a report can ship months-old numbers to a client with no warning. Start with a fetched_at threshold (omit section + note when stale), then align to the report period.
- **MEDIUM (Fix): `FetchKeywordRankings` data-integrity trio.** (1) Delete-today-then-insert is non-transactional (`FetchKeywordRankings.php:96-103`) — a crash loses the day; (2) the delete wipes same-day tracked-keyword placeholders created by `SiteSeoAudit::trackKeyword` (`SiteSeoAudit.php:339-349`) — a keyword not in GSC's top-200 loses its tracked flag overnight, silently; (3) `catch (\Throwable) → Log::warning` (`:106-108`) makes `tries=2` dead and hides API failures entirely. Also `recorded_date=today` for data from D-3 (`:49-50,69`) shifts every trend chart 3 days, and `$q['url']` (`:84`) never exists → column always null.
- **MEDIUM (Harden): Zero tests.** No file under `tests/` references any Google class (grep). Every finding above is the kind that tests around token refresh, the OAuth callback, and cache writes would have caught. Prerequisite for the fixes.
- **LOW (Harden): Tokens are double-encrypted** — `encrypted` casts (`GoogleConnection.php:47-48`) *plus* manual `encrypt()/decrypt()` in both writers (`GoogleAuthController.php:91-92`, `GoogleApiService.php:26,31,52`). Consistent today (verified: no other readers), but any new cast-only reader gets ciphertext and the DecryptException path deactivates the connection. Fix requires a one-off unwrap migration — do not change code alone.
- **LOW (Harden): No refresh lock / expiry skew / empty-refresh-token guard** (`GoogleApiService.php:25,37-42`; `GoogleAuthController.php:92` stores `encrypt('')` if Google omits `refresh_token`).
- **LOW (Harden): Failing connections re-dispatch forever** — domain breakers are recorded but by design never gate dispatch (`CircuitBreakerService.php:161-165`; `DataSyncDispatcher.php:35-68`): a deleted GA property means failing jobs every interval, indefinitely. Add exponential `next_sync_at` backoff after N domain failures.
- **LOW (Fix): `disconnectSearchConsole` null-unsafe delete** (`SiteSearchConsole.php:229`, vs the null-safe analytics twin at `SiteAnalytics.php:229`); minor authz asymmetry — viewers can trigger `refreshData`/`fetchRealtimeData` (`SiteAnalytics.php:142-151,206-224`), quota noise only.
- **LOW (Fix, dead code): `getExternalLinks` fabricates "external links" from top-pages clicks and has no callers** (`GoogleSearchConsoleService.php:433-456`) — delete with the E-53 cleanup.

**Scale at 100+ sites:** fine. Indexed dispatcher queries, one-at-a-time-per-site unique jobs on the `sync` supervisor, 6h cache TTL, chunked inserts; GSC is 4 requests/site/sync — well within quota. INFERENCE: default `interval_minutes` not verified against migration defaults.

**Scorecard:** Correctness 3 · Stability 3 · Scale 4 · Security 3.

## Cloudflare

**Footprint.** `CloudflareService` (app/Services/CloudflareService.php), Livewire pages `Sites/Detail/SiteCloudflare` and admin token management in `Settings/IntegrationsSettings` (:425-501, admin-gated at routes/web.php:192-199), job `SyncCloudflareZone` (queue `sync`, `ShouldBeUnique`), dispatched every 6h per zone by `DataSyncDispatcher::dispatchCloudflareSync()` (app/Dispatchers/DataSyncDispatcher.php:71-81), daily token validation in `ValidateExternalConnections` (:84-96), report section `CloudflareGatherer`, DKIM discovery consumer `DnsSelectorDiscoveryService` (:76-98). Tables: `cloudflare_connections` (token `encrypted` cast — VERIFIED, app/Models/CloudflareConnection.php:39), `site_cloudflare` (FKs cascade correctly), `cloudflare_cache_purges`.

**Status: Complete** for its surfaced scope (connect site→zone, read-only DNS view, purge everything/by-URL with per-user rate limit, live GraphQL analytics, 6h zone/SSL sync, report section). Authorization on mutating actions is present and correct (`authorizeSiteModification` on connect/disconnect/purge, SiteCloudflare.php:90,113,162,196). But the module carries a large dead tail and several silent-failure paths.

### Findings (Track 1)

- **[Medium · Fix] E-59 confirmed still open — purge false-success.** Both purge actions discard the service's bool result (`SiteCloudflare.php:179,214`; `CloudflareService.php:147-163`) and unconditionally write a `cloudflare_cache_purges` row + success flash. `purgeByUrls` also sends unlimited URLs in one call (Cloudflare caps at 30/request) with no validation. Operators can believe a client site's stale/defaced content was purged when Cloudflare rejected the call, with a false audit trail. *Safe-for-live:* UI/service-only change, no schema/connector impact.
- **[Medium · Fix] `SyncCloudflareZone` retry config is dead.** The catch at `SyncCloudflareZone.php:70-74` swallows exceptions when `attempts() < tries`, so the job "succeeds" on first failure — `tries=2`/`backoff` never engage and `failed()` never fires for transients. `last_sync_at` is never written by anyone (permanently null). Zone/SSL data silently stales. *Safe-for-live:* job-only; retried call is an idempotent GET.
- **[Medium · Fix] HTTP errors become plausible data.** `request()` never throws on non-2xx (`CloudflareService.php:451-475`); `getSslMode()` defaults to `'off'` on any failure (:280-285) and the sync job persists it (`SyncCloudflareZone.php:64-66`) — a permissions error makes the manager (and client reports, `CloudflareGatherer.php:43`) claim SSL is OFF on a site running Full/Strict. Same default-masquerade pattern in `getSecurityLevel()`/`getWafStatus()`; the DNS tab caches `[]` for 120s on failure with no error shown (`SiteCloudflare.php:136-144`).
- **[Medium · Fix] Report Cloudflare metrics are a never-populated data path.** `CloudflareGatherer.php:36-38` and `WithReportGeneration.php:537` read `cloudflare_requests`/`cloudflare_bandwidth_bytes`/`cloudflare_cache_hit_ratio` from `site_monthly_snapshots`, but no code writes those columns (`AggregateMonthlySnapshots` has zero Cloudflare references — VERIFIED by repo-wide grep); `site_cloudflare.cache_level` likewise has no writer. Every client report renders "not available"/"—" for Cloudflare traffic despite `getAnalytics()` working live in the UI. *Safe-for-live fix:* additive snapshot writer; readers already null-guard.
- **[Medium · Harden] Unvalidated `zone_id` → REST-path and GraphQL injection.** `connectToZone` stores the raw Livewire property without checking format or membership in the connection's zone list (`SiteCloudflare.php:88-108`); it is interpolated into URL paths (`CloudflareService.php:58`) and unescaped into the GraphQL query (`:315`). Bounded by the token's own scope, but allows arbitrary GETs under the shared token and garbage zone links (failed lookups still store `zone_name=''`, `status='active'`, `:67-79`). Fix: `^[a-f0-9]{32}$` + membership check + GraphQL variables.
- **[Medium · Harden] Connection scoping.** The zone-connect dropdown lists **all** valid connections regardless of owner (`SiteCloudflare.php:56`), so any non-viewer with access to a single site can enumerate every zone and read full DNS records of any team member's Cloudflare account via `availableZones`/`listZones`. Acceptable in a trusted single-team model, but inconsistent with the otherwise-scoped `canAccessSite` model (`User.php:112`) — make the sharing model explicit.
- **[Low · Harden] Validation flaps availability.** `validateToken()` writes `is_valid=false` on *any* non-success including CF 5xx (`CloudflareService.php:20-32`); daily `ValidateExternalConnections` can thus silently disable sync fleet-wide for up to 24h (`SyncCloudflareZone.php:44` skips invalid connections). Demote only on authoritative 401/403.
- **[Low · Fix] `listDnsRecords` truncates at 100 records** — no pagination (`CloudflareService.php:84-89`), unlike `listZones`; degrades the DNS view and can silently miss `_domainkey` records in DKIM selector discovery (`:91-122`).
- **[Low · Fix] `plan_label` TypeError.** `ucfirst($this->plan_type)` under `strict_types` throws when `plan_type` is null (`SiteCloudflare.php` model :83-86; null set at `CloudflareService.php:73`) — 500s the site's Cloudflare page and the report gatherer (`CloudflareGatherer.php:42`) for affected rows; the blade's `?? 'N/A'` cannot catch it.
- **[Low · Harden] Dead surface + zero tests.** DNS write, firewall-rule, WAF, access-rule/IP-block, security-level, purge-by-tag/prefix methods have no callers (`CloudflareService.php:124-276` range) and target Cloudflare's deprecated Firewall Rules / legacy WAF endpoints (INFERENCE re external deprecation — verify before ever wiring; rebuild on Rulesets instead). No file under `tests/` mentions Cloudflare (VERIFIED).

**Positives (verified):** API token encrypted at rest; token CRUD admin-only; per-connection 200 req/min rate limiter on every call (`CloudflareService.php:428-434,453-459`); per-user purge rate limit (`SiteCloudflare.php:169-174`); `listZones` pagination correct; job dedup via `ShouldBeUnique`; analytics period input safely coerced (`abs((int))`, `:291`). Minor note: unlike the other three sync types, `dispatchCloudflareSync` ignores `is_monitoring_disabled`/circuit state (`DataSyncDispatcher.php:71-81`) — defensible since the CF API is independent of the WP site, but undocumented.

**Scale (100+ sites):** fine — one dispatcher query, 2 API calls per zone per 6h, per-connection rate limiting. Only the DNS-records truncation and the (proposed) snapshot writer need attention to the 30-URL/pagination/rate-limit boundaries.

**Competitive note (→ Part C):** live analytics + purge already exceed ManageWP/WP Umbrella (no CF integration) — WOW candidates: DNS record editing, one-click Under-Attack mode, CF IP-block wired to the security module via the Rulesets API, and auto-purge after plugin updates.

## Incident Response (AI)

**Status: Complete (functional, opt-in, least production-proven).** The module is genuinely wired end-to-end — VERIFIED: scheduler dispatch every 5 min (`routes/console.php:44-49`), proactive triggers for active fixable vulnerabilities and critical/high open security issues (`app/Dispatchers/IncidentResponseDispatcher.php:31-94`), event trigger on `SiteWentDown` (`app/Listeners/TriggerIncidentResponse.php`), a two-tier responder (playbooks first, Claude tool-use agent fallback, human escalation last — `app/Services/IncidentResponse/IncidentResponderService.php:78-116`), 5 playbooks, a 14-tool executor, and a dedicated Horizon supervisor (`config/horizon.php:270-281`, queue `incident-response`, tries 1, timeout 900). Master switch defaults **off** (`config/incident-response.php` `enabled => env(..., false)`), with runtime override from admin settings (`app/Providers/IncidentResponseConfigServiceProvider.php`). Whether it is enabled in production could not be verified read-only (DB-backed setting).

**What's demonstrably solid (VERIFIED):** prior audit fixes landed — the SEC-A2-04 schema crash is fixed with regression comments (`IncidentResponseDispatcher.php:66-69`, `ContextGatherer.php:92-99`) and SEC-A2-11 orphaned-cooldown poisoning has both a `failed()` handler resolving by natural key (`app/Jobs/RunIncidentResponse.php:65-82`) and a belt-and-braces 15-min stale sweep (`routes/console.php:51-65`). Guardrails are real: per-type 30-min cooldown, 3/site/hour cap (`IncidentResponderService.php:118-135`), per-incident action (10) and AI-call (5) limits (`app/Models/IncidentResponse.php:120-128`), dedupe via `ShouldBeUnique` keyed on site+trigger (`RunIncidentResponse.php:39-42`), and correct composite indexes for the cooldown queries (migration `2026_05_14_000001`, lines 35-36). Cross-tenant safety of AI-supplied IDs is enforced and tested: rollback points are site-scoped (`IncidentActionExecutor.php:207-219`), plugin actions resolve through `$site->sitePlugins()->findOrFail` (`app/Services/PluginManagerService.php:90,121`), covered by `tests/Feature/Authorization/CrossTenantActionsTest.php:67-84`. The Claude tool-use loop itself is correctly implemented (stop_reason handling, tool_result echo, terminal tools, token accounting — `app/Services/IncidentResponse/AiAgentService.php:39-88`). Test coverage is good for a new module: 6 test files, ~35 cases spanning dispatcher schema-reality checks, guardrails, playbook routing, executor actions, and worker-death cleanup.

**Findings (Part A):**

- **IR-01 · High · Fix — backup invariant silently void.** `IncidentActionExecutor::createBackup` sets `backup_created=true` and reports success even when the site has **no backup config** (`IncidentActionExecutor.php:226-241`), and `CreateBackup::handle` returns silently without a backup when the site operation lock isn't acquired or a duplicate is detected (`app/Jobs/CreateBackup.php:96-98,113-117`). The `always_backup_before_destructive` guard (`IncidentActionExecutor.php:92-100`) is then satisfied by a lie, and the AI is told "Database backup created" before deactivating/updating/rolling back on a live client site. *Safe-for-live:* code-only guard; sites without backup configs shift from "act without backup" to "escalate" — the intended posture.
- **IR-02 · High · Fix — infinite re-trigger loop.** The dispatcher's only suppression is the 30-min cooldown window (`IncidentResponseDispatcher.php:44-49,78-83`); Escalated/Failed incidents don't block re-selection, and source rows are never marked handled — even a *successful* vulnerability fix re-triggers until the next vuln scan clears the alert (playbook then fails "no update available" → AI tier → tokens burned). One stuck critical issue = up to 3 incidents/site/hour, each ≤5 Claude calls + ≤10 site actions, indefinitely. Unbounded API cost and repeated mutations on client production sites. *Safe-for-live:* dispatcher-query change; strictly reduces actions on live sites.
- **IR-03 · High · Fix — escalation can be invisible.** `incident_response_resolved|escalated|failed` are emitted (`IncidentResponderService.php:137-167`) but absent from `NotificationTemplate::EVENTS` (`app/Models/NotificationTemplate.php:45+`), which the channel-subscription UI treats as the single source of truth (`channel-form.blade.php:96-103`); any channel with explicit subscriptions drops them (`NotificationChannel.php:76-83`). There is **no UI surface at all** for `incident_responses` (verified: no Livewire/Blade references outside the settings page). Tier-3 "escalate to human" can terminate into a void. *Safe-for-live:* additive enum entries + read-only UI.
- **IR-04 · Medium · Harden — bypasses safe-updates opt-in.** Incident `update_plugin` calls `SafeUpdateService` directly (`IncidentActionExecutor.php:168-198`); `safe_updates_enabled` is enforced only in the manual UI path (`app/Livewire/Traits/WithPluginManagement.php:25`). Sites that opted out of automated updates still get them via vulnerability incidents.
- **IR-05 · Medium · Harden — prompt injection surface.** Untrusted site output (debug.log tail, fatal errors, activity text) is embedded verbatim into the agent prompt (`ContextGatherer.php:112-131`, `AiAgentService.php:219-238`) while the agent holds same-site mutating tools — a compromised plugin could steer it into e.g. deactivating a security plugin. Blast radius is same-site only (scoping verified above), but real.
- **IR-06 · Medium · Fix — deprecated models + no retry.** Default `claude-sonnet-4-20250514` and allowlisted `claude-opus-4-20250514` (`config/incident-response.php`; validation at `AiIncidentResponseSettings.php:67`) are deprecated and **retire 2026-06-15** (verified against the current Anthropic model catalog); only the Haiku ID is current. `callClaude` makes a single un-retried call (`AiAgentService.php:90-121`) — 404 after retirement, or a transient 429/529 today, silently kills the AI tier with only a log warning.
- **IR-07 · Medium · Harden — nested sync work vs 900s timeout.** `CreateBackup::dispatchSync` (own budget 2700s, `CreateBackup.php:36`) and synchronous `runSafeUpdate` (which dispatchSyncs another backup, `SafeUpdateService.php:56-59`) run inside a 900s job (`RunIncidentResponse.php:27`, `horizon.php:279`); a large-site backup gets the worker SIGKILLed mid-operation. Cleanup marks the incident Failed, but WP-side tasks continue unmanaged.
- **IR-08 · Low · Harden — API key round-trips to browser** decrypted via a public Livewire property (`AiIncidentResponseSettings.php:46-53`); admin-gated (`routes/web.php:192`) but should be write-only/masked.
- **IR-09 · Low · Harden — no retention** for `incident_responses`/`incident_response_actions` (up to ~50KB `ai_context` jsonb per row, `AiAgentService.php:172-181`; truncation slices to last-4 without re-checking size); `RetentionPolicyService` has zero coverage (verified).
- **IR-10 · Low · Harden — guardrail skips logged as errors.** Cooldown/hourly-limit are RuntimeExceptions (`IncidentResponderService.php:36-45`) caught and logged at error level (`RunIncidentResponse.php:59-62`); the SiteWentDown listener path trips this routinely, polluting error logs.

*Minor/INFERENCE:* `SiteDownPlaybook::isElementorRelated` substring-matches "elementor" across the whole diagnostic JSON (`SiteDownPlaybook.php:118-123`) — over-broad but the fix routine is non-destructive. `IncidentResponseConfigServiceProvider` reads settings on every boot; assumed cached by `SettingsService` (not traced).

**Scorecard:** Correctness 3 · Stability 3 · Scale 4 · Security 3.

**Competitive note (Track 2 / Part C):** none of WPMUDEV Hub, ManageWP, or WP Umbrella ship an autonomous AI incident responder — this is a genuine WOW differentiator once IR-01/02/03 are fixed and it can be enabled with confidence; the escalation UI (IR-03) is also the piece that turns it into a demoable client-facing story ("we detected, diagnosed, backed up, fixed, and verified — automatically").

---

## Part B — Cross-cutting / architecture audit
*Track 1 — system-wide concerns.*

## Auth & multi-tenancy

**Status: Partial.** The isolation model is structurally sound on the **write path** — a single `WithSiteAuthorization` trait (`app/Livewire/Traits/WithSiteAuthorization.php`) plus `SitePolicy`/`BackupPolicy`/`ClientPolicy` gate per-site mutations, and `RequireRole` middleware (`app/Http/Middleware/RequireRole.php`) + `role:admin` route groups protect settings. `User::canAccessSite()` (`app/Models/User.php:112-129`) correctly encodes owned-OR-assigned-client-OR-admin. The `RestoreConfirmation` component is exemplary: it re-checks `backup->site_id === site->id` and runs the policy on every entry point (`app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:99-110`). Bulk site actions are scoped (`WithBulkSiteActions.php:18-19`).

The **read path and a few bulk actions, however, break tenant isolation** for non-admin roles, and the two scoping mechanisms in use disagree.

### Cross-tenant read leakage on global pages (High/Medium)
The primary dashboard is fully unscoped: `DashboardService::getSitesOverview()` and `computeStats()` (`app/Services/DashboardService.php:31-42,230-297`) query `Site` with no user filter, and `GlobalDashboard.php:64` serves that to every authenticated user. A Manager or Viewer therefore sees the **entire portfolio** — every client's site name, URL, health, updates and backup status — despite `SitesList.php:40`, `SecurityDashboard.php:34` and `MaintenancePlans.php:48` scoping non-admins to their own sites. The same unscoped-read pattern repeats in `Updates/UpdatesOverview.php:31-118`, `Uptime/UptimeOverview.php:130-165`, `Backups/BackupsOverview.php:33,116`, `Reports/ReportsOverview.php:90` and `Components/GlobalSearch.php:40-59`. Per-item mutating methods on these pages *are* re-authorized, so this is enumeration/read leakage rather than a write hole — but it contradicts the isolation the rest of the app enforces.

### Cross-tenant config write via CopySettingsModal (High)
`CopySettingsModal::apply()` (`app/Livewire/Components/CopySettingsModal.php:62`) refetches `Site::whereIn('id', $selectedSiteIds)` with **no user scoping and no viewer guard**, then pushes security/tweak/module config to each target through `BulkSettingsCopyService`. `$selectedSiteIds` is client-controlled and `getAvailableSites()` only scopes the *display*, so any user who can open the modal for one accessible site can inject arbitrary IDs and mutate other tenants' live WordPress sites — a genuine IDOR. `mount()` also performs no authorization on the source site.

### Missing authorization on a bulk action (Medium)
`ReportsOverview::generateAllReports()` (`Reports/ReportsOverview.php:47-62`) has **no role check and no scoping** — a Viewer can queue report generation for every connected site across all tenants. Its siblings `backupAllSites()` (`BackupsOverview.php:239`) and `addMonitorsForAllSites()` (`UptimeOverview.php:135`) correctly guard viewers, making this an inconsistency.

### Divergent scoping definitions (Medium)
List scopes use `where('user_id', auth id)` only (`SitesList.php:40`, `SecurityDashboard.php:34`, `WithBulkSiteActions.php:19`, `CopySettingsModal.php:102`), but authorization uses `canAccessSite()` which *also* grants via `assignedClients`. A client-assigned Manager can open/modify a site's pages (policy passes) yet that site never appears in their lists — the client-assignment tenancy path is half-wired, and a naive "fix" to align lists to the policy is a leak waiting to happen.

### Lower severity
`UserManagement::updateRole()` (`Settings/UserManagement.php:110-118`) relies solely on route middleware (Livewire's update endpoint does not re-run route-specific middleware) and writes the role string without validating against `UserRole::cases()`. `SetCurrentSite` middleware (`app/Http/Middleware/SetCurrentSite.php:15-24`) resolves any site by id and shares it to the view before the component's own authorize runs (defense-in-depth gap only).

**Recommended remediation (safe-for-live):** introduce one canonical `Site::visibleTo($user)` scope mirroring `canAccessSite()` and apply it to every list/search/stat query and every "all sites" bulk action; add explicit `isViewer()`/`canAccessSite()` guards to `CopySettingsModal::apply()` and `generateAllReports()`. All changes are read-only tightenings or additive guards — admins retain full visibility, no migration is required — and each should ship with a per-role regression test asserting a Manager/Viewer sees and touches only their own sites.

## Connector protocol + rollback

**Scope:** `WordPressHttpClient` HMAC signing, the connector permission chain (IP allowlist -> rate limit -> HMAC), timestamp/nonce/replay, self-update + rollback, `api_key_hash`, key rotation.

**Status:** Partial. The core POST push protocol is cryptographically sound — HMAC over `METHOD|PATH|TS|NONCE|BODY` with `hash_equals` constant-time comparison (`WordPressHttpClient.php:86-94`, `class-authentication.php:76-105`), encrypted-at-rest secrets (`Site.php:193-194`), nonce anti-replay, and a 300s timestamp window. A spoofed agent without the secret cannot act. But there is a live silent-failure bug on the GET path, and the recovery-oriented mechanisms (rollback, key rotation) have no safety net.

**Scorecard:** Correctness 3 · Stability 3 · Scale 3 · Security 3.

### Findings

**H1 — GET-with-body HMAC mismatch silently kills error-log ingestion fleet-wide.** `request()` computes the signed body from `$data` (`WordPressHttpClient.php:255`) but the GET branch sends no body (`:128-129`); WP recomputes over an empty body (`class-authentication.php:76,89-97`), so the signatures never match. Callers pass filter args in the `$data` slot instead of `$queryParams`: `ManagesErrorLogs.php:11` (`limit`) and `ManagesSiteInfo.php:40` (theme integrity `slug`). Production logs show **2176 `INVALID_SIGNATURE` failures across 30 daily log files** (2026-06-12 → 2026-07-11), on essentially every site. `FetchPhpErrorLogs.php:101-102` swallows the 401 as a warning, so `php_error_logs` is never populated and the PHP-fatal notification never fires. Fix: move the args to the query-param slot (connector already reads them from the query) — manager-only, backwards-compatible, no connector push.

**H2 — API key rotation can permanently brick a site.** `class-key-rotation-endpoint.php:26-27` writes the new key/secret immediately and returns them in the body; `SiteOverview.php:408-413` only stores them on a successful response. A response lost after the WP write desyncs the two ends with no automated recovery — the site becomes unmanageable until manual wp-admin regeneration. Needs a two-phase rotation with a dual-key overlap window.

**M1 — Rollback has no pre-snapshot and no post-verification.** `class-rollback-endpoint.php` (plugin/theme/core) upgrades to a target version with no backup and no health check; `RollbackService.php:38-55` marks the point `used` without confirming the site is alive. `SafeUpdateService.php:150-152` uses this same unguarded path as its auto-rollback net, so the net can itself brick the site. This directly answers the charter's "does rollback actually recover?" — not guaranteed.

**M2 — Safe-update pre-backup is DB-only and silently skipped without a backup config.** `SafeUpdateService.php:56-59` backs up only the database and only when `backupConfig` exists; a file-corrupting update is unrecoverable and, absent a config, no backup is taken at all.

**M3 — Nonce replay race without a persistent object cache.** `class-authentication.php:110` relies on `wp_cache_add` atomicity, which on default WordPress (no persistent object cache) is per-process; concurrent replays within the 300s window can both pass. Use an atomic DB reservation instead.

**L1 — IP whitelist trusts spoofable forwarded headers** (`class-ip-whitelist.php:194-215`) on non-Cloudflare sites — defense-in-depth only (HMAC still enforced), but false assurance.

**L2 — Legacy no-nonce HMAC branch** (`class-authentication.php:87-95`) is unreachable from real traffic but keeps a replay-unprotected path alive; remove after fleet rollout.

**L3 — RCE blast radius.** Self-update accepts any `download_url` with optional `expected_hash` (`class-self-update-endpoint.php:21-116`; `connector:update` omits the hash entirely). A spoofed agent cannot exploit this (no secret), but a compromised manager can push arbitrary code to all sites. Make `expected_hash` mandatory and constrain the host; consider asymmetric package signing.

**Note on `api_key_hash`:** deterministic SHA-256 (`Site.php:216`), indexed, currently referenced by nothing after the agent-pull removal (PR #45) — harmless vestige, not a leak. **Test coverage:** `RollbackServiceTest`/`SafeUpdateServiceTest` mock the API and never exercise HMAC signing or the GET-body path — which is why H1 shipped undetected.

## Horizon / queues / scheduler

**Status: Complete** — a mature, recently-hardened topology. Six dedicated supervisors (`config/horizon.php:207-282`) with per-queue wait thresholds for all nine queues (`config/horizon.php:99-109`); the previously-suspected backups-vs-notifications starvation is structurally impossible now (separate supervisors, `config/horizon.php:233-256`).

**Verified sound (do not re-report):**
- **E-05 closed.** Redis `retry_after` defaults to 7200s (`config/queue.php:70`), comfortably above the longest job timeout (RestoreBackup 3600s, `app/Jobs/RestoreBackup.php:35`) — no double-execution window. `after_commit: true` (`config/queue.php:72`) is correct for PgBouncer transaction pooling.
- **Escalation loop fixed** (MEMORY concern): escalation-generated logs are born `escalated` (`app/Jobs/SendNotificationJob.php:94-97`), so A→B/B→A rule pairs cannot loop (`app/Jobs/ProcessNotificationEscalations.php:62-76`).
- **Stuck-work recovery is genuinely good for backups/restores**: heartbeat-based detection with bounded auto-retry and no-retry-on-restore (`app/Dispatchers/BackupDispatcher.php:179-274`), plus `backups:recover-stuck-restores` every 15 min (`routes/console.php:253-256`) and the incident-response stale sweep (`routes/console.php:54-65`).
- **Observability exists**: `horizon:health-check` every 5 min sends *synchronously* so the alert can't sit in the dead queue (`app/Console/Commands/HorizonHealthCheckCommand.php:28-36`); external dead-man heartbeat every minute (`routes/console.php:168-171`); `LongWaitDetected` → notification and a repeated-failure alert via `Queue::failing` (`app/Providers/AppServiceProvider.php:110-142`).
- **Deploy safety**: horizon `stop_grace_period: 3660s` exceeds the longest job timeout (`docker-compose.prod.yml:72`); Horizon UI is admin-gated behind web+auth (`app/Providers/HorizonServiceProvider.php:32`, `config/horizon.php:86`).

**Findings:**

1. **High / Harden — Horizon container OOM budget mismatch.** Container hard limit is 1024M (`docker-compose.prod.yml:92`) while supervisor worker *thresholds* total >5GB — backups alone allow 3 workers × 1024MB (`config/horizon.php:240,295`). Worker `memory` is a graceful self-restart hint, not a cap; two concurrent large backups invite a cgroup OOM SIGKILL mid-backup/restore — the exact failure `stop_grace_period` was added to prevent, and the trigger for finding 2. *Safe fix:* config/env only — raise the container limit or shrink per-worker budgets/worker counts.

2. **High / Fix — ~20 `ShouldBeUnique` jobs without `uniqueFor` → permanent lock after a hard kill.** `CheckUptime` is the worst case (`app/Jobs/CheckUptime.php:23,39-42`): its per-monitor unique lock has no TTL, `next_check_at` only advances inside `handle()` (`:219-223`), and the dispatcher's re-dispatch is silently swallowed by the stale lock (`app/Dispatchers/MonitoringDispatcher.php:63-71`) — that client's uptime monitoring dies silently until Redis is flushed (volatile-lru never evicts no-TTL keys, `docker-compose.prod.yml:290`). Same pattern in `SyncWordPressSite`, `RunSecurityScan`, `FetchAnalyticsData/SearchConsoleData/KeywordRankings`, `PushSecuritySettings/SiteTweaksSettings`, `SyncCloudflareZone`, `RunPerformanceTest`, `RunIncidentResponse`, `RunSafeUpdate`, integrity/vulnerability checks, status-page jobs. Only backup/restore jobs have lock recovery (`BackupDispatcher.php:249,302-303,353-354`). *Safe fix:* additive `uniqueFor ≈ 2-3× timeout` on every such job.

3. **Medium / Harden — scheduler mutexes can stick 24h, invisibly.** All dispatchers use bare `->withoutOverlapping()` (24h default TTL, `routes/console.php:17-49`) and the scheduler container has no `stop_grace_period` (10s default; `docker-compose.prod.yml:95-128`). A SIGKILL mid-dispatcher-run (deploy recreate, host OOM) freezes e.g. `monitoring-dispatcher` — no uptime/security/DNS/PageSpeed dispatch for up to a day — while the heartbeat keeps pinging green (it's a separate task) and `horizon:health-check` sees healthy supervisors. *Safe fix:* `->withoutOverlapping(10)` on minute-cadence tasks + `stop_grace_period: 30s` on the scheduler + a "dispatcher ran recently" assertion in the heartbeat.

4. **Medium / Fix — `ProcessNotificationBatch` is at-most-once.** It LPOP-drains the whole buffer into memory before dispatching (`app/Jobs/ProcessNotificationBatch.php:36-42`) and inherits the notifications supervisor's 30s timeout (`config/horizon.php:254`). A big batch during an incident storm gets killed mid-loop; the retry finds an empty buffer — alerts silently dropped exactly when they matter. The schedule-level `withoutOverlapping` (`routes/console.php:210-214`) guards only the dispatch, not execution. *Safe fix:* LMOVE-to-processing-list pattern + explicit `$timeout` + per-run cap.

5. **Medium / Harden — uptime throughput at the 100+ site target.** 2 workers (`config/horizon.php:212,287`), 30s worst-case checks: a handful of simultaneously-down sites saturates the queue and delays every client's checks. LongWait alert fires at 30s but remediation is manual. *Safe fix:* env-only worker bump (budgeted against finding 1); longer term, pooled async checks.

6. **Low / Harden — queue misassignment.** `ProcessNotificationEscalations`, `SendDailyDigest`, `CreateStatusPageIncident`/`ResolveStatusPageIncident` and `RunSecurityScan` default to the lowest-priority `default` queue on supervisor-general (no `onQueue()`; queue order `config/horizon.php:259`) behind 900s SEO crawls (`app/Jobs/CrawlSitePages.php:33`) — public status pages can lag a real outage. *Safe fix:* one-line `onQueue()` additions.

7. **Low / Fix — repeated-failure alert race.** Non-atomic `Cache::get`+`put` counter and strict `=== 3` gate (`app/Providers/AppServiceProvider.php:125-129`) can skip the alert entirely under concurrent failures. *Safe fix:* `Cache::increment` + `>= 3` with a notified flag.

**Test coverage:** thin for this layer — `tests/Feature/Dispatchers/` has 3 files/11 tests (backup-restore interplay, incident-response, performance dispatch); no tests for `MonitoringDispatcher`/`DataSyncDispatcher` due-selection, unique-lock behavior, or supervisor/queue-name consistency.

**Scorecard:** Correctness 4 · Stability 3 · Scale 3 · Security 5.

## Backup/restore subsystem integrity (cross-cutting, highest data risk)

**Status: Complete — recently hardened, with confirmed residual logic defects.** All claims VERIFIED in code unless marked otherwise.

### What is genuinely good (verified)
- **Cross-operation site lock on a non-evictable DB store** (`app/Services/Backup/SiteOperationLock.php:44-104`, store `database` per :103) with token-ownership release, re-entrancy plumbing and TTL 7200s — E-06 properly fixed (PR #37).
- **Restore re-run guard**: a redelivered restore whose predecessor died mid-flight refuses to auto-re-run (`app/Jobs/RestoreBackup.php:100-115`); `tries=4/maxExceptions=1` exists only to wait politely on the lock.
- **Mandatory pre-restore safety backup** with typed-domain bypass only after a *failed* safety backup, IDOR-checked and policy-gated (`app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:99-110, 215-277`; `app/Policies/BackupPolicy.php:27-34`).
- **Real integrity verification**: Level A on every backup before `completed` (sha256 + ZIP `CHECKCONS` + SQL-dump parse + files-presence, `app/Services/Backup/IntegrityVerifier.php`), weekly Level B re-download sampling (`routes/console.php:153` → `VerifyBackupRestoreCommand`), on-demand "Test restore" (`RunBackupVerification`), checksum re-verify at restore time (`RestoreBackup.php:342-351, 387-394, 587-591`) and before replication (`ReplicateBackup.php:113-119`).
- **3-2-1 replication** with idempotent replica recording under row lock (`ReplicateBackup.php:140-159`) and **replica fallback at restore time** (`RestoreBackup.php:244-284`).
- **E-05 resolved (INFERENCE on env)**: `retry_after` default 7200 (`config/queue.php:70`) exceeds every backup/restore timeout; production `.env` unverifiable read-only.

### Findings

1. **HIGH / Fix — Days-based retention can leave a site with zero restorable backups.** Days mode loads only rows older than the cutoff (`RetentionService.php:36-41`), so a chain whose full crossed the cutoff is deleted (:73-84) even when its incrementals are newer than the cutoff; the FK nulls their `parent_backup_id` (`pgsql-schema.sql:7482`) and `cleanupOrphans` (:271-283) deletes those *recent* restore points in the same pass. With weekly fulls + daily incrementals + 7-day retention this fires weekly, and the pass runs after every successful backup (`CreateBackup.php:630/1054`). Test suite covers days mode only with standalone fulls (`RetentionServiceTest.php:85-96`). *Safe-for-live:* read-logic-only fix (gate chain deletion on newest chain member, age-gate orphan cleanup); interim: switch affected sites to count-based retention.

2. **HIGH / Fix — Dispatcher falsely fails live restores at 30 min and blind-steals the site lock.** `BackupDispatcher::recoverStuckRestores` (`:226-250`) marks `in_progress` restores failed after 30 min of `updated_at` silence and calls unconditional `SiteOperationLock::forceRelease` — but `sendRestoreData` is one heartbeat-free HTTP call of up to 1800s (`RestoreBackup.php:928-933`) and the job's own timeout is 3600s. It contradicts the deliberately-safe PR #38 command (75-min threshold, ownership-checked release: `RecoverStuckRestores.php:28-29, 64-71`, comment "Never blind-force"). Consequence: false "half-restored" critical alerts *and* a scheduled backup can start against a site mid-restore. *Safe-for-live:* remove the dispatcher branch (the command already covers it), never lower the command's threshold.

3. **HIGH / Fix — Zero-change incrementals always fail.** With no changed files (`CreateIncrementalBackup.php:162-171`) the v3-zip has no `files/*` entries but `meta.type='incremental'` (:356), and `verifyV3Zip` requires files for any type ≠ `database` (`IntegrityVerifier.php:248-263`) → backup failed, `backup_ok=false`, failure alert, no restore point that day. Common on quiet sites; recurring false alarms at fleet scale. *Safe-for-live:* verifier branch for incrementals + unit test.

4. **HIGH / Fix — Safe updates run outside the SiteOperationLock; the safety backup can silently no-op.** `OPERATION_SAFE_UPDATE` (`SiteOperationLock.php:38`) is referenced nowhere; `RunSafeUpdate` acquires nothing; `SafeUpdateService.php:58` runs `CreateBackup::dispatchSync` **without** `heldLockToken`, so under lock contention `acquireSiteLock` returns false (`BackupJobTrait.php:166-191`) and `handle()` returns silently (`CreateBackup.php:96-98`) — the client site is then updated with **no pre-update backup and no error**. Updates/rollbacks can also interleave with backups/restores. *Safe-for-live:* acquire the lock in `RunSafeUpdate`, pass the token down, hard-abort on a skipped/failed safety backup.

5. **MEDIUM / Fix — Selective-restore file browser broken for all new (v3-zip) backups.** `BackupBrowserService::listContents` only recognizes legacy `files.zip` (`:56`); v3-zip (`files/` subtree, the only pull-flow write path since `CreateBackup.php:140-142`) and v2-chunked report `has_files=false / 0 files`, so granular restore is unavailable and backups look file-less. The precache job is dispatched only from `CreateBackup::createArchive` (`:450`) — dead code: the whole `createArchive/verifyIntegrity/finalize` trio (`CreateBackup.php:387-453, 1080-1208`) is unreachable (tech debt). *Safe-for-live:* central-directory listing fix, read-only.

6. **MEDIUM / Harden — 20-min stuck-backup heartbeat vs heartbeat-free uploads can double-run a backup.** No `updated_at` touches during `driver->upload` (`CreateBackup.php:1104-1119`; no callbacks in `S3Driver.php:63-78` / `DropboxDriver.php:119-183`); >20-min uploads trigger `autoRetryBackup` (`BackupDispatcher.php:185-188, 279-328`) which re-dispatches the SAME `backupId`, and `prepare()` rejects only `Cancelled` (`CreateBackup.php:156-165`), so a Completed backup can be flipped back and fully re-run. *Safe-for-live:* heartbeat in upload loops + Completed-guard in `prepare()`.

7. **MEDIUM / Harden — Temp-space leaks can halt fleet backups.** `backup:cleanup-temp` covers only `backup-*` dirs and `php*` files (`CleanupBackupTemp.php:43,67`); orphaned `restore-*`/`replicate-*`/`verify-*`/`browse-*` dirs and multi-GB `restore-{token}` staging copies (`RestoreBackup.php:915-919`) leak after SIGKILL; `DiskSpaceGuard` then blocks ALL scheduled backups below 10 GB free with a log-only alert (`DiskSpaceGuard.php:24-57`; `BackupDispatcher.php:34`). Restores need ~3× archive size (`RestoreBackup.php:377-440, 915-919`) with no disk precheck. *Safe-for-live:* extend cleanup prefixes (age-gated), warn-level restore disk precheck, real notification when the guard blocks.

8. **MEDIUM / Harden — Large-site restore duration ceiling.** Job timeout 3600s vs two 1800s WP apply calls plus multi-GB download/extract/repack (`RestoreBackup.php:35, 781-787, 928-933`; chain restores multiply downloads `:548-684`); WP-side `set_time_limit(1800)` (`class-backup-endpoint.php:1617`) may be host-capped (INFERENCE). A killed restore leaves the client site half-restored (alerts fire, but downtime is real). *Safe-for-live:* async apply-with-polling in the connector, capability-gated (fleet rollout of 2.17.0 still pending per memory).

9. **LOW / Harden —** (a) Level B / on-demand verify routes v3-zip through legacy `verifyArchive` (still sha256+CHECKCONS+DB-parse but skips the has-files assertion; no replica fallback so an unreachable primary false-alarms a healthy replicated backup: `BackupVerifier.php:31-47`, `VerifyBackupRestoreCommand.php:68-93`, `IntegrityVerifier.php:141-157`). (b) Zip entry names from the semi-trusted WP site are never sanitized before `extractTo` on the manager (`BackupZipBuilder.php:120-123`; `RestoreBackup.php:359, 401` — traversal exposure PHP-version-dependent, PLAUSIBLE). (c) `restore-download/{token}` serves the full site archive unauthenticated during the restore window — acceptable given 256-bit token + post-request unlink (`routes/web.php:39-50`); noted for completeness. (d) Scale: 2 backup workers + 3-min stagger ⇒ ~5h dispatch spread at 100 sites (`horizon.php:233-243`; `BackupDispatcher.php:60-62`), env-tunable.

### Test coverage
Good on the lock/recovery seams (`SiteOperationLockTest`, `RestoreBackupLockTest`, `RecoverStuckRestoresTest`, `BackupDispatcherRestoreTest`, `RestoreConfirmationTest`, `RetentionServiceTest`, `RestoreVerificationTest`) — but all four HIGH defects sit in *untested* combinations (days-retention × incremental chains; dispatcher-vs-job timing; zero-change incrementals; safe-update × lock). A true end-to-end restore against a staging WP site is unverifiable read-only — keep the weekly Level B sweep and add a periodic real restore drill as a follow-up.

### Scorecard
Correctness 3 · Stability 3 · Scale-readiness 3 · Security 4

## Config, secrets, Docker, deploy

**Status: Complete.** This area was substantially hardened through PRs #9/#12/#32/#35/#37: CI-gated deploys pinned to the exact SHA (`deploy.sh:40-59`), migrations run on a direct-Postgres connection bypassing PgBouncer's transaction pooling (`deploy.sh:117-125`, `config/database.php:110-122`) followed by a PgBouncer restart to clear cached plans (`deploy.sh:127-132`), and a Horizon drain with bounded timeout (`deploy.sh:70-84`).

**What is verifiably good** (all VERIFIED):
- **Secrets hygiene is clean.** `.env` was never committed (git history checked), is git-ignored and docker-ignored (`.dockerignore` — "never bake secrets into the image"), and no hardcoded credentials exist in `config/` or the compose file — everything flows through `env()` in config files. `pgsql`/`pgbouncer` use scram-sha-256 (`docker-compose.prod.yml:202,226,265`); no DB/Redis ports are published to the host — only nginx binds 80/443 (`docker-compose.prod.yml:139-141`).
- **Container hardening is above par for a small-team stack:** `read_only: true` root filesystems with scoped tmpfs mounts, `no-new-privileges`, non-root `appuser` (uid 1000), memory/CPU limits on every service, real healthchecks on app/horizon/nginx/pgsql/pgbouncer/redis/gotenberg, and a multi-stage Dockerfile with pinned PECL versions and no compiler toolchain in the runtime image (`docker/php/Dockerfile.prod`).
- **Config caching is coherent:** `entrypoint.sh:6-8` re-caches config/routes/views into tmpfs on every container start; `opcache.validate_timestamps=0` matches the immutable-image deploy model (`docker/php/opcache.ini:8`).
- **E-05 is resolved at the config layer:** Redis queue `retry_after` is 7200s (`config/queue.php:70`), safely above the longest job timeout of 3600s (`config/horizon.php:242`).
- App responses carry a nonce-based CSP plus the full security-header set via middleware (`app/Http/Middleware/SecurityHeaders.php:28-51`), complementing the nginx TLS config (TLS1.2+, HSTS, session tickets off, login rate-limit zone at `docker/nginx/nginx.conf:53`).

**Findings** (severity-ordered):

1. **High / Harden — Horizon container can be kernel-OOM-killed mid-restore.** The horizon service is capped at 1024M (`docker-compose.prod.yml:89-93`), yet `supervisor-backups` permits 2 workers × 1024MB each (`config/horizon.php:233-243`) and `RestoreBackup` raises its own limit to 1G (`app/Jobs/RestoreBackup.php:90`) — on top of the master and ten other workers. One large restore approaching its permitted footprint busts the cgroup; the kernel SIGKILLs with zero grace, defeating the 3660s `stop_grace_period` added expressly to prevent half-restored client sites (`docker-compose.prod.yml:68-72`). Mitigated but not eliminated by PR #40 (chunk streaming) and `backups:recover-stuck-restores` (PR #38). *Safe-for-live:* raise the limit to 2048M or drop backups to 1 process / 768M — compose-only, graceful redeploy, trivial rollback; don't apply while a restore runs.

2. **Medium / Fix — deploy.sh hard-kills nginx at step 6 and only recreates it at step 11** (`deploy.sh:93-100` vs `:151`). Every deploy is a connection-refused outage — the maintenance page from `artisan down` (`deploy.sh:89`) is never served because nothing is listening. With `set -euo pipefail` (`deploy.sh:2`), a failed migration or health-wait aborts the script leaving *no web server and the app in maintenance mode* until a human intervenes — taking down status pages, the client portal, and WP backup callbacks (`routes/api.php:8`). Also: images are tagged only `:latest` (`docker-compose.prod.yml:8`), so rollback requires a rebuild. *Safe-for-live:* drop nginx from the rm loop, add a failure `trap` that runs `artisan up` and recreates nginx, and SHA-tag images; script-only change.

3. **Medium / Fix — certbot renews, nginx never reloads.** The certbot loop has no deploy hook (`docker-compose.prod.yml:181-182`) and nginx reads certificates once at start (`:152-163`). If no deploy happens for ~30+ days after a renewal, the *served* certificate expires while valid PEMs sit on disk — breaking the UI, portal, status pages, and connector callbacks. Currently masked by frequent deploys. (INFERENCE on the host side: a host cron reloading nginx would moot this — could not verify read-only.) *Safe-for-live:* weekly `docker exec simplead-nginx nginx -s reload` via host cron, or a touch-file watcher; zero-downtime.

4. **Medium / Harden — app container memory math doesn't close.** 512M limit (`docker-compose.prod.yml:48`) minus opcache 256M + JIT 128M shared memory (`docker/php/opcache.ini:4,12`) leaves ~128M for 20 FPM workers (`docker/php/php-fpm-pool.conf:9`) each allowed 256M (`docker/php/php.ini:3`). Livewire-polling bursts at 100+ sites risk worker OOM-kills and 502s. *Safe-for-live:* raise to 1024M or cut `pm.max_children`/JIT buffer; config-only.

5. **Low / Harden — CI gate fails open on API error:** `gh api` failure coerces to `unknown` and the deploy *continues* (`deploy.sh:52,58`), silently bypassing the T-A2-01 gate; `DEPLOY_SKIP_CI_CHECK=1` already exists as the deliberate override, so the unknown branch should fail.

6. **Low / Harden — nginx header-inheritance gap:** the static-asset and `/build/` locations declare `add_header Cache-Control`, which discards all server-level security headers for those responses (`docker/nginx/conf.d.ssl/app.conf:37-43` vs `:68-81`). Marginal — PHP responses are fully covered by the middleware — but fix via an include file; drop deprecated `X-XSS-Protection` while there.

7. **Low / Harden — mismatched/dead limits:** nginx accepts 100M bodies (`conf.d.ssl/app.conf:48`) while PHP caps at 64M (`php.ini:1-2`); the scheduler healthcheck `php -r 'echo 1;'` can never fail (`docker-compose.prod.yml:104`) — a wedged (non-exited) `schedule:work` stays "healthy" (mitigated by the external dead-man heartbeat, PR #22).

8. **Low / Fix — `env('TRUSTED_PROXIES')` in `bootstrap/app.php:22-27`** violates the project's own no-`env()`-outside-config rule; it works today only because compose `env_file` injects real process envs and FPM keeps them (`php-fpm-pool.conf:23` `clear_env = no`). Move to config(). Related doc rot: `deploy.sh:134-136` claims config caching is skipped — false since `entrypoint.sh:6` caches on every start (and `deploy.sh:123` relies on exactly that).

9. **Low / Harden — flat network topology:** all nine services share one bridge network (`docker-compose.prod.yml:338-340`); gotenberg — which renders HTML derived from client-site data — can reach pgsql and redis. Exploitability is low (Chromium JS disabled at `:319`, scram on Postgres), but split front/back networks and confirm `REDIS_PASSWORD` is actually set in production `.env` (UNVERIFIED — file unreadable in this audit; `requirepass` applies only when non-empty, `docker-compose.prod.yml:288-293`).

**Wow candidate (Track 2):** zero-downtime deploys — SHA-tagged images, start-new-then-swap container flow, `deploy.sh rollback <sha>`. Competitors' control planes never visibly blink; SimpleAd's status pages currently go dark on every deploy.

**Test coverage:** none for the deploy path itself (expected for shell tooling); infra-adjacent guards exist (`tests/Feature/CriticalSchemaTest.php`, `tests/Unit/MigrationCollisionTest.php`). The CI gate is the effective test harness for deploys.

**Scorecard:** Correctness 4 · Stability 3 · Scale 3 · Security 4.

## Observability + platform self-protection

**Status: Complete** — this area was substantially hardened in the recent PR wave (#21/#22/#35/#37) and the architecture is genuinely thoughtful: an external dead-man's-switch heartbeat pings every scheduler tick (`routes/console.php:168-171`, `app/Console/Commands/HeartbeatPingCommand.php`), a 5-minute Horizon health check alerts via `dispatchSync` so the alarm never rides the dead queue it is reporting on (`app/Console/Commands/HorizonHealthCheckCommand.php:28-37`, `app/Services/Notifications/NotificationService.php:237-244`), all nine Docker services carry real healthchecks (`docker-compose.prod.yml`), an unauthenticated-but-throttled `/health` endpoint checks DB/Redis/Horizon/disk (`routes/web.php:33`), the platform backs itself up twice (daily `db:dump` at 02:30 + configurable app self-backup with DB/env/storage components), and `deploy.sh:37-62` gates deploys on green CI. Log discipline is sane (6 debug / 82 info / 131 warning / 39 error call sites in `app/`; Postgres slow-query logging at 1s, `docker-compose.prod.yml:224`).

**Scorecard:** Correctness 3 · Stability 4 · Scale 4 · Security 4

### Findings (Part A/B — Track 1)

- **High / Fix — `db:dump` succeeds even when `pg_dump` fails.** The dump pipeline `pg_dump | gzip > file` runs via `exec()` which sees only gzip's exit code, and the appended `2>&1` lands after the file redirect so gzip's stderr is written into the `.sql.gz` artifact itself (`app/Console/Commands/DatabaseDumpCommand.php:47-69`). No size/content validation (contrast `AppBackupCreator.php:296-298`, which checks). A failed dump logs "Dump created" and 7-day retention rotates the last good dump away. *Safe-for-live:* command-internal fix (pipefail or two-step dump + size check + `gunzip -t`), zero client risk.
- **High / Harden — self-backups may never leave the host.** `db:dump` writes only to the local `app-storage` volume (`DatabaseDumpCommand.php:26`); app backups silently fall back to local disk when no StorageDestination resolves (`app/Services/AppBackup/AppBackupCreator.php:143-150`, resolver at 249-263) with a success notification indistinguishable from an off-site upload. DB volume, dumps, and fallback backups share one host (`docker-compose.prod.yml:342-354`); no WAL archiving, RPO ≈ 24h. Whether production actually has a remote destination configured is DB state — **INFERENCE, verify**. *Safe-for-live:* config verification + additive loud-fallback warning.
- **Medium / Fix — stuck `in_progress` app backup blocks all future app backups.** Guard at `AppBackupCreator.php:31` has no staleness bound; a SIGKILLed worker (tries=1, `app/Jobs/CreateAppBackup.php:21-23`) leaves the row in_progress forever. Site restores got a recovery sweep (`routes/console.php:253-256`); app backups did not. Mitigation: repeated failures do fire critical notifications (`CreateAppBackup.php:46-64`). *Safe-for-live:* additive sweep mirroring `backups:recover-stuck-restores`.
- **Medium / Harden — no branch protection on `main`; CI gate fails open.** VERIFIED live via GitHub API: branch unprotected, rulesets empty. `deploy.sh:58` continues on indeterminate CI status; `deploy.sh:61` skips the gate if `gh` is missing. The manager holds write-capable HMAC credentials for every client site — a red deploy is fleet-blast-radius. *Safe-for-live:* repo-settings change + one-line fail-closed.
- **Medium / Harden — zero `onFailure` hooks on ~30 scheduled tasks** (`routes/console.php`, grep-verified). The heartbeat proves the scheduler *ticks*, not that tasks *succeed*; nightly `db:dump` or weekly `backup:verify-restore` can fail forever with only a log line. *Safe-for-live:* additive sync alerts on the critical subset, dedup via existing `NotificationService::isDuplicate`.
- **Medium / Fix — DR circularity in the env backup.** `env.encrypted` is encrypted with `encrypt()`/APP_KEY (`AppBackupCreator.php:319-321`); in total host loss, the key needed to decrypt it was inside the file it protects. *Safe-for-live:* encrypt new backups with the separate `BACKUP_ENCRYPTION_KEY` (`config/app.php:136`) + key escrow runbook; keep legacy decrypt fallback.
- **Low / Harden — secret hygiene drift:** `AppBackupCreator.php:274` passes `PGPASSWORD` inline on the shell command line — the exact `/proc` exposure `DatabaseDumpCommand.php:34-45` was engineered to avoid; `db:dump` passes its AES key via `-pass pass:` (`DatabaseDumpCommand.php:76`).
- **Low / Harden — `getTableRowCounts()` runs `COUNT(*)` on all ~106 tables per backup** (`AppBackupCreator.php:487-504`, called at :72); seq-scans on `uptime_checks`-class tables will drag the 02:00 window at 100+ sites. Use `pg_class.reltuples`.
- **Low / Harden — scheduler container healthcheck is decorative** (`php -r 'echo 1;'`, `docker-compose.prod.yml:104`) and Docker "unhealthy" triggers no remediation anywhere; `/health` discloses component-level degradation to anonymous callers (`routes/web.php:33`). Both mitigated (heartbeat; throttle + Cloudflare) — tighten opportunistically.
- **Low / Fix — duplicate exception logging:** the production catch-all renderable logs every Throwable a second time, traceless (`bootstrap/app.php:83-88`), inflating error-rate signal in the `daily-json` channel.
- **Follow-up (unverifiable read-only):** confirm `SCHEDULER_HEARTBEAT_URL` is set in production and the external monitor actually alarms on a missed ping; confirm a default notification channel is subscribed to `horizon_stopped` (event exists in catalog, `NotificationTemplate.php:72`; channel state is DB data). Tests exist for the heartbeat and AppBackup services but **none** for `HorizonHealthCheckCommand`, `DatabaseDumpCommand`, or `HealthCheckController`.

### Competitive note (→ Part C)
No APM/error tracker exists (composer.json verified — no Sentry/Flare/Bugsnag); observability is log-file + Slack-webhook only. A "platform meta-status" surface (self-health page reusing the existing status-page module, restore-drill history, backup-freshness SLO) would exceed what ManageWP/WP Umbrella expose about their own reliability. **Wow candidate, Low priority.**

---

## Part C — Competitive gap analysis & wow opportunities
*Track 2 — kept separate from the correctness audit. Benchmarked against the current (2026) WordPress-management landscape.*

### Part C — Competitor Cluster: All-in-One WP Management

Competitors researched (current 2026 state): **ManageWP, MainWP, WP Umbrella, WPMU DEV Hub, InfiniteWP**.

## Per-Competitor Snapshot

### ManageWP (GoDaddy)
- **Model:** Freemium — free tier for unlimited sites (monthly backups, basic checks, 1-click login), premium add-ons at $1–$2/site/month (backup $2, uptime $1, security $1, performance $1, advanced reports $1, white-label $1, SEO $1, vulnerability protection $2, link monitor $1); ~$150/mo bundle for up to 100 sites. ([pricing/features](https://managewp.com/features/), [Software Advice](https://www.softwareadvice.com/website-monitoring/managewp-profile/))
- **Does better:** [Safe Updates](https://managewp.com/features/safe-updates/) backed by *incremental* off-site backups, pre/post-update HTTP checks, **before/after screenshots**, and auto-rollback on critical errors. [Uptime Monitor](https://managewp.com/features/uptime-monitor/) checks every 60s with confirmation-before-alert (fewer false positives) and Email/SMS/Slack delivery. Mature 1-click bulk everything (updates, comments, users) across hundreds of sites, plus the smoothest worker-install onboarding in the market.

### MainWP
- **Model:** Self-hosted free plugin + **MainWP Pro**: 32+ extensions for $29/mo, $199/yr, or $499–$599 lifetime — unlimited sites, no per-site fees. ([signup](https://mainwp.com/signup/), [G2 pricing](https://www.g2.com/products/mainwp/pricing))
- **Does better:** Unlimited-site bulk operations (core/plugin/theme updates, bulk content, bulk settings) with recent 6.x releases improving bulk-update speed, reporting, and a full **REST API** ([MainWP 6.x](https://mainwp.com/mainwp-6-x-updates-faster-sync-better-reporting-and-expanded-rest-api-capabilities/)). **Staging + clone** extensions (quick-clone workflow, clone-from-backup). Agency back-office depth: [Cost Tracker](https://docs.mainwp.com/add-ons/client/cost-tracker-extension), client records, [Fathom analytics](https://mainwp.com/add-on/fathom/), browser extension for time/cost. Data sovereignty — everything on your own server.

### WP Umbrella
- **Model:** Flat **€1.99/site/month** all-inclusive (backups, monitoring, updates, vulnerability scanning, reports); add-ons: hourly backups, Site Protect. ([pricing](https://wp-umbrella.com/pricing/), [features](https://wp-umbrella.com/features/))
- **Does better:** Best-in-class [Safe Updates](https://wp-umbrella.com/features/safe-updates/): **visual regression testing**, cache clearing, DB-upgrade verification, status-code + uptime checks, with *manual validation or automatic rollback* per update. **Encrypted incremental backups, 50-day retention, EU infrastructure**. In-house uptime + performance + SSL + domain monitoring. Daily fleet-wide **broken link/image/redirect scanning** surfaced in one dashboard. 2026: bulk security dashboard with [Site Protect](https://support.wp-umbrella.com/en/articles/78-everything-to-know-about-the-site-protect-powered-by-patchstack) — **Patchstack-powered virtual patching** that blocks known vulnerabilities at firewall level before patches exist. Frictionless onboarding: one lightweight plugin + API key, bulk onboarding, [bulk plugin deploy](https://support.wp-umbrella.com/en/articles/51-how-to-use-the-bulk-upload-plugin-feature-on-wp-umbrella-s-dashboard) to many sites at once.

### WPMU DEV Hub
- **Model:** Tiered: ~$2.50/mo (1 site) → $5 (3) → $8.33 (10) → $83.33/mo unlimited; higher tiers unlock white-label billing, reseller, CDN/backup storage, hosting credits. ([pricing](https://wpmudev.com/pricing/), [G2](https://www.g2.com/products/wpmu-dev/pricing))
- **Does better:** **[Client Billing](https://wpmudev.com/docs/hub-2-0/client-billing/)** — create and sell product/service packages, Stripe subscriptions, 0% fees on Premium — the only competitor with revenue tooling built in. **[Hub Client](https://wpmudev.com/project/the-hub-client/)** white-label portal running on the agency's own domain. [Automate Safe Upgrade](https://wpmudev.com/docs/hub-2-0/automate-updates/): pre-update backup, screenshots of homepage + 5 pages, post-update visual diff with a configurable change-percentage alert threshold, rollback via pre-update backup. [Scheduled reports](https://wpmudev.com/docs/hub-2-0/reports/) covering updates/security/backups/performance/SEO/analytics/uptime.

### InfiniteWP
- **Model:** Self-hosted; tiered by site count (Starter 10 → Agency unlimited → Enterprise), all premium features per tier, 1 year updates/support; 18 add-ons (Scheduled/Cloud Backup, Wordfence, Broken Link Checker top sellers). ([pricing](https://new.infinitewp.com/pricing/), [overview](https://maqtoob.com/tool/infinitewp/))
- **Does better:** Full data control on your own server; one-time-ish cost economics at scale. Otherwise the laggard of the cluster — legacy UX, slower feature velocity. Mostly a pricing-model reference, not a feature threat.

## SimpleAd Coverage vs This Cluster

| Category | Coverage | Best competitor | Gap |
|---|---|---|---|
| Bulk operations at scale | **Partial** | MainWP / WP Umbrella | Has fleet updates + connector push, but no bulk plugin/theme *install-deploy* to many sites, bulk settings/user/content ops, or REST API for fleet automation |
| Safe updates + auto-rollback | **Partial** | WP Umbrella | Has opt-in safe updates + auto-rollback; missing **visual regression screenshots**, per-update incremental snapshot, per-update manual-validate vs auto-rollback choice |
| Uptime / health | **Full** | ManageWP / WP Umbrella | At or above parity (uptime + SSL + domain expiry + Lighthouse + escalations); only SMS channel and 60s check cadence worth confirming |
| Client reports | **Full** | WPMU DEV / WP Umbrella | HTML+PDF with recommendations approval exceeds most; white-label/scheduled delivery polish is the only delta |
| Onboarding / agent-install UX | **Partial** | WP Umbrella / ManageWP | Connector push via signed URL exists, but no self-serve "paste URL + admin creds → connected in 30s" flow, no bulk onboarding, no hosting integrations |
| Pricing / monetization model | **Partial** | WPMU DEV | Maintenance plans exist but no client billing/subscriptions (Stripe), no packaged upsells, no white-label client portal depth |

## WOW Versions Worth Building

1. **Safe Updates v2 with visual regression** — before/after screenshots of homepage + N key pages, pixel-diff with a configurable threshold, per-update choice of *auto-rollback* vs *hold for manual approval* with side-by-side diff in the UI, plus cache-clear and DB-migration verification. This leapfrogs ManageWP (screenshots but coarse) and matches WP Umbrella, and pairs naturally with the existing Lighthouse pipeline (also diff performance scores pre/post update).
2. **One-command fleet operations** — bulk deploy/activate/remove a plugin or theme across tagged site groups, bulk-apply security hardening profiles, and expose it all via an authenticated REST API so agency workflows can be scripted (MainWP 6.x parity, but SaaS-simple).
3. **Zero-friction onboarding** — a "connect a site" wizard: enter URL + one-time admin credential (or upload the connector), auto-install, auto-handshake, auto-enroll in default monitoring/backup/update policies, with CSV bulk import for portfolios. Target: 10 sites connected in under 5 minutes.
4. **Client billing + white-label portal** — Stripe-backed maintenance-plan subscriptions tied to existing client + maintenance-plan models, packaged upsells (extra backups, Site-Protect-style firewall), and a branded client portal showing live status, reports, and approved recommendations — the WPMU DEV moat, but with SimpleAd's richer monitoring data behind it.
5. **Virtual-patching security layer** — integrate a vulnerability-intelligence feed (Patchstack-style) so known CVEs are blocked at the existing IP-firewall/hardening layer before updates land, closing the "real malware/vuln protection" gap without building a signature scanner from scratch.
6. **Incremental backups** — block/file-level incrementals with 30–50 day retention on the existing S3+Dropbox pipeline; required to make Safe Updates v2 per-update snapshots cheap, and matches WP Umbrella/ManageWP.

**Sources:** [ManageWP Safe Updates](https://managewp.com/features/safe-updates/) · [ManageWP Uptime](https://managewp.com/features/uptime-monitor/) · [ManageWP Features](https://managewp.com/features/) · [MainWP signup/pricing](https://mainwp.com/signup/) · [MainWP 6.x](https://mainwp.com/mainwp-6-x-updates-faster-sync-better-reporting-and-expanded-rest-api-capabilities/) · [MainWP Cost Tracker](https://docs.mainwp.com/add-ons/client/cost-tracker-extension) · [WP Umbrella Pricing](https://wp-umbrella.com/pricing/) · [WP Umbrella Safe Updates](https://wp-umbrella.com/features/safe-updates/) · [WP Umbrella Site Protect](https://support.wp-umbrella.com/en/articles/78-everything-to-know-about-the-site-protect-powered-by-patchstack) · [WP Umbrella bulk plugin deploy](https://support.wp-umbrella.com/en/articles/51-how-to-use-the-bulk-upload-plugin-feature-on-wp-umbrella-s-dashboard) · [WPMU DEV Pricing](https://wpmudev.com/pricing/) · [WPMU DEV Client Billing](https://wpmudev.com/docs/hub-2-0/client-billing/) · [WPMU DEV Automate](https://wpmudev.com/docs/hub-2-0/automate-updates/) · [WPMU DEV Hub Client](https://wpmudev.com/project/the-hub-client/) · [InfiniteWP Pricing](https://new.infinitewp.com/pricing/)

## Part C — Backup & Security Specialists (BlogVault/MalCare, Jetpack/VaultPress, Wordfence, Solid Security, Sucuri)

### Competitor capability profiles

**BlogVault / MalCare** (same company; the gold standard this cluster is judged against)
- Incremental backups: after the first full sync, every backup transfers only changed files/tables, processed off-server on their own cloud so there is zero load on the client site ([blogvault.net](https://blogvault.net/), [MalCare guide](https://www.malcare.com/blog/wordpress-backup-blogvault/)).
- One-click restore that works **even when the site is inaccessible**, plus a unique "test restore" that lets you preview a restoration before applying it ([FS-Poster review](https://www.fs-poster.com/blog/blogvault-review), [weDevs review](https://wedevs.com/blog/103130/blogvault-review-wordpress-backup-security/)).
- Free integrated staging on their infrastructure with selective merge back to live and live-vs-staging diff view ([blogvault.net/wordpress-staging](https://blogvault.net/wordpress-staging/)).
- MalCare side: daily-to-hourly AI malware scans (off-server), **one-click automatic malware removal**, real-time plugin firewall with bot protection, geoblocking, IP blacklisting, and activity logs; tiers Protect $99/yr → Fortify $499/yr per site ([malcare.com/pricing](https://www.malcare.com/pricing/), [bot protection](https://www.malcare.com/features/wordpress-bot-protection/), [Booknetic review](https://www.booknetic.com/blog/malcare-review)).
- What they do better than SimpleAd: incremental/off-server backup engine, restore of dead sites, staging, signature+AI malware detection with automated cleanup.

**Jetpack / VaultPress Backup + Scan/Protect**
- **Real-time backups**: full first backup, then incremental; additionally event-triggered backups on post publish, plugin/theme install/update — every change captured, stored in Jetpack Cloud ([jetpack.com/support/backup](https://jetpack.com/support/backup/), [jetpack.com/upgrade/backup](https://jetpack.com/upgrade/backup/)).
- One-click restore that works **even when the host is down**; "Immutable Real-Time Objects" guarantee WooCommerce orders are never lost during a restore ([Woo immutable objects](https://jetpack.com/support/backup/backups-via-the-jetpack-plugin/jetpack-backup-immutable-real-time-objects-for-woocommerce-sites/)).
- Jetpack Scan/Protect: daily malware scanning with **one-click fixes**, WPScan-powered vulnerability database (~53,500 vulns), automated WAF with expert-maintained rules ([jetpack.com/upgrade/scan](https://jetpack.com/upgrade/scan/), [wordpress.org/plugins/jetpack-protect](https://wordpress.org/plugins/jetpack-protect/)).
- What they do better: real-time event-triggered incremental backups, host-down restores, order-safe e-commerce restores, one-click malware fixes.

**Wordfence**
- Endpoint (PHP-level) firewall with the Threat Defense Feed: real-time firewall rule + malware signature updates on Premium (30-day delay on free), premium IP blocklist of 40,000+ threat actors, country blocking ([wordfence.com](https://www.wordfence.com/), [Premium](https://www.wordfence.com/products/wordfence-premium/)).
- Signature-based malware scanner checking core/theme/plugin files against a continuously researched signature database; detects backdoors, injected code, modified core files ([wordpress.org/plugins/wordfence](https://wordpress.org/plugins/wordfence/)).
- Security **Audit Log** (Premium) covering authentication, configuration, and content changes with configurable verbosity ([wordfence.com](https://www.wordfence.com/)).
- What they do better: real-time threat-intelligence-driven signatures/rules backed by a dedicated research team; live traffic view.

**Solid Security (formerly iThemes)**
- 30+ hardening controls: login URL hiding, passkeys/2FA, trusted devices, user groups, strong-password enforcement, file change detection, network-wide brute-force blocking ([FatLab review](https://fatlabwebsupport.com/blog/website-security/solid-security-review/), [isitwp review](https://www.isitwp.com/wordpress-plugins/ithemes-security/)).
- **Patchstack integration**: vulnerability scanning against Patchstack's database with **virtual patching** — firewall rules applied automatically for known plugin/theme vulns before official fixes ship; custom firewall rules UI ([WAFPlanet](https://wafplanet.com/waf/solid-security/), [doindigital review](https://doindigital.com/solid-security-review/)).
- Notable weakness: no malware scanner and no true WAF ([MalCare's Solid Security review](https://www.malcare.com/blog/solid-security-review/)).
- What they do better: hardening depth and automatic virtual patching of known vulnerabilities.

**Sucuri**
- Cloud (edge) WAF + Anycast CDN — traffic is filtered before it reaches the origin; custom rules; ~60% average speed increase from the CDN ([sucuri.net/website-firewall](https://sucuri.net/website-firewall-a/), [sucuri.net](https://sucuri.net/)).
- **Unlimited human malware cleanup** by an incident-response team on all platform plans, blocklist monitoring/removal (Google Safe Browsing etc.), post-cleanup reports, 24/7 support ([sucuri.net/website-security-platform](https://sucuri.net/website-security-platform/), [malware removal](https://info.sucuri.net/feature/malware-removal)).
- Server-side scanning on paid plans (remote SiteCheck is surface-only); WP plugin adds auditing + hardening ([sitecheck.sucuri.net](https://sitecheck.sucuri.net/), [WP plugin](https://sucuri.net/wordpress-security-plugin/)).
- What they do better: edge-level WAF/CDN (works even if WP is compromised) and a human cleanup guarantee.

### Category-by-category: SimpleAd coverage, gap, and the WOW version

| # | Category | SimpleAd | Best-in-class | Gap |
|---|----------|----------|---------------|-----|
| 1 | Incremental backups | **None** | BlogVault, Jetpack (real-time incremental) | SimpleAd runs full chunked backups; competitors sync only deltas, continuously, with near-zero site load |
| 2 | One-click restore | **Full** | BlogVault/Jetpack (host-down restore, Woo-safe) | SimpleAd's atomic staged restore is parity for live sites; competitors restore dead sites and protect live WooCommerce orders |
| 3 | Restore testing | **Full** | BlogVault (test restore) | SimpleAd's on-demand verification/restore-testing already matches or beats BlogVault; parity |
| 4 | Off-site storage | **Full** | Jetpack (immutable objects), BlogVault (own encrypted cloud) | S3+Dropbox with retention is parity; missing immutability/object-lock (ransomware-proofing) |
| 5 | Malware/vuln scanning | **Partial** | Wordfence (signatures), MalCare (AI scan + 1-click clean), Jetpack (WPScan DB) | Only integrity checks — no signature/heuristic malware detection, no CVE/vulnerability feed matching, no automated cleanup |
| 6 | Firewall | **Partial** | Sucuri (edge WAF), Solid Security (virtual patching), Wordfence (real-time rules) | IP-blocking + captcha only — no WAF rules, no virtual patching, no bot protection/geoblocking |
| 7 | Hardening | **Full** | Solid Security (30+ controls) | 6 hardening categories + email-code 2FA + captcha is strong; missing depth items (login URL hiding, passkeys, trusted devices, user groups) |
| 8 | Audit log | **Full** | Wordfence Audit Log, MalCare activity log | Activity log exists; gap is forensic depth and cross-signal correlation, not presence |

**1. Incremental backups — the biggest single gap in this cluster.**
WOW version: forever-incremental engine on top of the existing chunked S3 pipeline — connector reports changed files (reuse integrity-scan file hashes) and changed DB tables; manager stores deltas and periodically builds synthetic fulls server-side; add event-triggered snapshots (plugin update, post publish — hook the existing safe-updates flow so every update gets a pre/post restore point automatically). Differentiator no competitor has: every incremental chain is **automatically proven restorable** by the existing restore-testing module, surfaced as a "verified restore point" badge in client reports.

**2. One-click restore.** WOW: a connector-independent emergency path (SFTP/host-API bootstrap that reinstalls the connector or extracts the archive directly) so a white-screened, hacked, or suspended site can still be restored — matching Jetpack/BlogVault's "restore even when the site is down." Add selective restore (single file / table / plugin) and a Woo-style order-preservation option for e-commerce clients.

**3. Restore testing.** Already a differentiator — BlogVault is the only competitor with anything comparable. WOW: make it continuous and visible: auto-test every Nth backup in an ephemeral container, run an HTTP + screenshot + Lighthouse diff against production, and print "last proven-restorable backup: X hours ago" on client reports and the status page. That is a sellable guarantee none of the five offer.

**4. Off-site storage.** WOW: S3 Object Lock (WORM) immutable retention tiers per maintenance plan, per-site encryption keys, and client-visible storage location/region — pitch it as ransomware-proof backups, one step past Jetpack's Woo-only immutability.

**5. Malware/vulnerability scanning — the second big gap.**
WOW: a two-layer scanner. Layer 1: vulnerability matching — cross-reference the plugin/theme/core inventory SimpleAd already collects against a vulnerability feed (Patchstack/WPScan API), feeding the existing to-do feed and safe-updates prioritization. Layer 2: malware detection — the integrity scanner already knows *which files changed*; ship changed/suspect files to the manager and scan them off-server with YARA-style signature sets plus AI triage (extend the existing AI incident response), so heavy analysis never loads the client site (MalCare's architecture). Close the loop with one-click quarantine + auto-clean that is uniquely safe because every clean action sits on top of atomic staged restore and restore-testing.

**6. Firewall.**
WOW: skip the PHP-plugin-firewall arms race and go to the edge — SimpleAd already integrates Cloudflare, so push managed WAF rules, geoblocking, bot rules, and per-vulnerability **virtual patches** (from the Layer-1 vuln feed) to Cloudflare per site, with a connector-level fallback ruleset for non-Cloudflare sites. That combines Sucuri's edge model with Solid Security's virtual patching, orchestrated fleet-wide from one dashboard — something none of the five do across a fleet.

**7. Hardening.** Near-parity. WOW: fleet-wide hardening policy templates (define once, enforce across all client sites, drift detection alerts in the action-items feed) plus a client-facing hardening score in reports; add login-URL hiding and passkey support to close the Solid Security checklist.

**8. Audit log.** Present. WOW: correlate the activity log with every other signal SimpleAd already has (uptime dips, integrity changes, update events, firewall blocks) into a single incident timeline, with AI anomaly flagging ("admin created at 03:12 from new country, 4 minutes before file changes") — turning a compliance log into the forensic input for the AI incident-response module.

### Sources
- https://blogvault.net/ · https://blogvault.net/wordpress-staging/ · https://www.malcare.com/blog/wordpress-backup-blogvault/ · https://www.fs-poster.com/blog/blogvault-review · https://wedevs.com/blog/103130/blogvault-review-wordpress-backup-security/
- https://www.malcare.com/pricing/ · https://www.malcare.com/features/wordpress-bot-protection/ · https://www.booknetic.com/blog/malcare-review · https://wordpress.org/plugins/malcare-security/
- https://jetpack.com/support/backup/ · https://jetpack.com/upgrade/backup/ · https://jetpack.com/support/backup/backups-via-the-jetpack-plugin/jetpack-backup-immutable-real-time-objects-for-woocommerce-sites/ · https://jetpack.com/upgrade/scan/ · https://wordpress.org/plugins/jetpack-protect/
- https://www.wordfence.com/ · https://www.wordfence.com/products/wordfence-premium/ · https://wordpress.org/plugins/wordfence/
- https://fatlabwebsupport.com/blog/website-security/solid-security-review/ · https://wafplanet.com/waf/solid-security/ · https://www.isitwp.com/wordpress-plugins/ithemes-security/ · https://www.malcare.com/blog/solid-security-review/ · https://doindigital.com/solid-security-review/
- https://sucuri.net/ · https://sucuri.net/website-security-platform/ · https://sucuri.net/website-firewall-a/ · https://info.sucuri.net/feature/malware-removal · https://sitecheck.sucuri.net/ · https://sucuri.net/wordpress-security-plugin/

## Part C — Competitor cluster: Monitoring & white-label reporting

**Scope note:** "WP Health" is not a separate current competitor — it was the original name of WP Umbrella (the WordPress.org plugin slug is still `wp-health`) ([wordpress.org/plugins/wp-health](https://wordpress.org/plugins/wp-health/)). This cluster therefore covers UptimeRobot, Pingdom, WP Umbrella, plus the white-label agency tooling benchmark set (ManageWP, MainWP Pro Reports, WPMU DEV Hub, Better Stack for status pages).

### Competitor capability snapshots (July 2026)

**UptimeRobot** ([pricing](https://uptimerobot.com/pricing/)) — HTTP(S)/keyword/ping/port/cron/heartbeat monitors; SSL + DNS monitoring on paid plans; 60s checks (30s Enterprise); multi-location verification from 4 regions (NA/EU/Asia/AU); maintenance windows on all paid plans; monthly uptime email reports; status pages: 1 free → 100 on Team ($33/mo) with **custom domain, custom design, full white label**; 12 integrations. What it does better than SimpleAd: check frequency + multi-region confirmation of downtime, mature white-label status pages, packaged monthly SLA emails.

**Pingdom** ([pingdom.com](https://www.pingdom.com/), [pricing](https://www.pingdom.com/pricing/)) — synthetic monitoring from 100+ probe locations, **transaction monitoring** (scripted login/checkout flows), page-speed checks, root-cause analysis, and **Real User Monitoring** (RUM) with 13-month retention, unlimited sites, shareable reports; from ~$10–15/mo. What it does better: transaction/user-flow checks, RUM (real visitor experience data, geography/browser breakdowns), probe diversity.

**WP Umbrella** ([features](https://wp-umbrella.com/features/), [maintenance reports](https://wp-umbrella.com/features/maintenance-reports/), [white label](https://wp-umbrella.com/features/white-label/)) — €1.99/site: uptime + PHP error monitoring + Lighthouse; visual-regression-tested safe updates; **automated recurring white-label PDF/email reports with 50+ variables, agency logo/colors, sent from the agency's own domain**, custom-work line items; LLM/slash-command control (Claude Code/ChatGPT integration); 5,000+ agencies. What it does better: report automation polish (own-domain sending, variable library, "custom work performed" entries), per-site pricing simplicity.

**ManageWP** ([uptime monitor](https://managewp.com/features/uptime-monitor/), [client report guide](https://managewp.com/guide/client-report/)) — 1-minute uptime checks at $1/site add-on; Client Report auto-pulls uptime/security/update data into branded scheduled reports with agency logo/colors/contact. What it does better: pay-per-site add-on economics, report scheduling maturity.

**MainWP Pro Reports** ([Pro Reports](https://mainwp.com/add-on/pro-reports/), [White Label extension](https://mainwp.com/kb/white-label-extension/)) — fully customizable PHP/HTML report template engine with tokens (`[client.name]` etc.), PDF delivery, plus a White Label extension that rebrands/hides the child plugin on client sites. What it does better: total template control, connector-plugin white-labeling.

**WPMU DEV Hub / Hub Client** ([Hub Client](https://wpmudev.com/project/the-hub-client/), [Client Billing docs](https://wpmudev.com/docs/hub-2-0/client-billing/), [0% fee announcement](https://wpmudev.com/blog/white-label-client-billing-with-stripe-now-free/)) — the benchmark for client portal + billing: fully white-label client dashboard hosted **on the agency's own site/brand** (multiple brands supported), Stripe-integrated recurring subscriptions and invoicing, branded emails, product/service packages, **automatic site suspension on non-payment**, 0% platform fee on Premium. What it does better: everything billing, and a portal clients log into rather than a share link.

**Better Stack** ([status page](https://betterstack.com/status-page), [subscriptions docs](https://betterstack.com/docs/uptime/subscribing-to-status-updates/)) — status-page state of the art: CNAME custom domains, component-level statuses, email/RSS/webhook **subscriber notifications**, scheduled maintenance announcements, status reports/updates timeline, own-domain email sending (add-on). Included as the bar SimpleAd's status pages are measured against.

### Category ratings, gaps, and WOW versions

| # | Category | SimpleAd | Best competitor | Gap |
|---|----------|----------|-----------------|-----|
| 1 | Uptime & synthetic monitoring depth | **Partial** | Pingdom / UptimeRobot | Single-vantage checks; no multi-region down-confirmation, no scripted transaction checks, no RUM, no maintenance windows, coarser intervals than 30–60s tiers |
| 2 | Uptime SLA reporting & analytics | **Partial** | UptimeRobot / Better Stack | Has uptime % + `sla_target` display on status pages, but no scheduled SLA digest emails, response-time percentiles, per-client SLA targets with breach tracking, or YoY trends |
| 3 | Public status pages | **Partial** | Better Stack / UptimeRobot Team | Has branded pages with auto-incidents from downtime events, incident templates, custom-domain field — but no subscriber notifications (email/RSS/webhook), no component grouping, no scheduled-maintenance announcements, no own-domain incident emails |
| 4 | White-label client reports | **Partial (strong)** | WP Umbrella / MainWP Pro Reports | Has HTML+PDF reports with recommendations-approval flow, but lacks sending from the agency's own email domain, a variable/token library (WP Umbrella: 50+), manual "custom work performed" line items, and per-client template variants |
| 5 | Client-facing dashboard / portal | **Partial** | WPMU DEV Hub Client | Token-link portal serving reports only; competitors give clients a branded login on the agency's domain with live health, tickets/requests, and billing |
| 6 | Client billing & subscriptions | **None** | WPMU DEV Clients & Billing | Zero billing code in repo; WPMU DEV offers Stripe subscriptions, invoicing, branded checkout, 0% fees, auto-suspension on non-payment |

**WOW versions worth building**

1. **Monitoring:** Multi-region down-confirmation (2-of-3 probes before alerting) + scripted WordPress transaction checks (login, add-to-cart, form submit via the connector) + maintenance windows — then feed real-visitor Core Web Vitals from a lightweight connector beacon as a "RUM lite" no WP tool ships today.
2. **SLA reporting:** Per-client SLA contracts (target %, response-time budget) with automatic monthly SLA attainment statements, breach alerts before month-end ("you will miss 99.9% if downtime exceeds 12 more minutes"), and credit-calculation output — packaged into the existing report pipeline.
3. **Status pages:** Add email/RSS/webhook subscribers, component groups (site, checkout, API), scheduled-maintenance posts, and own-domain incident emails; AI incident response already exists — auto-drafting the public incident post-mortem from it is a differentiator nobody in the WP space has.
4. **Reports:** Own-domain (DKIM) sending, token/variable library, custom-work log entries billable-hours-aware, and AI-written executive summary per report — turning the existing recommendations-approval flow into a client-visible "work approved → work done → value delivered" narrative.
5. **Client portal:** Branded client login (magic link) on the agency's custom domain showing live uptime/security/performance, report archive, recommendation approvals, and a request inbox — effectively Hub Client without hosting lock-in.
6. **Billing:** Stripe Billing integration — maintenance-plan objects already exist as `MaintenancePlans`; attach prices, generate branded invoices, auto-pause monitoring/reports (never the site) on non-payment, and upsell prompts generated from monitoring data ("3 incidents last month — propose the Pro plan"). This closes the loop no monitoring competitor except WPMU DEV has, and theirs lacks SimpleAd's monitoring depth.

**Sources:** [UptimeRobot pricing](https://uptimerobot.com/pricing/) · [Pingdom](https://www.pingdom.com/) · [Pingdom pricing](https://www.pingdom.com/pricing/) · [WP Umbrella features](https://wp-umbrella.com/features/) · [WP Umbrella maintenance reports](https://wp-umbrella.com/features/maintenance-reports/) · [WP Umbrella white label](https://wp-umbrella.com/features/white-label/) · [WP Umbrella pricing](https://wp-umbrella.com/pricing/) · [wp-health plugin slug = WP Umbrella](https://wordpress.org/plugins/wp-health/) · [ManageWP Uptime Monitor](https://managewp.com/features/uptime-monitor/) · [ManageWP Client Report guide](https://managewp.com/guide/client-report/) · [MainWP Pro Reports](https://mainwp.com/add-on/pro-reports/) · [MainWP White Label](https://mainwp.com/kb/white-label-extension/) · [WPMU DEV Hub Client](https://wpmudev.com/project/the-hub-client/) · [WPMU DEV Client Billing docs](https://wpmudev.com/docs/hub-2-0/client-billing/) · [WPMU DEV 0% fee billing](https://wpmudev.com/blog/white-label-client-billing-with-stripe-now-free/) · [Better Stack status pages](https://betterstack.com/status-page) · [Better Stack subscriber docs](https://betterstack.com/docs/uptime/subscribing-to-status-updates/)

---

## Future modules

### Proposed New Modules for SimpleAd Manager (Prioritized)

## Tier 1 — Parity-critical (competitors win deals on these today)

### 1. Vulnerability Intelligence & Malware Scanner
- **Client value:** "Your sites are protected against known exploits and malware, not just monitored" — the single most-asked security question from agency clients.
- **Gap closed:** Only file-integrity checks exist today; Wordfence (Threat Defense Feed), MalCare (AI scanner + one-click clean), Jetpack/WPScan (~53k CVE database), and WP Umbrella Site Protect (Patchstack virtual patching) all ship this.
- **Design:** Two layers. (a) Match the plugin/theme/core inventory SimpleAd already collects against Patchstack/WPScan feeds — feeds the existing to-do/action-items feed and safe-updates prioritization. (b) Ship integrity-flagged changed files to the manager and scan off-server with YARA-style signatures + AI triage, with one-click quarantine backed by atomic staged restore.
- **Complexity:** Layer (a) **M**; layer (b) **L**.
- **Dependencies:** Patchstack/WPScan API license, existing integrity-scan hashes, connector file-shipping endpoint (connector version bump), AI incident-response module, action-items feed (MODULE #6).

### 2. Backup engine overhaul — hardened incrementals + opportunistic WP-CLI fast path *(CHOSEN — option B, 2026-07-11)*
- **Client value:** Hourly/event-triggered restore points with near-zero site load; fast, robust backups on large sites; per-update rollback points become free.
- **Gap closed:** BlogVault and Jetpack are forever-incremental with real-time event triggers; WP Umbrella ships encrypted incrementals with 50-day retention. SimpleAd already has a *partial* incremental path (`CreateIncrementalBackup`, `BackupManifestV3`, chain-aware retention) — but it is **broken** (P0-03 retention destroys valid chains, P0-04 zero-change incrementals fail). This module = fix that first, then make it fast and robust.
- **Design (two tracks):**
  1. **Fix + finish the pure-PHP/REST incremental path** (the universal workhorse — runs on ANY host, no shell). Land P0-03/P0-04 first, then: connector reports changed files (reuse integrity-scan hashes) + changed tables; manager stores deltas on the existing S3+Dropbox pipeline and builds synthetic fulls server-side; auto-prove every chain restorable via the existing restore-testing module ("verified restore point" badge).
  2. **Opportunistic WP-CLI fast path.** The connector detects at runtime whether the host allows WP-CLI (`proc_open`/`shell_exec` available + a `wp-cli.phar` present or fetchable) and reports the capability back per-site into the manager. Where available → use `wp db export` / file streaming via WP-CLI for much faster large-site backups; where not → fall back to the pure-PHP path automatically. **Not SSH-based** — runs inside the WordPress process from the connector, so it needs zero SSH access from the platform. The fleet is mixed, so both paths ship fully.
- **Complexity:** **XL** (delta format, synthetic-full compaction, chain integrity, connector capability detection + dual backup engines).
- **Dependencies:** P0-03/P0-04 (fix the broken incremental logic first), connector 2.18+ (capability detection + WP-CLI runner, staged fleet rollout), existing backup/restore jobs, restore-testing module, S3 lifecycle config.
- **Constraint note:** a "WP-CLI everywhere / SSH-based primary" design is NOT viable — no SSH to all clients and `shell_exec` disabled on many hosts (CLAUDE.md). Hence the capability-detected, connector-embedded, opportunistic approach above.

#### Recommended architecture — "thin client / cloud brain" with content-addressed storage *(researched 2026-07-11)*

The best products (BlogVault/MalCare, Jetpack, WP Umbrella, WPMU Snapshot v4) all share ONE architecture: **a thin client that only detects and ships deltas; a cloud that does all compression, storage, dedup, and synthetic-full assembly.** Only the self-hosted tools (UpdraftPlus/Duplicator) do work on the client — and they prove the universal-floor tricks (chunk + checkpoint + resume + split). SimpleAd's Laravel-manager + connector split maps onto this exactly. Recommended design:

**A. Delta detection in the connector (cheap, no shell).** The manager holds the authoritative per-site manifest. Files: connector reports `(path, size, mtime)`; cheap mtime+size first pass, then a fast content hash (xxHash/BLAKE3 — not SHA) only for files whose mtime/size changed. Database: never dump-and-diff — chunk each table by primary-key ranges and checksum each chunk (`CHECKSUM TABLE` where usable, else a hash over a PK-ordered batch); only changed chunks re-export. Optionally hook `save_post` / `woocommerce_new_order` / plugin-theme-update actions (Jetpack's trick) to mark tables dirty and skip scanning clean ones.

**B. Chunked, resumable, content-addressed transport (the non-negotiable core).** Ship only changed file blobs + changed DB chunks, each an independent unit, via chunked HTTP — prefer **direct-to-S3 presigned multipart** for file blobs to offload the manager. **Store blobs by content hash** → automatic dedup across the whole fleet (identical plugin files upload once) and an incremental "upload set" is simply the hashes not already in S3. **Checkpoint every unit with server-authoritative state** (the manager knows which chunks are done) so a dead PHP request resumes at the next incomplete unit — UpdraftPlus's resumption model, hardened. Encrypt in transit + at rest.

**C. Server-side synthetic-full compaction — the key trick.** Because storage is content-addressed, **every restore point is a manifest of blob pointers, so every incremental logically IS a full** — a restore resolves pointers in O(1), never replays a chain. No data re-transfer to "materialize" a full. Compaction runs on the **manager, not the client**: reference-count GC prunes blobs no restore point references; retention becomes a pointer/GC policy (WPMU-style: keep N granular points, thin older ones) that never affects restore cost. This is exactly what fixes P0-03 structurally — retention can't destroy a restorable point because points are pointer sets, not fragile parent-child chains.

**D. Opportunistic WP-CLI/shell accelerator.** On hosts that allow it (detected at handshake): `wp db export` / `mysqldump --single-transaction` for fast consistent DB snapshots (skips PHP memory entirely on multi-GB DBs), native `tar` for file staging, optionally MySQL binlog as a true DB change stream. **Critical:** the fast path MUST emit the identical blob+manifest format as the PHP path, so a site can lose shell access or move hosts without breaking its chain. WP-CLI is an accelerator, never a dependency — the PHP chunked path is always the floor.

**Which competitor risk each piece solves:** no-zip-on-client + chunked push (BlogVault/WP Umbrella) → CPU/OOM on multi-GB shared-hosting sites, the "don't kill the client" risk; checkpoint+resume (UpdraftPlus) → `max_execution_time` cutoffs; content-addressed synthetic full + server compaction (BlogVault + WPMU base-merge) → "incrementals are hard to restore / unbounded chains"; event-hook dirty-marking (Jetpack, incl. Woo immutable orders) → data loss between runs on stores + scan cost; PK-range DB checksums → the large-DB timeout that file-only incrementals (UpdraftPlus skips the DB) never solve.

**Net:** this is a *better module than "fix the current chains"* — it replaces the fragile parent-child incremental model with content-addressed manifests where a restorable full is always one pointer-resolution away, gives BlogVault-class efficiency across the mixed fleet, keeps the pure-PHP path as the universal floor, and folds WP-CLI in as a pure accelerator. Sources: BlogVault/MalCare, Jetpack VaultPress, WP Umbrella, WPMU Snapshot v4, UpdraftPlus, Duplicator (URLs in Part C research log).

### 3. One-Click Staging & Cloning
- **Client value:** Test updates and changes without risking production; clone sites for new-client spin-up.
- **Gap closed:** Coverage is "None." MainWP has clone/staging extensions; hosting-integrated competitors offer one-click staging.
- **Design:** Restore latest backup to a sandboxed subdomain (reusing atomic staged restore machinery), run Safe Updates + visual regression there first, then promote. Merging staging into the safe-update pipeline is a genuine differentiator no competitor has.
- **Complexity:** **L** (URL rewriting, sandbox provisioning, promote/merge path).
- **Dependencies:** Backup+restore pipeline, Safe Updates module, Module 4 (visual regression) for full value; DNS/subdomain automation (Cloudflare API already integrated).

### 4. Safe Updates v2 — Visual Regression
- **Client value:** Updates never silently break the client's site; every update is screenshot-verified.
- **Gap closed:** WP Umbrella runs per-update visual regression; ManageWP and WPMU DEV take before/after screenshots with diff thresholds. SimpleAd has rollback but no visual check.
- **Design:** Before/after screenshots of homepage + N key pages, pixel-diff with configurable threshold, auto-rollback vs hold-for-approval with side-by-side diff UI, plus Lighthouse deltas from the existing performance pipeline.
- **Complexity:** **M** (headless-browser worker + diffing; rollback plumbing exists).
- **Dependencies:** Safe Updates module (#3/AUDIT E-14), headless Chrome container in the Docker stack, Horizon queue capacity; becomes cheap once incremental snapshots (Module 2) land.

## Tier 2 — Revenue & retention differentiators

### 5. Client Billing & Subscriptions (Stripe) — ~~proposed~~ **DROPPED (2026-07-11)**
- **Decision:** Not building. SimpleAd is an internal tool for monitoring the agency's own clients, not a per-seat product — there is no monetization loop to close. (Competitor billing capabilities are retained in Part C as *facts*, not as a target.)
- *Original rationale, for the record:* WPMU DEV Hub has Stripe billing; SimpleAd has none. Superseded by the internal-tool decision.

### 6. White-label report & status surface — ~~Client Portal v2 with login~~ **re-scoped (2026-07-11)**
- **Decision:** No client login portal / billing (internal tool). Keep and polish the **branded report + status surface** clients already receive.
- **Client value:** Clients get flawless, branded reports and a public status page — the agency looks like it built its own platform — without a login/checkout system to build and secure.
- **Design (kept):** white-label branding on generated PDFs (logo, agency domain-of-record), DKIM own-domain report sending, in-report recommendation approvals, and Status Pages v2 (below). Dropped: magic-link login, per-client user accounts, request inbox, billing checkout.
- **Complexity:** **M** (down from L).
- **Dependencies:** existing report pipeline + recommendations-approval flow, DKIM/SES, CNAME for status pages.

### 7. Uptime SLA Contracts + Status Pages v2
- **Client value:** Contractual proof of uptime ("99.9% attained, statement attached") plus a public client-facing status asset.
- **Gap closed:** UptimeRobot ships monthly SLA email reports; Pingdom has 13-month analytics; Better Stack has subscriber notifications, component groups, and scheduled maintenance — SimpleAd has status pages + sla_target but none of the above.
- **Design:** Per-client SLA contracts (target %, response-time budget), monthly attainment statements through the report pipeline, pre-emptive breach alerts ("12 more minutes misses 99.9%"), credit calculations; status pages gain email/RSS/webhook subscribers, component groups, scheduled-maintenance posts, and AI-drafted post-mortems from AI incident response.
- **Complexity:** **M**.
- **Dependencies:** Existing uptime + status-page + report modules, notification escalation fix (known ProcessNotificationEscalations issue), AI incident response.

### 8. Multi-Region & Transaction Monitoring ("RUM lite")
- **Client value:** No false-positive alerts, and proof the checkout/login actually works — not just that the homepage returns 200.
- **Gap closed:** Single-vantage checks today; Pingdom monitors from 100+ locations with transaction checks + RUM, UptimeRobot does 4-region verification and maintenance windows.
- **Design:** 2-of-3 multi-region probe confirmation before alerting, 60s cadence tier, maintenance windows, connector-driven WP transaction checks (login, add-to-cart, form submit), and a connector beacon collecting real-visitor Core Web Vitals. No WP maintenance tool ships RUM — differentiator.
- **Complexity:** **L** (requires 2+ external probe workers/regions — new infra).
- **Dependencies:** Cheap VPS/edge probes in 2-3 regions, connector update for transaction endpoints + beacon, existing uptime/notification stack. Feeds Modules 7 and 4.

## Tier 3 — Fleet-scale & platform maturity

### 9. Fleet Edge WAF & Virtual Patching
- **Client value:** Exploits blocked at the edge before a patch exists; geoblocking and bot protection per client.
- **Gap closed:** IP blocking + captcha only today; Sucuri (cloud WAF), Jetpack/Wordfence (WAF rules), Patchstack/Solid Security (virtual patching).
- **Design:** Push managed rules, geoblocking, and per-CVE virtual patches (driven by Module 1's vuln feed) to Cloudflare per site — Cloudflare is already integrated — with a connector-level fallback ruleset for non-Cloudflare sites. Surface "exposed vs patched vs virtually-patched" per client.
- **Complexity:** **L**.
- **Dependencies:** Module 1 (CVE feed), Cloudflare API integration, connector fallback rules.

### 10. Bulk Fleet Operations + REST API
- **Client value (agency):** Deploy/activate/remove any plugin or theme across tagged site groups in one action; script everything.
- **Gap closed:** MainWP 6.x offers unlimited-site bulk-everything + REST API; WP Umbrella has one-click bulk plugin deploy. SimpleAd has fleet updates but not arbitrary install/deploy or an API.
- **Design:** Tag-scoped bulk plugin/theme install-activate-remove, bulk hardening-profile application (pairs with existing hardening + tags module #1), authenticated REST API (Sanctum tokens) over existing job dispatchers.
- **Complexity:** **M** (dispatchers and tags exist; API surface + plugin-upload storage are new).
- **Dependencies:** Tags module, existing job/dispatcher layer, connector install endpoint (signed-URL pattern already proven).

### 11. Team Collaboration & Granular Permissions
- **Client value (agency):** Safely onboard junior staff/VAs — per-site, per-capability access with the existing activity log as the audit trail.
- **Gap closed:** ManageWP collaborators and WP Umbrella team roles offer this; SimpleAd is effectively single-operator (app 2FA was just removed — revisit auth story here, e.g. passkeys).
- **Design:** Roles (owner/admin/tech/viewer) × site-group scoping via tags, capability gates on destructive actions (restore, delete, deploy), approval workflow option. Builds directly on the 81-guard authz sweep from the 2026-07-10 audit.
- **Complexity:** **M**.
- **Dependencies:** Existing authz guards, tags module, activity log.

### 12. Connector-Independent Emergency Restore
- **Client value:** "Even if your site is hacked, white-screened, or suspended, we can bring it back" — the strongest backup marketing claim available.
- **Gap closed:** BlogVault and Jetpack restore fully-down sites; SimpleAd's restore requires a live connector.
- **Design:** SFTP/host-API bootstrap that reinstalls the connector or extracts the archive directly; selective restore (single file/table/plugin); WooCommerce order-preservation option (vs Jetpack's Immutable Real-Time Objects).
- **Complexity:** **M-L** (per-site SFTP credential vault, host-API adapters).
- **Dependencies:** Backup pipeline, encrypted credentials storage; order-preservation benefits from Module 2's table-level deltas.

### 13. Multisite Support
- **Client value:** Agencies running WP multisite networks can manage them without a second tool.
- **Gap closed:** MainWP supports multisite; SimpleAd's connector and Site model assume single-site (`url` column, per-site connector).
- **Complexity:** **L** (touches connector, Site model semantics, backups, updates, reporting — network vs subsite scoping everywhere).
- **Dependencies:** Connector rework, data model change (network → subsites). **Recommend deferring** unless a concrete client demands it — lowest demand-to-effort ratio in this list.

## Suggested build order

| Order | Module | Size | Rationale |
|---|---|---|---|
| 1 | Vuln feed (Module 1a) | M | Cheapest large-perceived-value win; unblocks WAF virtual patching |
| 2 | Safe Updates v2 visual regression | M | Fast, high-visibility, reuses existing rollback |
| 3 | White-label report/status polish | M | Agency-brand value; no login/billing (internal tool) |
| 4 | ~~Billing & Subscriptions~~ | — | **Dropped** — internal tool, no monetization loop |
| 5 | Backup engine overhaul (Module 2, chosen B) | XL | Biggest lift; unlocks staging + emergency restore economics; efficient full+incremental (see FM2) |
| 6 | Staging/clone | L | Unique staging+safe-update fusion once 2 and 5 exist |
| 7 | SLA + status pages v2 | M | Compounds monitoring strength into client-visible assets |
| 8 | Off-server malware scan (1b) | L | Completes security story |
| 9-12 | Multi-region probes, WAF, bulk ops/API, team permissions | M-L | Fleet-scale maturity |
| 13 | Multisite | L | Only on demand |

**Cross-cutting notes:** Modules 1, 2, 8, 12 all require connector releases — batch them into the pending 2.16.0 fleet push cadence and keep `Version:`/`SAM_VERSION` in sync. WP-CLI-based approaches are ruled out fleet-wide by the `shell_exec` restriction; every "agent-side" capability must go through connector HTTP endpoints. Modules 4 and 8 add a headless-browser/scanner worker to the Docker stack (new service in docker-compose.prod.yml).

---

## Part D & E — Roadmap and backlog

### PART D — ROADMAP

Three phases, mapped 1:1 to the production-readiness priorities. Each phase is internally dependency-ordered; items within a wave can be parallelized. IDs reference Part E.

---

## Phase 1 — Operational Solidity (go-live gate)

**Goal:** No data-loss path, no silent failure mode, no unauthorized or unsafe mutation of a client site, platform survives its own disasters, and the fleet runs unattended at 100+ sites. Nothing ships to Phase 2 until every P0 in Part E is closed.

**Why now:** The audit found the backup subsystem can *delete its only restorable backups* (retention chain bug), *kill live restores* (blind force-release), and *fail every daily incremental with zero changed files* — while the alerting layer that would surface these is itself silently broken (site_recovered mismatch, db:dump false success, disconnect-forever, uptime blackout E-27). These compound: a client site could be lost with every safety net individually failing quietly. This is the exact opposite of the "zero manual intervention" bar.

**Waves (dependency-ordered):**

- **Wave 1.0 — Stop-the-bleeding hotfixes (days, ship independently):** RetentionCleanup crash on dropped `security_commands` (P0-01 — nightly cleanup is failing *now*); db:dump false success (P0-02); PSI every-minute loop dispatcher guard (P0-19); GET-with-body HMAC fleet-wide 401 (P0-16); duplicate stuck-restore recovery removal (P0-05); restore redelivery guard (P0-06); zero-change incremental failure (P0-04); uptime E-27 timeout blackout (P0-17). All are S-effort, code-only, revert-in-one-commit.
- **Wave 1.1 — Backup/restore data integrity:** retention chain deletion (P0-03, behind a one-week dry-run log), safe-update SiteOperationLock + heldLockToken (P0-07), Global Updates through the safe pipeline (P0-08), incident-response backup invariant (P0-20) and re-trigger loop (P0-21). Depends on Wave 1.0's recovery-path fixes so lock semantics are tested against a single recovery sweep.
- **Wave 1.2 — Tenant isolation & authorization sweep:** WP-admin viewer minting (P0-13), connect-modal secrets (P0-14), SEO api_key in HTML (P0-15), CopySettingsModal IDOR (P0-10), updatePluginAcrossSites (P0-09), plan-snapshot dangerous keys (P0-11), report guards + Generate All (P0-24/25), dashboard scoping (P0-22), then the consolidated `Site::visibleTo()` scope (P1-01) and global read-leak sweep (P1-02). Order matters: land the canonical scope before the sweep so all overviews share one definition.
- **Wave 1.3 — Silent-failure elimination / self-healing:** disconnect-forever + reconnect probe (P0-18), Google token deactivation (P0-23), DNS false-change E-30 (P0-27), site_recovered event (P1-05), uniqueFor sweep (P1-07), releaseUniqueLock no-op (P1-08), stuck performance rows (P1-09), audit watermark poisoning (P1-11), Cloudflare status checking (P1-12), notification pipeline correctness cluster (P1-20..24).
- **Wave 1.4 — Connector safety (batch into one 2.17.x fleet push):** .htaccess snapshot/rollback rewrite (P0-12), two-phase key rotation (P1-15), rollback pre-backup + health verify (P1-16), connector log-sort fix. One connector release, one staged fleet rollout (5 sites → 24h soak → fleet), keeping `Version:`/`SAM_VERSION` in sync.
- **Wave 1.5 — Platform self-protection & 100-site scale:** off-site self-backups (P0-26), app self-restore rebuild (P1-13), .env/APP_KEY circularity (P1-14), Horizon memory alignment (P1-06), queue capacity plan (P1-33), deploy downtime + certbot reload (P1-34/35), scheduled-task failure hooks (P1-36), branch protection + fail-closed CI gate (P1-37).

**Dependencies:** none external except the connector release cadence (Wave 1.4) and the pending 2.16.0 fleet push already outstanding. Wave 1.4 items are the only ones needing WP-side deploys.

**Client safety:** every Wave 1.0–1.3 item is manager-side, additive, no schema change beyond expand-only columns, revert = one commit. Retention fix runs a dry-run week; connector changes go out staged. Interim mitigations (documented per-row in Part E): disable `incremental_frequency` on incremental sites, hide the app-restore button, avoid bulk key rotation.

**Rough effort:** ~6–8 engineer-weeks. Wave 1.0 ≈ 3 days; 1.1 ≈ 1.5 wk; 1.2 ≈ 1 wk; 1.3 ≈ 1.5 wk; 1.4 ≈ 1 wk + rollout soak; 1.5 ≈ 1.5 wk.

---

## Phase 2 — Client-Facing Completeness

**Goal:** Everything a client receives is correct and branded: flawless white-label reports and status pages with SLA proof. **No billing** (internal tool — decision 2026-07-11); "client-facing" here means the report/status surfaces clients are sent, not a login/checkout portal.

**Why now:** Phase 1 makes the *data* trustworthy — Phase 2 is pointless before that (reports currently embed false DNS data, never-written Cloudflare metrics, and stale 28d Google caches; branded output on top of wrong data is a liability). With data integrity fixed, client-visible surfaces become the highest-leverage investment.

**Included (dependency order):**

1. **Report pipeline correctness cluster** — infrastructure section dead code (P2-01), Cloudflare report snapshot columns never written (P2-02), Google cache staleness/period mismatch (P2-03), recipient validation (P2-04), catch-all Throwable retry defeat (P1-25), monthly-run swallow (P1-26), stuck-generating recovery (P2-05). *Reports must be flawless before they go out white-labeled.*
2. **White-label report polish** — client CASCADE FK (P1-30), archived-client stale surfaces (P2-06), report-access guards (P2-07), report test coverage (part of P1-40), branding/logo/domain-of-record on generated PDFs.
3. **SLA contracts + Status Pages v2 (MOD-07)** — attainment statements through the (now-correct) report pipeline, subscriber notifications, maintenance windows, CNAME custom domain. Depends on the notification correctness cluster from Phase 1 (P1-20..24) and the ProcessNotificationEscalations fix.
4. **SSL-expiry monitoring** (P2-08) — the dead `check_ssl` plumbing exists; a client-visible gap competitors don't have.
5. *(Dropped)* ~~Client Portal v2 with login~~ / ~~Billing & Subscriptions~~ — not needed for an internal tool. If clients ever need a live self-serve view later, the read-only token-link report/status surface already exists and can be extended without billing.

**Dependencies:** Phase 1 Waves 1.2–1.3 (correct data + correct alerting); SES/DKIM own-domain sending and CNAME routing for status pages.

**Client safety:** all new surfaces are additive and feature-flagged; nothing here can affect a managed site's availability.

**Rough effort:** ~4–5 engineer-weeks (reports cluster 1.5 wk; white-label polish 1 wk; SLA/status 2 wk). *(Down from ~8–10 wk with billing + login portal dropped.)*

---

## Phase 3 — Competitive Parity + New Modules

**Goal:** Close the gaps competitors win deals on, then differentiate: vulnerability intelligence, screenshot-verified updates, incremental backups, staging, and fleet-scale tooling.

**Why now:** Only safe once updates/backups/restores are provably solid (Phase 1) — several modules *reuse* that machinery (staging reuses restore; visual regression reuses safe-update rollback; vuln feed drives safe-update prioritization). Sequencing them earlier would build on broken foundations.

**Included (build order, per Track 2 rationale):**

1. **Vulnerability feed (MOD-01a, M)** — cheapest large win; matches existing inventory against Patchstack/WPScan; feeds the to-do feed and safe-update priority. Needs API license only.
2. **Safe Updates v2 — visual regression (MOD-04, M)** — needs headless Chrome service in docker-compose; rollback plumbing exists post-Phase-1. Pairs with extending safe updates to themes/core/bulk (P2-09).
3. **Incremental Backup Engine (MOD-02, XL)** — biggest lift; requires connector 2.17+ delta endpoints (batch with the Phase-1 connector cadence) and the *fixed* chain retention (P0-03) as a hard prerequisite. Auto-verified restore points via the restore-testing module.
4. **One-Click Staging & Cloning (MOD-03, L)** — reuses restore + safe updates + visual regression; the staging↔safe-update fusion is the differentiator.
5. **Off-server malware scan (MOD-01b, L)** — completes the security story; connector file-shipping endpoint (another connector release).
6. **Multi-region probes + transaction checks/RUM (MOD-08, L)** — new probe infra (2–3 cheap regions); kills single-vantage false positives (also resolves the single-probe-location finding).
7. **Fleet Edge WAF / virtual patching (MOD-09, L)** — depends on MOD-01a CVE feed; Cloudflare already integrated.
8. **Bulk fleet ops + REST API (MOD-10, M)**, **Team permissions (MOD-11, M)** — builds on Phase-1 authz consolidation (`visibleTo`, guard sweep).
9. **Connector-independent emergency restore (MOD-12, M–L)** — benefits from MOD-02 table deltas.
10. **Multisite (MOD-13)** — deferred unless a concrete client demands it.

**Dependencies:** connector releases batched (MOD-01b/02/08/12); new Docker services (headless Chrome, scanner worker, probe nodes); `shell_exec` is disabled fleet-wide, so every agent-side capability goes through connector HTTP endpoints (WP-CLI approaches are ruled out).

**Client safety:** every module is opt-in per site/plan; anything mutating client sites (WAF rules, staging promote, malware quarantine) rides the safe-update/lock/backup invariants hardened in Phase 1 and ships to pilot sites first.

**Rough effort:** ~4–6 months rolling, ordered so revenue-relevant items (1–4) land in the first 6–8 weeks.

---

### PART E — TO-DO BACKLOG

Legend — Type: Fix/Harden/Wow · Effort: S (<½d) / M (1–3d) / L (1–2wk) / XL (>2wk) · Risk-to-live: risk *the change itself* poses to live operation/client sites. Duplicated cross-module findings are merged (noted in title). Anything touching data integrity, tenant isolation, backup/restore, or connector mutation of client sites is elevated by default.

| ID | Title | Type | Module | Pri | Eff | Risk | Deps | Acceptance criteria | Safe-rollout note |
|---|---|---|---|---|---|---|---|---|---|
| P0-01 | RetentionCleanup crashes nightly on dropped `security_commands` table (merged: DNS + Error-logs findings) | Fix | Retention (cross) | P0 | S | Low | — | Nightly run completes; per-table try/catch; Settings retention stats render | Config-line removal; deploy immediately; self-heals at next 03:00 |
| P0-02 | db:dump reports success when pg_dump fails; gzip stderr corrupts artifact | Fix | Observability | P0 | S | Low | — | pipefail/two-step dump; exit code + min-size + gunzip -t checked; failure fires critical notification | Verify once with a wrong DB host; revert = one commit |
| P0-03 | Retention deletes live incremental chains + orphan cleanup destroys in-window restore points (merged: chain-aware + days-based + orphan findings) | Fix | Backups | P0 | M | Low | — | Chain deletable only when NEWEST member outside window; orphan cleanup age-gated; regression test: old full + fresh incremental both survive | Run in dry-run/log-only mode for 1 week before enabling deletes; interim: raise retention on incremental sites |
| P0-04 | Daily incremental FAILS when zero files changed (verifyV3Zip demands files/*) | Fix | Backups | P0 | S | Low | — | Zero-change incremental completes as DB-only; unit test for files_changed_count==0 | Verifier branch only; revert = one commit |
| P0-05 | BackupDispatcher 30-min blind force-release kills live restores (merged, 2 modules) | Fix | Backups–restore | P0 | S | Low | — | recoverStuckRestores removed from dispatcher; only PR #38 command (75-min, ownership-checked) recovers; regression test single recovery path | Pure removal; do NOT lower the command threshold |
| P0-06 | Redelivery guard hole: timeout-killed restore silently re-runs in full ~2h later | Fix | Backups–restore | P0 | S | Low | P0-05 | attempts()>1 proceeds only on status Pending; test mirrors RestoreBackupLockTest for status=Failed | Stricter conditional only; revert = one commit |
| P0-07 | Safe updates never take SiteOperationLock; pre-update safety backup silently no-ops on contention (merged, 3 modules) | Fix | Updates/Backups | P0 | M | Low | P0-05 | RunSafeUpdate acquires OPERATION_SAFE_UPDATE, passes heldLockToken; update hard-aborts if backup skipped/failed; contention throws on sync path | Test on one site behind a held lock; worst case updates queue behind backups (correct) |
| P0-08 | Global Updates page bypasses safe-update pipeline, runs with no pre-update backup | Fix | Updates | P0 | M | Low | P0-07 | All 3 entry points route safe_updates_enabled sites through queueSafeUpdate; others get pre-update backup | Additive branch; flag-off sites keep exact current behavior |
| P0-09 | updatePluginAcrossSites mutates every connected site with only a viewer check | Fix | Updates | P0 | S | Low | — | Loop skips sites failing canAccessSite; skipped count reported; test | Server-side guard only |
| P0-10 | CopySettingsModal.apply(): cross-tenant write IDOR, no viewer guard (merged, 2 modules) | Fix | Plans/Auth | P0 | S | Low | — | abort(403) viewers; targets re-scoped server-side; mount authorizes source; ViewerWriteGuard + cross-tenant tests | UI already scopes display — no legitimate flow changes |
| P0-11 | Plan snapshots + copySecuritySettings propagate site-specific settings (login URL, 2FA, captcha keys, firewall) | Fix | Plans | P0 | M | Low | — | Bulk-safe whitelist enforced at snapshot AND apply; single source of truth; existing plans stop copying dangerous keys | Filter at read time — no plan-JSON migration; optional cleanup UPDATE afterwards |
| P0-12 | .htaccess multi-setting rollback restores partially-modified file — can leave client site 500ing | Fix | Connector | P0 | M | Med | Connector 2.17.x release | Single pristine snapshot at batch start; restore from it on self-check failure; backup survives until batch verifies | Connector release, staged fleet push (5 sites → 24h → fleet); until then warn operators re manual .htaccess repair |
| P0-13 | Viewer can mint WP-admin auto-login URLs and switch impersonated admin | Fix | Sites | P0 | S | Low | — | authorizeSiteModification on openWpAdmin + setWpAdminUser; ViewerWriteGuard tests | Additive guard; ship immediately |
| P0-14 | Connector API key/secret exposed plaintext to viewers via Connect modal | Fix | Sites | P0 | S | Low | — | openConnectModal guarded; secret masked, never round-tripped to browser | Admin UX: re-paste instead of edit — acceptable |
| P0-15 | Site api_key rendered into HTML on SEO audit page (3x) | Fix | SEO | P0 | S | Low | P1-03 removes need | Panel removed/proxied server-side; no decrypted key in any payload | Pure UI removal; optionally rotate keys for exposed sites after P1-15 lands |
| P0-16 | GET-with-body HMAC mismatch silently breaks error-log ingestion fleet-wide (2,176 prod 401s) | Fix | Connector protocol | P0 | S | Low | — | Filter args moved to queryParams; INVALID_SIGNATURE warnings drop to ~0; fleet-wide-401 alert added | Manager-only one-liner, no connector push; instant revert |
| P0-17 | E-27: monitor timeout ≥ job/worker timeout → silent monitoring blackout | Fix | Uptime | P0 | S | Low | — | Job timeout derived from monitor (+buffer); failed() records synthetic failed check + advances next_check_at | Verify redis retry_after > new max before deploy |
| P0-18 | Transient sync failure permanently marks site disconnected — silently stops backups/scans/sync | Fix | Sites | P0 | M | Low | — | Disconnect only in failed() on auth/4xx; hourly reconnect probe; connected→disconnected notification event; dashboard tile | Probe is read-only getInfo; strictly less destructive than today |
| P0-19 | PSI every-minute test loop (frequency mismatch) + 'Manual only' broken | Fix | Performance | P0 | S | Low | — | Dispatcher excludes non-daily/weekly; validation accepts manual; SELECT confirms no looping rows in prod | Dispatcher guard defuses live loops instantly; query-only change |
| P0-20 | Incident-response "backup before destructive action" silently passes with no backup | Fix | Incident Response | P0 | S | Low | — | backup_created=true only when completed Backup row verified; no-config sites refuse/escalate | Behind existing opt-in module flag |
| P0-21 | Unresolved/escalated incidents re-trigger full AI pipeline every 30 min forever | Fix | Incident Response | P0 | M | Low | — | Escalated incidents suppress re-dispatch until ack; response_attempted_at stamped; backoff per trigger | Reduces actions on live sites — strictly safer |
| P0-22 | Global dashboard + DashboardService unscoped — cross-tenant read | Fix | Auth | P0 | M | Low | — | All DashboardService queries scoped; test: Viewer sees only own sites | Admins unaffected; read-only change |
| P0-23 | Transient Google token-refresh failure permanently deactivates connection, silent forever (merged, 2 modules) | Fix | Google/External | P0 | S | Low | — | Deactivate only on invalid_grant/4xx; retry on 429/5xx; notification on deactivation; validator covers deactivated | Log-only deactivations first week to observe frequency |
| P0-24 | Reports "Generate All" fatally broken (ArgumentCountError) + no authorization | Fix | Reports | P0 | S | Low | — | Explicit period passed; role guard; Livewire dispatch test | Fix to already-broken path — zero regression risk |
| P0-25 | Report trait viewer-guard gaps: bulk delete, arbitrary-email send, generate, recommendation mutations | Harden | Reports | P0 | S | Low | — | authorizeSiteModification on all 8 trait methods; ViewerWriteGuard tests extended | Confirm no Viewer legitimately sends reports first |
| P0-26 | Platform self-backups may never leave host; silent local fallback; local-only pg dumps | Harden | Observability | P0 | M | Low | — | Remote destination verified in prod; local fallback marked degraded + warns; daily dump pushed off-site | Config + additive code; zero client-site risk |
| P0-27 | E-30: dns_get_record() failure treated as zero records → false "deleted" alerts + false client-report data | Fix | DNS | P0 | S | Low | — | Lookup failure carries forward previous value; change requires 2 consecutive observations; no false Missing in reports | Job-logic only; optional cleanup of paired empty→restored dns_changes rows |
| P1-01 | Consolidate two divergent scoping mechanisms into canonical `Site::visibleTo($user)` | Fix | Auth | P1 | M | Low | — | Single scope incl. client assignments; used by all lists | Land before P1-02 sweep |
| P1-02 | Global read-leak sweep: Updates, Uptime, Performance, Backups, Reports, Activity timeline, GlobalSearch (merged, 7 modules) | Harden | Auth (cross) | P1 | L | Low | P1-01 | All overviews/search apply visibleTo; tests per surface | Read-only tightening; admins unaffected |
| P1-03 | SEO single-page fix actions + audit fetch dead (X-SAM-API-Key → guaranteed 401) | Fix | SEO | P1 | M | Low | — | All 6 call sites use signed factory client; blade fetch panel deleted; Http::fake tests assert signed headers | Broken path starts working; test on one site first |
| P1-04 | Residual viewer/authz sweep: verifySettings, error resolve(), SeoQuickAudit, CreateSiteWizard, generateAllReports, NotificationDropdown backup-retry, profitability mutations, addMonitorsForAllSites (merged, 8 findings) | Fix | Cross | P1 | M | Low | — | Guard on each; ViewerWriteGuardTest extended per action | Additive guards only |
| P1-05 | Recovery alerts emit 'site_up' but config only knows 'site_recovered' — unroutable/untemplatable | Fix | Notifications | P1 | S | Low | — | Event renamed; subscribedTo aliases both during transition; template renders | Honor old jsonb rows via alias — don't bare-rename |
| P1-06 | Horizon container 1024M vs >5GB summed worker thresholds — OOM-kill mid-backup/restore (merged, 2 modules) | Harden | Horizon/Docker | P1 | S | Med | — | container limit ≥ workers×threshold+master; OOM alert added | Apply in quiet window, no restore in flight; revert = compose value |
| P1-07 | ~20 ShouldBeUnique jobs lack uniqueFor — hard kill leaves permanent lock, monitor/sync silently disabled forever | Fix | Horizon | P1 | M | Low | — | uniqueFor = 2–3× timeout on all; one-time Redis stale-lock check; overdue-monitor sweeper alert | Purely additive; zero client-facing risk |
| P1-08 | releaseUniqueLock silent no-op (wrong Redis DB) — cancelled backups blocked up to 45 min | Fix | Backups | P1 | S | Low | — | Release via framework UniqueLock on lock_connection; regression test acquire-framework/release-trait | Worst case identical to today (TTL expiry) |
| P1-09 | RunPerformanceTest non-idempotent, stuck 'running' rows polled every 2s forever | Fix | Performance | P1 | M | Low | P0-19 | Stale-running sweeper; resume-safe retries; poll max-age; one-time cleanup UPDATE | Cleanup rows are display-only; run in maintenance window |
| P1-10 | E-28: sites without site_health_states row never uptime-checked / never sync (merged, 2 modules) | Fix | Uptime/Sync | P1 | S | Low | — | Row auto-created on site create + backfill; dispatchers LEFT-JOIN-safe | Backfill is INSERT-only |
| P1-11 | Unvalidated remote fields can poison audit-log watermark, permanently stalling ingestion | Fix | Activity | P1 | M | Low | — | occurred_at clamped, IP validated, lengths truncated; row-tolerant insert; zero-ingest alert | Manager-side only; healthy sites unchanged |
| P1-12 | CloudflareService::request() never checks HTTP status — mutations silently no-op, purge UI logs false success (E-59; merged, 2 modules) | Fix | Cloudflare | P1 | M | Low | — | request() strict on failed()/success:false; Livewire records purge only on true | Two-step: check booleans in callers first, then strict request() + sweep ~25 call sites |
| P1-13 | App self-restore cannot work against live DB (no --clean, ON_ERROR_STOP, sync in HTTP request) | Fix | Backups–restore | P1 | L | Med | — | Dump --clean --if-exists; restore = queued command on pgsql_direct with maintenance mode + horizon pause; validated end-to-end on staging | HIDE the restore button now (S); never test on production |
| P1-14 | DR circularity: app-backup .env encrypted with the APP_KEY inside that same .env | Fix | Observability | P1 | S | Low | P0-26 | Recovery key stored out-of-band; documented DR runbook restores from nothing | Doc + key-escrow change; no code risk |
| P1-15 | API key rotation can permanently brick a site if response lost; rotateApiKeys also references undefined property (merged) | Fix+Harden | Connector | P1 | M | Med | Connector release (with P0-12) | Two-phase rotation: overlap window accepts both keys; timeout triggers reconciliation probe; broken $this->apiFactory fixed + test | Ship connector first, then enable confirm step; avoid bulk rotation until live |
| P1-16 | Connector rollback has no pre-rollback backup and no post-rollback health verification | Harden | Connector | P1 | M | Med | Connector release | Rollback takes DB snapshot + runs health self-check; failure surfaces to manager | Batch into same 2.17.x staged push |
| P1-17 | Pre-update backup in safe-update is DB-only and silently skipped when no backup config exists | Fix | Connector/Updates | P1 | M | Low | P0-07 | No-config → hard refusal or explicit logged consent; result verified before update | Behavior change is strictly safer |
| P1-18 | Pre-update backup dispatched async — races the update it precedes | Fix | Updates | P1 | S | Low | P0-07 | Update proceeds only after backup completion (or explicit skip) | Same pattern as safe-update path |
| P1-19 | Core update reports success regardless of connector result | Fix | Updates | P1 | S | Low | — | Connector response parsed; failure surfaces + notifies | — |
| P1-20 | SendNotificationJob retries create duplicate logs → false 'Delivery FAILED' escalations | Fix | Notifications | P1 | S | Low | — | One log row per logical send; retry updates it; no false escalations | — |
| P1-21 | Quiet hours silently annihilate non-critical notifications (no record, no deferral) | Fix | Notifications | P1 | M | Low | — | Deferred delivery queue; in-app record always written | — |
| P1-22 | Email channel logs 'sent' when merely queued — SMTP failures invisible, never escalate | Fix | Notifications | P1 | M | Low | — | Sent status from actual transport result; failures escalate | — |
| P1-23 | Acknowledge endpoint mutates on GET — Slack/Discord link-preview crawlers auto-ack alerts | Harden | Notifications | P1 | S | Low | — | Ack = POST with confirm page (or signed one-time token immune to preview) | — |
| P1-24 | notify_down/recovery/degraded toggles saved but consumed nowhere | Fix | Notifications | P1 | S | Low | — | Toggles honored in NotifyIncident routing; test per flag | Default = current behavior |
| P1-25 | GenerateReport catch-all Throwable defeats retries — 3 transient failures kill a client's schedule | Fix | Reports | P1 | S | Low | — | Transient errors rethrow; only permanent errors mark failed | — |
| P1-26 | Report ShouldBeUnique lock can swallow a scheduled monthly report after next_run_at advanced | Fix | Reports | P1 | S | Low | P1-07 | next_run_at advanced only after successful dispatch; uniqueFor set | — |
| P1-27 | Heartbeat gap during long uploads triggers false 'stuck' → duplicate backup runs | Fix | Backups | P1 | M | Low | — | Heartbeat ticks during upload phase; threshold > worst-case gap; prepare() never re-runs Completed rows (merged w/ stuck-retry double-run) | — |
| P1-28 | Manual backup deletion leaks secondary replicas/sidecars/manifests; global page lacks chain guard | Fix | Backups | P1 | M | Low | P0-03 | Delete removes all artifacts across destinations; chain guard on both pages | — |
| P1-29 | cancelBackup + progress tracking use unscoped client-tamperable backup ID | Harden | Backups | P1 | S | Low | — | Backup resolved through site relation + authz | — |
| P1-30 | sites.client_id FK ON DELETE CASCADE — client hard-delete would destroy all its sites | Harden | Clients | P1 | S | Low | — | FK → RESTRICT/SET NULL migration; deletion flow handles reassignment | Expand-only migration; restart PgBouncer after DDL |
| P1-31 | Selective restore tar extraction ignores exit code — silently restores nothing, reports success | Fix | Backups–restore | P1 | S | Low | — | Exit code + stderr checked; failure fails the restore visibly | — |
| P1-32 | Selective-restore file browser broken for every new (v3) backup; precache path dead | Fix | Backups–restore | P1 | M | Low | — | Browser understands v3 layout; browse test against fresh backup | — |
| P1-33 | Queue/worker capacity plan for 100+ sites: backup window, uptime workers, SEO crawl isolation, restore duration ceiling (merged, 4 findings) | Harden | Horizon | P1 | L | Med | P1-06 | Load model documented; supervisors sized; SEO crawls off 'general'; restore timeout > sum of sub-step budgets | Env-tunable; apply in quiet window |
| P1-34 | deploy.sh force-removes running nginx early — hard downtime every deploy, full outage if later step fails | Fix | Deploy | P1 | S | Med | — | nginx recreated last, only after app healthy | Test on a low-traffic window first |
| P1-35 | Certbot renews but nothing reloads nginx — expired cert served after long deploy gaps | Fix | Deploy | P1 | S | Low | — | Renewal hook reloads nginx; cert age alert | — |
| P1-36 | Scheduled tasks have no failure hooks — db:dump/verification failures reach only the log | Harden | Observability | P1 | S | Low | — | onFailure notification on critical tasks; heartbeat URL configured and verified | — |
| P1-37 | No branch protection on main; CI deploy gate fails open on API error/missing gh (merged, 2 modules) | Harden | Deploy | P1 | S | Low | — | Branch protection on; gate exits non-zero on any error | — |
| P1-38 | Stuck in_progress AppBackup row permanently blocks all future app backups (merged, 2 modules) | Fix | Observability | P1 | S | Low | — | Stale sweep (like site restores got); test | — |
| P1-39 | Restore/replica disk-space + temp lifecycle: no restore precheck; restore-*/verify-*/app-backup-* debris leaks until DiskSpaceGuard halts fleet backups (merged, 3 findings) | Harden | Backups | P1 | M | Low | — | DiskSpaceGuard on restore + replicate; sweeper covers all temp prefixes | — |
| P1-40 | Testing sprint: zero/near-zero coverage modules — uptime, performance, DNS, activity pipeline, Google, Cloudflare, portal, plan apply, retention/backup pipelines, integration failure paths (merged, ~11 findings) | Harden | Cross | P1 | XL | Low | after P0 wave | Each P0/P1 fix lands with regression test; smoke coverage per listed module | Pure test additions |
| P1-41 | Update HTTP calls use 30s default timeout vs unbounded connector update work | Harden | Updates | P1 | S | Low | — | Timeout raised/polled per operation type | — |
| P1-42 | Auto-rollback and health-check failures complete silently — no notification | Harden | Updates | P1 | S | Low | P1-05 | safe_update_failed/rolled_back events registered + routed | — |
| P1-43 | Manual plugin 'Rollback' dead: UpdateLog looked up by name using slug | Fix | Updates | P1 | S | Low | — | Lookup by slug column; rollback test | — |
| P1-44 | IR escalations invisible: events unregistered, module has no UI | Fix | Incident Response | P1 | M | Low | P1-05 | incident_response_* in EVENTS; escalated incidents in to-do feed + site tab | Additive enum + read-only UI |
| P1-45 | Incident-driven plugin updates bypass per-site safe_updates_enabled opt-in | Harden | Incident Response | P1 | S | Low | P0-07 | IR updates route through safe-update pipeline when flag on | — |
| P1-46 | Prompt injection from managed-site content can steer agent holding mutating tools | Harden | Incident Response | P1 | M | Low | P0-20/21 | Site-derived content demarcated as untrusted; mutating tools require playbook allowlist | Reduces agent authority only |
| P1-47 | Deprecated Claude models (retired 2026-06-15) + no API retry | Fix | Incident Response | P1 | S | Low | — | Current model ids; 429/5xx retry | — |
| P1-48 | OAuth callback state check passes when both states null (CSRF bypass) | Fix | Google | P1 | S | Low | — | Null state rejected; test | — |
| P1-49 | Google fetch uniqueId omits date range — user 7d/90d fetches silently dropped, UI spins forever | Fix | Google | P1 | S | Low | — | uniqueId includes range; UI resolves | — |
| P1-50 | Empty connector inventory response wipes site_plugins/themes/users incl. license keys | Harden | Sites | P1 | S | Low | — | Empty payload = no-op + warning, never a wipe | — |
| P1-51 | Error counts inflate / resolved errors resurrect every 6h fetch (no ingestion watermark) | Fix | Error logs | P1 | M | Low | P0-16 | Watermark-based ingestion; resolved rows stay resolved | — |
| P1-52 | Burst audit events permanently lost (newest-500 DESC, no pagination) | Fix | Activity | P1 | M | Med | Connector release | Paginated pulls until watermark met | Batch into connector push |
| P1-53 | Scheduler mutexes stick 24h after hard kill (no stop_grace_period, default TTL) | Harden | Horizon | P1 | S | Low | — | withoutOverlapping TTL sized per task; grace period set | — |
| P1-54 | ProcessNotificationBatch at-most-once: LPOP before dispatch + 30s supervisor timeout | Fix | Horizon | P1 | S | Low | — | Reliable-queue pattern (RPOPLPUSH/ack); no notification loss on kill | — |
| P1-55 | RDAP transient failure overwrites domain-expiry data with NULLs, not retried for 7 days | Fix | DNS | P1 | S | Low | — | Failure preserves last-known data + shorter retry | — |
| P1-56 | New sites never get DNS monitor / plan modules not materialized by wizard | Fix | DNS/Plans | P1 | M | Low | P1-58 | Site::created materializes plan modules; backfill scheduled | — |
| P1-57 | SyncCloudflareZone swallows exceptions on non-final attempts — retries dead, silent staleness (merged, 2 modules) | Fix | Cloudflare | P1 | S | Low | — | Exceptions rethrow on non-final attempts; failure visible | — |
| P1-58 | Three plan-application entry points, three different results (applyPlanToAll + Site::created skip schedules/security/tweaks) | Fix | Plans | P1 | M | Low | P0-11 | Single apply service used by all 3; parity test | — |
| P1-59 | createFromSite: no canAccessSite — snapshots другого owner's security config into global plan | Fix | Plans | P1 | S | Low | — | Authz on source site | — |
| P1-60 | applyToSites runs whole fleet synchronously in one Livewire request | Harden | Plans | P1 | M | Low | P1-58 | Queued per-site apply jobs with progress | — |
| P1-61 | Cloudflare HTTP errors persisted as plausible defaults (ssl_mode 'off' on API failure) | Fix | Cloudflare | P1 | S | Low | P1-12 | Failure → no write + error state | — |
| P1-62 | Push jobs (security/tweaks) have no failed() — settings stuck 'Pending' forever, stale score | Fix | Security hub | P1 | S | Low | — | failed() marks error state + notifies | — |
| P1-63 | Preset snapshots copy one site's encrypted CAPTCHA secret globally and push to other sites | Fix | Security hub | P1 | S | Low | P0-11 | Same bulk-safe whitelist applied to presets | — |
| P1-64 | Dropbox driver retries only 401 — one 429/5xx chunk aborts entire multi-GB upload | Harden | External | P1 | S | Low | — | Retry w/ backoff on 429/5xx per chunk | — |
| P1-65 | FetchKeywordRankings: non-transactional delete-then-insert, wipes placeholders, swallows exceptions | Fix | Google | P1 | S | Low | — | Transactional upsert; errors surface | — |
| P1-66 | Ops verification checklist: heartbeat URL live, horizon_stopped alert routed, weekly restore-verify green (unverifiable in audit) | Harden | Observability | P1 | S | Low | — | Each verified in prod + documented | Runbook item, no code |
| P2-01 | Infrastructure report section can never render; DnsGatherer/ErrorLogGatherer dead code | Fix | Reports | P2 | M | Low | P0-27 | Section renders with real SSL/domain/email data | Phase 2 gate item |
| P2-02 | Client-report Cloudflare metrics read snapshot columns nothing writes | Fix | Cloudflare/Reports | P2 | M | Low | P1-12 | Analytics job populates snapshots; report shows real data | — |
| P2-03 | Reports embed rolling 28d Google cache regardless of period, no staleness check | Harden | Google/Reports | P2 | M | Low | — | Period-matched fetch or staleness disclosure in report | — |
| P2-04 | Schedule recipient emails never validated; one bad address aborts delivery to ALL | Fix | Reports | P2 | S | Low | — | Per-recipient validation + per-recipient failure isolation | — |
| P2-05 | Report job timeout < worst-case Gotenberg budget; no stuck-'generating' recovery | Harden | Reports | P2 | S | Low | — | Timeout sized; stale sweep | — |
| P2-06 | Archived/inactive clients keep live portals; portal on by default forever | Fix | Clients | P2 | S | Low | — | Portal disabled on archive; explicit enable | — |
| P2-07 | Portal report view lacks data_snapshot/status guard — incomplete reports render empty shell | Fix | Clients | P2 | S | Low | — | Same guard as token view | — |
| P2-08 | SSL-expiry monitoring absent (dead check_ssl/ssl_expires_at plumbing, no UI writer) | Harden | Uptime | P2 | M | Low | — | Expiry checks + threshold alerts + client-visible badge | Client-facing win; plumbing exists |
| P2-09 | Extend safe-update pipeline to themes, core, and bulk operations | Wow | Updates | P2 | L | Med | P0-07/08 | Themes/core/bulk get backup+verify+rollback | Pilot sites first |
| P2-10 | MOD-06 Client Portal v2 (white-label domain, live widgets, approvals, DKIM sending) | Wow | Portal | P2 | XL | Med | Phase-1 data fixes, P2-01..07 | Pilot client using branded portal end-to-end | Feature-flag per client; pilot first |
| ~~P2-11~~ | ~~MOD-05 Client Billing & Subscriptions (Stripe)~~ — **DROPPED (internal tool, 2026-07-11)** | — | — | — | — | — | — | — | — |
| P2-12 | MOD-07 Uptime SLA contracts + Status Pages v2 | Wow | Uptime/Status | P2 | L | Low | P1-05, P1-20..24 | Monthly attainment statement in report; subscriber notifications; maintenance posts | Additive surfaces |
| P2-13 | MOD-01a Vulnerability feed (Patchstack/WPScan inventory matching) | Wow | Security | P2 | M | Low | API license | CVE matches in to-do feed; drives safe-update priority | Read-only layer |
| P2-14 | MOD-04 Safe Updates v2 — visual regression screenshots | Wow | Updates | P2 | L | Med | P2-09, headless Chrome service | Before/after diff gates auto-rollback vs hold | New Docker service; pilot sites |
| P2-15 | Competitor benchmarking dead end (URLs collected, nothing ever tests them) | Fix | Performance | P2 | M | Low | P1-09 | Competitor tests run + comparison renders | — |
| P2-16 | Maintenance-plan performance interval dead knob (writes interval_minutes, scheduler reads hardcoded frequency) | Fix | Performance/Plans | P2 | S | Low | P1-58 | Plan interval honored | — |
| P2-17 | SEO score saturation: per-page penalties sum to zero category scores | Fix | SEO | P2 | S | Low | — | Penalty capped per category; scores plausible | Scores shift — note in changelog |
| P2-18 | Broken-link suggestions read from wrong audit (NULLS FIRST) | Fix | SEO | P2 | S | Low | — | Latest completed audit selected | — |
| P2-19 | SSRF: SEO quick-audit crawls + custom-webhook URLs + monitor URLs can reach internal Docker services (merged, 3 modules) | Harden | Cross | P2 | M | Low | — | Private-range/host allowlist validation on all outbound user URLs | — |
| P2-20 | CrawlSitePages retry non-idempotent (duplicates rows) + runtime exceeds own timeout | Harden | SEO | P2 | M | Low | — | Idempotent upsert; chunked resumable crawl | — |
| P2-21 | bulkFix mutates live client sites synchronously in the Livewire request | Harden | SEO | P2 | M | Low | P1-03 | Queued job; audit trail per item | Elevated (connector mutation) but after P1-03 |
| P2-22 | CAPTCHA verification fails open when provider unreachable | Harden | Security hub | P2 | S | Med | Connector release | Configurable fail-closed; default documented | Batch into connector push |
| P2-23 | Email 2FA fail-open default + zero security-score credit | Harden | Security hub | P2 | S | Med | Connector release | Fail-closed option; score credit | — |
| P2-24 | .htaccess self-check probes only homepage; leaves web-readable backup | Harden | Connector | P2 | S | Med | P0-12 | Multi-path check; backup outside webroot/denied | Same connector release |
| P2-25 | Stale local state after successful safe update — plugin still shows update pending | Fix | Updates | P2 | S | Low | — | Local inventory refreshed post-update | — |
| P2-26 | Rollback points unusable for premium (non-wp.org) plugins | Harden | Updates | P2 | M | Low | — | Local zip snapshot before update for non-wp.org | — |
| P2-27 | No sweeper for safe_updates stuck in intermediate states | Harden | Updates | P2 | S | Low | — | Stale sweep like restores | — |
| P2-28 | executeRollback consumes rollback point before validating result | Fix | Updates | P2 | S | Low | — | Point consumed only on verified success | — |
| P2-29 | Transient scheduled-backup failure → premature failure notification + duplicate Backup row | Fix | Backups | P2 | S | Low | — | Notify only after retries exhaust; retry reuses row | — |
| P2-30 | pre_update backups locked forever — retention never reclaims | Harden | Backups | P2 | S | Low | P0-03 | Unlock after N days/successful verify | — |
| P2-31 | backupAllSites stagger outruns 45-min stale threshold beyond ~15 sites → spurious auto-retries | Fix | Backups | P2 | S | Low | P1-33 | Threshold scales with stagger | — |
| P2-32 | pollPrepareStatus sleep()-polls, occupying a backup worker up to 60 min | Harden | Backups | P2 | M | Low | P1-33 | Re-dispatch with delay instead of sleep | — |
| P2-33 | Level-B verification: legacy verifier for v3, primary replica only, statistically thin sampling | Harden | Backups | P2 | M | Low | — | v3-aware verify; replicas sampled; rate scales with fleet | — |
| P2-34 | Zip entry names from semi-trusted WP site unsanitized before extractTo | Harden | Backups | P2 | S | Low | — | Path traversal entries rejected; test | — |
| P2-35 | pg_dump/psql platform backups via pooled connection instead of pgsql_direct; PGPASSWORD/-pass on command line (merged, 3 findings) | Harden | Observability | P2 | S | Low | — | Direct connection; env/file-based secrets | — |
| P2-36 | Plan drift invisible: editing a plan never re-applies; stale module rows | Harden | Plans | P2 | M | Low | P1-58 | Drift indicator + re-apply action | — |
| P2-37 | Domain module tables lack unique(site_id) — config duplication race | Harden | Plans | P2 | S | Low | — | Unique index + upsert | Expand-only migration; PgBouncer restart |
| P2-38 | 'Apply to Unassigned' falls back to arbitrary plan claiming it's the default | Fix | Plans | P2 | S | Low | — | No default → explicit error | — |
| P2-39 | ClientProfitability bypasses ClientPolicy; sortBy raw orderBy 500s; unvalidated free-string inputs (merged, 3 findings) | Harden | Clients | P2 | S | Low | — | Policy applied; sort whitelist; validated enums | — |
| P2-40 | ClientsList ignores client_user assignments — assigned users can't see listed clients | Fix | Clients | P2 | S | Low | P1-01 | Assignments included in list scope | — |
| P2-41 | CheckDns timeout leaves next_check_at past-due → every-minute 90s blocking job | Harden | DNS | P2 | S | Low | P1-07 | failed() advances next_check_at | — |
| P2-42 | DNS check failures fully silent — no error state/counter/notification | Harden | DNS | P2 | S | Low | P0-27 | Consecutive-failure state + alert | — |
| P2-43 | Retention categories missing: dns_changes, php_error_logs, in_app_notifications, incident_responses jsonb (merged, 4 findings) | Harden | Retention | P2 | S | Low | P0-01 | Categories added; nightly prune verified | DELETE-only, batched |
| P2-44 | Connector sorts logs by strcmp on 'DD-Mon-YYYY' — non-chronological across month/year | Fix | Connector | P2 | S | Med | Connector release | Timestamp-parsed sort | Batch into connector push |
| P2-45 | Failed-login stats panel dead — connector never logs failed_login | Fix | Activity | P2 | S | Med | Connector release | Panel shows real data | — |
| P2-46 | Audit-trail duplicates possible: pull job not unique, no dedup key | Harden | Activity | P2 | S | Low | — | Dedup key or unique job | — |
| P2-47 | FetchPhpErrorLogs swallows all failures — broken endpoint invisible | Harden | Error logs | P2 | S | Low | — | Failure counter + alert | — |
| P2-48 | Analytics 'previous period' comparison permanently null (dead feature) | Fix | Google | P2 | S | Low | — | Change % renders | — |
| P2-49 | Google refresh: no concurrency guard, no expiry skew, empty-string refresh_token storable | Harden | Google | P2 | S | Low | P0-23 | Lock around refresh; skew buffer; empty rejected | — |
| P2-50 | Google dispatcher re-dispatches dead connections forever; breakers recorded but never gate | Harden | Google | P2 | S | Low | P0-23 | Breaker consulted in dispatch | — |
| P2-51 | Cloudflare zone_id unvalidated, interpolated into REST paths + GraphQL | Harden | Cloudflare | P2 | S | Low | — | Format validation (hex32) | — |
| P2-52 | Any non-viewer can use teammates' Cloudflare connections and enumerate their zones | Harden | Cloudflare | P2 | S | Low | — | Connection scoped to owner/shared flag | — |
| P2-53 | One transient failure flips Cloudflare is_valid=false, silently disabling sync 24h | Harden | Cloudflare | P2 | S | Low | — | Same transient/permanent split as P0-23 | — |
| P2-54 | Notification dedup window suppresses distinct alerts, traceless + non-atomic | Harden | Notifications | P2 | S | Low | — | Atomic check; suppressed alerts logged | — |
| P2-55 | Decrypted channel configs (webhook URLs) cached plaintext in Redis 10 min | Harden | Notifications | P2 | S | Low | — | Encrypted-at-rest cache or no cache | — |
| P2-56 | IR: sync nested backup/safe-update can exceed 900s job timeout | Harden | Incident Response | P2 | S | Low | P0-20 | Async orchestration or sized timeout | — |
| P2-57 | Decrypted Anthropic API key round-tripped to browser | Harden | Incident Response | P2 | S | Low | — | Masked placeholder pattern (as P0-14) | — |
| P2-58 | UserManagement role mutations rely on middleware only; unvalidated role strings | Harden | Auth | P2 | S | Low | — | Enum-validated + in-component authz | — |
| P2-59 | SetCurrentSite middleware resolves any site id without authorization | Harden | Auth | P2 | S | Low | P1-01 | visibleTo applied | — |
| P2-60 | Nonce anti-replay race without persistent object cache on WP | Harden | Connector | P2 | M | Med | Connector release | Transient-locked nonce store or tolerance documented | — |
| P2-61 | Connector IP whitelist trusts spoofable forwarded-for on non-Cloudflare sites | Harden | Connector | P2 | S | Med | Connector release | REMOTE_ADDR authoritative unless CF-verified | — |
| P2-62 | Legacy no-nonce HMAC branch weakens auth surface | Harden | Connector | P2 | S | Med | Connector release | Branch removed after fleet ≥ 2.16 | Verify fleet version first |
| P2-63 | Self-update/rollback/plugin endpoints = RCE-on-fleet concentration; no download-host allowlist | Harden | Connector | P2 | M | Med | Connector release | Signed-URL host allowlist both sides | — |
| P2-64 | ValidateExternalConnections serial, tries=1, will exceed own timeout at fleet scale | Harden | External | P2 | S | Low | — | Fan-out per connection | — |
| P2-65 | Cloudflare rate limiter throws instead of deferring | Harden | External | P2 | S | Low | — | Job release/delay on limit | — |
| P2-66 | app container memory undersized for FPM/opcache — 502 risk under concurrency | Harden | Docker | P2 | S | Med | — | Limit ≥ FPM workers × avg + opcache | Quiet-window recreate |
| P2-67 | Flat bridge network: gotenberg/certbot reach Postgres+Redis; Redis auth conditional | Harden | Docker | P2 | M | Med | — | Segmented networks; Redis auth mandatory | Staged recreate |
| P2-68 | nginx location add_header drops security headers on static assets; 100M/64M body mismatch; dead healthchecks incl. scheduler no-op; /health discloses internals (merged, 4 findings) | Harden | Docker/Obs | P2 | M | Low | — | Headers on all responses; limits aligned; healthchecks real; /health authenticated | — |
| P2-69 | Health-score threshold systems conflict (90/70 vs 75/50) | Fix | Sites | P2 | S | Low | — | Single canonical thresholds everywhere | — |
| P2-70 | Global notification-critical/security jobs on lowest-priority 'default' queue | Harden | Horizon | P2 | S | Low | P1-33 | Dedicated queues assigned | — |
| P2-71 | Report download authz inconsistencies (bulk blocks admins; single blocks client-assigned) | Fix | Reports | P2 | S | Low | P1-01 | Consistent policy both paths | — |
| P2-72 | Zero-downtime deploys: SHA-tagged images + one-command rollback | Wow | Deploy | P2 | M | Med | P1-34 | Deploy with no dropped requests; rollback < 1 min | Parallel-run old flow until proven |
| P3-01 | MOD-02 Incremental Backup Engine (deltas, synthetic fulls, verified chains, Object Lock tier) | Wow | Backups | P3 | XL | High | P0-03/04, connector 2.17+, restore-testing | Chain restore proven weekly per site; badge in portal | Pilot 3 sites for a month; never replaces fulls until proven |
| P3-02 | MOD-03 One-click staging & cloning (restore-to-sandbox, safe-update rehearsal, promote) | Wow | Sites | P3 | XL | Med | P3-01 or full-backup path, MOD-04 | Update rehearsed on staging then promoted | Sandbox isolated subdomains only |
| P3-03 | MOD-01b Off-server malware scan + one-click quarantine | Wow | Security | P3 | L | Med | P2-13, connector file-shipping release | Flagged file quarantined with staged-restore undo | Quarantine behind backup invariant (P0-20 pattern) |
| P3-04 | MOD-08 Multi-region probes + transaction checks + RUM beacon (also resolves single-probe-location finding) | Wow | Uptime | P3 | L | Med | 2–3 probe VPSes, connector beacon release | 2-of-3 confirmation before alert; checkout transaction check live | Shadow-mode probes before they gate alerts |
| P3-05 | MOD-09 Fleet edge WAF & virtual patching via Cloudflare | Wow | Security | P3 | L | Med | P2-13, P1-12 | Per-CVE rule pushed + verified per site | Log-only rules first, then block |
| P3-06 | MOD-10 Bulk fleet ops + authenticated REST API (Sanctum) | Wow | Fleet | P3 | L | Med | Tags module, P1-04 sweep | Tag-scoped bulk install/activate/remove; API token-scoped | API read-only endpoints first |
| P3-07 | MOD-11 Team collaboration & granular permissions (+ revisit auth story/passkeys post-2FA removal) | Wow | Auth | P3 | L | Low | P1-01/02/04 | Capability gates on destructive actions; approval workflow option | — |
| P3-08 | MOD-12 Connector-independent emergency restore (SFTP/host-API bootstrap, selective restore) | Wow | Backups | P3 | L | High | Backup pipeline, credential vault | Dead site restored with no working connector (staging proof) | Credential vault encrypted; staging-proven before sale |
| P3-09 | MOD-13 Multisite support | Wow | Platform | P3 | XL | High | Connector + data-model rework | — | Defer unless a client demands it |
| P3-10 | Dashboard 'disconnected sites' tile + tag filter (Tags currently write-only; SitesList/BulkSettings dead code removal) (merged, 3 findings) | Wow/Fix | Sites | P3 | S | Low | P0-18 | Tile live; tag filter usable; dead components removed | — |
| P3-11 | LIKE/ILIKE escape helper corrupts own escapes (merged: Sites + Error logs) | Fix | Cross | P3 | S | Low | — | Backslash escaped first; test | — |
| P3-12 | Re-adding a soft-deleted site impossible (unique:sites,url collision) | Fix | Sites | P3 | S | Low | — | Rule ignores trashed rows | — |
| P3-13 | health_score refreshed nightly + only for connected sites — stale/NULL filters | Harden | Sites | P3 | S | Low | — | Refresh on sync; NULLs sorted last | — |
| P3-14 | Uptime: 365d recompute per check + uptime_365d reflects 45-day retention | Harden | Uptime | P3 | S | Low | — | Aggregates precomputed; label honest | — |
| P3-15 | One degraded check flips Site.is_up on dashboards | Fix | Uptime | P3 | S | Low | — | Degraded ≠ down | — |
| P3-16 | 'ping' monitor type unimplemented; keyword+HEAD guarantees false downs | Fix | Uptime | P3 | S | Low | — | Invalid combos rejected/implemented | — |
| P3-17 | uptime_monitors.url varchar(255) vs max:2048 validation — 500 on save | Fix | Uptime | P3 | S | Low | — | Column widened or validation aligned | Expand-only; PgBouncer restart |
| P3-18 | Maintenance-window early return never advances next_check_at (every-minute redispatch) | Harden | Uptime | P3 | S | Low | — | next_check_at advanced during windows | — |
| P3-19 | Perf: weekly ignores day_of_week; chart misaligns mobile/desktop dates; schema drift (base64 screenshots, ~15 dead columns) (merged, 3 findings) | Fix | Performance | P3 | M | Low | — | Day honored; chart aligned; screenshots externalized | — |
| P3-20 | SEO: negative scan_duration; normalizer drops query strings; silent partial link coverage; duplicate redirect systems (merged, 4 findings) | Fix | SEO | P3 | M | Low | — | Duration positive; coverage indicator shown; one redirect system | — |
| P3-21 | activity_log settings marked Applied without enforcement verification | Harden | Security hub | P3 | S | Low | — | Verified before score credit | — |
| P3-22 | Report draft-recommendation linking is site-global — concurrent reports mislink | Fix | Reports | P3 | S | Low | — | Linked by report id | — |
| P3-23 | Reports tech debt: duplicated rec-gathering, unused v2 blade, unscoped template load, weekly off-by-one, no PDF retention | Harden | Reports | P3 | M | Low | P2-43 | Debt items closed | — |
| P3-24 | Plans: applyToSites counters wrong for modules-only; dead BulkSettings wizard removal | Fix | Plans | P3 | S | Low | P1-58 | Counters accurate; dead code gone | — |
| P3-25 | Portal toggle/token regeneration not activity-logged; portal recomputes health in Blade (dual source of truth) | Harden | Clients | P3 | S | Low | — | Logged; persisted health_score used | — |
| P3-26 | DNS: decorative acknowledge; host-derivation duplicated 4 ways; soft-delete scoping inconsistencies (merged, 3 findings) | Fix | DNS | P3 | M | Low | P0-27 | Ack persists; single derivation helper; scopes consistent | — |
| P3-27 | DNS expected-record pinning + email-deliverability grading | Wow | DNS | P3 | M | Low | P0-27 | Pinned records alert on drift; SPF/DKIM/DMARC grade in report | — |
| P3-28 | Activity: since-cursor timezone unpinned; type/severity free-form vs enum convention (merged, 2 findings) | Harden | Activity | P3 | S | Low | — | UTC pinned; enums | — |
| P3-29 | in_app daily digest force-mails every user | Harden | Notifications | P3 | S | Low | — | Opt-out honored | — |
| P3-30 | Google: double-encryption trap (merged, 2 modules); dead getExternalLinks; disconnect double-invoke crash (merged, 3 findings) | Harden | Google | P3 | S | Low | — | Single encryption layer; dead code removed; null-safe | — |
| P3-31 | Cloudflare: listDnsRecords 100-record truncation; plan_label TypeError on null; ~150 lines dead surface (merged, 3 findings) | Fix | Cloudflare | P3 | M | Low | — | Pagination; null-safe; dead code removed | — |
| P3-32 | IR: cooldown control flow via exceptions spams error logs | Harden | Incident Response | P3 | S | Low | — | Log noise gone | — |
| P3-33 | Horizon repeated-failure alert: non-atomic counter, fires only on exactly 3rd failure | Fix | Horizon | P3 | S | Low | — | Atomic ≥3 semantics | — |
| P3-34 | env() in bootstrap/app.php for TRUSTED_PROXIES | Fix | Config | P3 | S | Low | — | Moved to config() | — |
| P3-35 | getTableRowCounts COUNT(*) over every table per app backup — crawls at scale | Harden | Observability | P3 | S | Low | — | pg_stat estimates | — |
| P3-36 | Duplicate exception logging in production renderable hook (no stack trace) | Fix | Observability | P3 | S | Low | — | Single log entry with trace | — |
| P3-37 | Backups dead code: legacy v2 path, never-dispatched PrecacheBackupFileList, orphaned StreamingBackupUploader | Harden | Backups | P3 | S | Low | P1-32 | Dead code removed | — |

**Merge accounting:** cross-module duplicates consolidated into single rows (noted "merged"): retention crash ×2, chain retention ×2, blind force-release ×2, safe-update lock ×3, Google deactivation ×2, Horizon memory ×2, CopySettingsModal ×2, Cloudflare request()/E-59 ×2, health-states ×2, app-backup stuck ×2, off-site dumps ×2, PGPASSWORD ×2, LIKE-escape ×2, double-encryption ×2, plus the consolidated sweeps P1-02 (7 read-scope findings), P1-04 (8 small authz gaps), P1-33 (4 capacity findings), P1-40 (~11 test-coverage findings), P2-19 (3 SSRF findings), P2-43 (4 retention gaps), P2-68 (4 infra hygiene findings). Every finding from the audit input maps to exactly one row above.

---

## Appendix

### Method & confidence
- **Coverage:** 25 areas audited (18 modules + 7 cross-cutting) by independent agents, each applying the charter §4.2 checklist.
- **Verification:** all 45 Critical/High findings were re-checked by a separate adversarial agent instructed to refute; 0 were refuted, 10 downgraded to Medium. Findings below High are unverified single-pass and should be confirmed before acting.
- **Stale schema dump:** `database/schema/pgsql-schema.sql` is ~17 migrations behind and still lists dropped tables (`security_commands`, `users.two_factor_*`). Trust `database/migrations/`, not the dump. Regenerating the dump is itself a backlog item.

### Could not be verified read-only
- A **real end-to-end restore** of a production backup onto a live WordPress site (correctness of the atomic staged restore under real data).
- Actual **S3 / Dropbox streaming** behaviour on a multi-GB site (memory, timeouts, replica consistency).
- Live **connector 2.17.0** behaviour on the fleet for the new 2FA / unban / whitelist paths (staging validation still pending per the session notes).
- External-feed liveness (Wordfence Intelligence, RDAP, Google/Cloudflare quotas) under real rate limits.

### Decisions taken (2026-07-11) — see the "Post-audit decisions" callout at the top
- **Billing/subscriptions:** ❌ Not building — internal tool.
- **Backups:** ✅ Option B — fix the broken incremental logic AND build an efficient full+incremental engine with an opportunistic WP-CLI fast path (mixed fleet, no SSH, capability-detected). *Backup architecture is being (re)designed — see the "Backup engine — recommended architecture" note appended to Future Module #2.*

### Still-open questions for the product owner
- **Malware scanning:** build a signature scanner, or integrate a third-party feed (Patchstack/WPScan → virtual patching)? Recommendation leans to the feed/virtual-patching direction.
- **Backup delta granularity:** file-level deltas (simpler) vs. block-level deltas (smaller transfer, more complex) — decided during the backup-engine design.

*Generated 2026-07-11. Findings that imply code fixes should each become their own implementation session with tests and a safe-rollout plan — not applied in bulk.*