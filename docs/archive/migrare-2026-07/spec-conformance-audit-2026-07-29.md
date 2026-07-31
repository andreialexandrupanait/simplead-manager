# SimpleAd Manager — SPEC Conformance Audit (main / production)

**Source of truth:** `SPEC-SAD-MANAGER.md` · **Codebase:** `/opt/apps/simplead-manager` @ `main` · **Requirements assessed:** 129 (125 scored, 4 explicitly `na`/deferred)

---

## 1. Conformance summary by SPEC section

| Section | ✅ impl | 🟡 partial | ❌ missing | Headline |
|---|---|---|---|---|
| §1 Scope & rules | 3 | 1 | 0 | Scope boundaries honored; single-user simplification NOT applied (roles + client portal remain) |
| §2 Principles | 1 | 3 | 0 | Building blocks exist; exception-first default, one-click bulk-update, batch approval all fall short |
| §3.1 Fleet sidebar | 2 | 1 | 3 | Fleet-nav restructure never applied — old English groups, no Alerte/Site-uri items |
| §3.2 Site sidebar | 1 | 1 | 0 | Site sidebar fully restructured and correct; ~15 leaf routes still placeholders |
| §3.3 Site nav behaviors | 3 | 1 | 0 | Switcher + collapse solid; no persistent quick-action bar across sections |
| §4 Site-list screen | 0 | 1 | 0 | Spec's WPMU two-view screen exists but is orphaned (no route) |
| §4.1 Row structure | 0 | 1 | 0 | Spec-exact row exists only in orphaned code; live row differs |
| §4.2 Icon groups | 0 | 1 | 0 (+1 na) | 3-group layout only in dead code; live dashboard uses different icons |
| §4.3 Color codes | 1 | 0 | 0 | 4-color system correct in shared component |
| §4.4 Tabs/filters/actions | 0 | 3 | 3 | No spec tabs, no group-by, half the filters/verbs missing, no bulk-connect, no grid thumbnails |
| §4.5 Panou (dashboard) | 0 | 0 | 1 | Routed dashboard is decorative stat-cards — violates "no widgets", no 3 bands |
| §5.1 Uptime/SSL/DNS | 4 | 2 | 0 | SSL/DNS/domain/response-time strong; no critical-tier cadence, no 2nd location |
| §5.2 Security signals | 2 | 3 | 0 | Vulns + failed-logins wired; new-admin/spam-user/theme-integrity not automated |
| §5.3 Health/EOL | 6 | 2 | 2 | Broad coverage; EOL-PHP and PHP-error attribution absent |
| §5.4 Forms/Woo | 1 | 1 | 0 | Woo complete; form check not weekly and email delivery actively aborted |
| §5.5 Performance | 0 | 1 | 0 | Desktop+mobile+CWV correct but weekly default, no monthly option |
| §5.6 PHP error architecture | 0 | 0 | 4 (+1 na) | Entire mandated own-handler architecture absent — reads debug.log (forbidden) |
| §5.7 Broken links | 8 | 0 | 0 | Fully conformant, best-covered section |
| §6.1 Operations engine | 3 | 2 | 0 | Engine interface/queues/rollback exactly per spec; CircuitBreaker/Dispatchers not consolidated |
| §6.2 11 operation types | 0 | 1 | 0 | Only the Cloudflare-purge pilot runs through the engine |
| §7.1 Update global view | 1 | 0 | 0 | Plugin-axis grouping + cross-site execution present |
| §7.2 Smart rules | 2 | 2 | 1 | Engine correct (flag-OFF); canary rollout missing, Rule-1 preconditions incomplete |
| §7.3 Risk list | 0 | 1 | 0 | Table + read path exist; nothing auto-populates or edits it |
| §7.4 Smoke/screenshots | 0 | 2 | 0 (+1 na) | Smoke service complete but unwired; screenshot retention not enforced |
| §7.5 Key URLs | 0 | 0 | 1 | No key-URL derivation at all |
| §8 Backups | 6 | 0 | 0 | Fully conformant incl. verified-restore differentiator (pilot-scoped) |
| §9 Plans/Profile | 1 | 2 | 0 | Plan bound to site correctly; names wrong, no unified Profile, no key-URLs/test-address |
| §10 Onboarding | 0 | 1 | 1 | It's a 4-step wizard (spec forbids); auto-detect/propose/first-backup unbuilt |
| §11 Integrations | 6 | 3 | 1 | GSC/GA4/CF/Telegram/Wordfence solid; app.simplead.ro task API absent |
| §12.1 Report basics | 1 | 0 | 0 | Per-site white-label PDF fully wired |
| §12.2 Report sections | 0 | 1 | 0 | All 6 present but Security/Performance/Traffic order wrong |
| §12.3 Send gates | 1 | 1 | 1 | Incident + uptime<99% gates work; failed-integration gate not built |
| §12.4 Invoice-justifying figure | 0 | 1 | 0 | Data exists but not composed into attributed narrative |
| §13 Presets (10/5/2/3) | 0 | 3 | 3 | Core "55→10" reduction never done; no Standard SimpleAD package, no essential flag |
| §13bis Design system | 2 | 3 | 3 | Own-components good; dark mode SHIPPED (spec forbids), shadows/Tabler/weights off |
| §15 60-day observation | 1 | 0 | 0 | All four modules instrumented and logged |
| **TOTAL** | **56** | **45** | **24** | **+4 na** |

---

## 2. MISSING & PARTIAL items — prioritized by user value

### TIER 1 — Blocks the spec's core product identity (build first)

1. **§4.5 Panou is wrong screen (MISSING).** Routed dashboard = 5 decorative stat-cards + flat list, directly violating "Fără widget-uri decorative" and the 3-band model (attention grouped-by-client/severity → awaiting-approval → rest-in-one-line). *Next: rebuild `GlobalDashboard` as the 3-band exception view; delete stat-card row.*

2. **§6.2 Only 1 of 11 operations on the engine (PARTIAL).** The unifying engine (§6.1) is built and correct but only `cloudflare.purge` is migrated; the other 10 stay on legacy paths and `OperationRegistry` registers one op. *Next: wrap safe-update, backup, restore, plugin/theme mgmt, apply-plan/presets, settings-copy, instant-login as `Operation`s.*

3. **§2 P2 + §4.4 Bulk update at fleet level (PARTIAL/stub).** The headline operation — update ~30 sites in one click — is a toast stub (`SitesList.php:135`, `bulkUpdate/bulkApplyPlan/bulkApplyPresets` don't exist in the trait; toolbar buttons error). *Next: wire `bulkUpdate`/`bulkApplyPlan`/`bulkApplyPresets`/`bulkPurgeCache` to real engine ops.*

4. **§13 The 55→10 preset reduction (MISSING) + "Standard SimpleAD" default package (MISSING).** The central §13 product decision is absent: full tweak catalogue still rendered across 4 tabs, no curated 10-item surface, no auto-applied package at connection, no "Esențială" flag on `disable_all_updates`. *Next: define the 10-setting curated view + the auto-applied Standard SimpleAD package + essential badge.*

5. **§10 Onboarding is a wizard, auto-detect unbuilt (MISSING/PARTIAL).** Class is literally `CreateSiteWizard` (4 gated steps — the exact pattern §10 forbids); no connector install, no WP/PHP/plugin/Woo/GSC auto-detection, no proposed profile, no immediate first-backup/reference-scan. *Next: single-screen connect that installs, auto-detects, proposes a full profile, and dispatches first backup + scan on save.*

### TIER 2 — Named spec features with no code

6. **§5.6 PHP-error architecture (4× MISSING).** Connector tails/parses `debug.log` — exactly what spec forbids. No `set_error_handler`/`register_shutdown_function`, no signature dedup (uses `md5(level+message)` incl. variable values), no batch send, no remote kill switch. *Next: build the connector-side error handler + normalized (file+line+template) signature + remote on/off.*

7. **§7.2 Canary rollout (MISSING) + §7.5 Key URLs (MISSING).** Rule-1 auto-minor just fires per-site immediately — no 2-site canary, 30-min wait, key-URL smoke gate, or rollback+task-on-fail. And no key-URL derivation exists anywhere (GSC top-3 / contact / Woo / sitemap fallback / quarterly recalc). These two are coupled — canary needs key URLs. *Next: build key-URL derivation first, then canary orchestration on top.*

8. **§11 app.simplead.ro task API (MISSING).** No client reads per-site tasks; also blocks §12.2 "sarcini finalizate". *Next: add the read-only unidirectional task client.*

9. **§4.4 Missing surface: spec tabs (Actualizări/Alerte/Planuri counters), group-by (client/tag/plan), 4 of 7 filters (plan/Woo/WP-ver/PHP-ver), bulk-connect, grid thumbnails (all MISSING).** *Next: rebuild the site-list toolbar/tabs against the live routed screen, not the orphan.*

10. **§3.1 Fleet-nav restructure (3× MISSING).** VEDERE/OPERAȚIUNI/EVIDENȚĂ groups, `Alerte [n]`, `Site-uri [58]` never applied — sidebar still carries site-scoped modules. *Next: restructure `global-sidebar.blade.php` per §3.1.*

11. **§5.3 EOL-PHP (MISSING) + PHP-error attribution (MISSING) + §12.3 failed-integration send gate (MISSING).** Discrete, self-contained gaps each worth building.

### TIER 3 — Exists but deviates from spec detail (tighten, don't rebuild)

- **§8 Verified restore** — real and recorded, but weekly (not monthly), persistent sandbox (not ephemeral container), pilot-flag-scoped (not fleet-wide).
- **§4.1–4.3** — spec-exact row/groups/colors exist but only in the **orphaned `SitesList`**; live dashboard row differs. *Route the correct screen or port its layout.*
- **§5.1** anti-false-positive: incident row created on first failure; no 2nd location. **§5.5** performance runs weekly, no monthly option. **§5.2** new-admin/spam-user/theme-integrity exist but aren't automated/scheduled.
- **§7.4** `UpdateSmokeCheckService` is complete but wired only to its own test; screenshot "last 3 sets" retention has zero callers.
- **§9** plans named "Full Monitoring/Standard/Basic" not "Bază/Standard/Premium"; report cadence lives outside the plan; no unified Profile entity.
- **§11** critical alerts escalate once to a different channel, not "re-sent every 15 min until ack"; vuln 3-state not modeled (`active`/`fixed` only); no Wordfence webhook; NAS only via `local` path.
- **§12.2** section order wrong (Security near end). **§12.4** figures present but not composed into attributed narrative.
- **§13bis** — **dark mode fully shipped though spec says "no dark mode in v1"**; shadows in 57 files, gradients in 2 (spec: none); icons are Feather inline (not Tabler sprite); `font-semibold/bold` in 118 files (spec: 400/500 only). Row-density 44px ✓, own-components-not-a-kit ✓.
- **§1** three-role `UserRole` enum, per-user site scoping, and `client-portal/report.blade.php` still present despite "single user, no roles, no client portal".

### Genuinely DEFERRED / `na` (correct to omit)
§2 P5 (meta-principle), §4.2 reserved 4th icon group, §5.6 "batch vs per-error" (moot without the handler), §7.4 stage-3 masked pixel-diff (spec says do NOT implement). §8 `retention_dry_run` defaults `true` — deletion is a **prod-env flag flip**, not a code gap.

---

## 3. Overall conformance & top-5 to reach the vision

**Fully implemented: 56 / 125 scored = 44.8%.**
**Weighted (partial = 0.5): (56 + 22.5) / 125 = ~63%.**

Interpretation: the **operational plumbing is strong** — backups (§8 100%), broken-links (§5.7 100%), integrations (§11 6/10), the operations-engine core (§6.1), monitoring signals (§5.1/§5.3) all land. The **shortfall is product-shape**: the exception-first UX, the bulk-update flow, the preset system, onboarding, and the PHP-error architecture — the things that make it *the SPEC's* product rather than a generic WP monitor — are where the missing/partial mass sits.

### Top 5 to build/finish
1. **Rebuild the routed dashboard as the 3-band exception Panou** (§4.5) and route the spec-exact site-list/row instead of the orphan (§4/§4.1–4.4) — the single biggest UX gap.
2. **Migrate the remaining 10 operations onto the engine and make fleet bulk-update real** (§6.2, §2-P2, §4.4) — turns the built engine into the promised one-click-on-30-sites capability.
3. **Build the §13 preset system**: the curated 10-setting surface, the auto-applied "Standard SimpleAD" package at connection, and the "Esențială" flag.
4. **Replace the onboarding wizard with the single-screen auto-detect-and-propose flow** incl. immediate first backup + reference scan (§10).
5. **Implement the §5.6 connector error architecture** (own handler + signature dedup + kill switch) and the **key-URL derivation → canary rollout** chain (§7.5 → §7.2) — the two largest "named but no code" blocks.

**Quick wins alongside:** flip `BACKUP_RETENTION_DRY_RUN=false` in prod; reorder report sections to Security→Performance→Traffic (§12.2); wire the already-complete `UpdateSmokeCheckService` into the update pipeline (§7.4); add EOL-PHP classification (§5.3). Each is low-effort relative to value.

**Spec-contradiction to flag for a decision:** dark mode is fully shipped though §13bis says "no dark mode in v1", and §1's role/client-portal machinery survives the single-user simplification — both are *more* than spec, so they need an explicit keep-or-remove call rather than a build.

---

## Appendix — all 129 per-requirement verdicts

- **[§1] IMPLEMENTED** — Manages under 60 WordPress sites; app sized for a small fleet
  - evidence: app/Services/DashboardService.php:316 (getSitesOverview default perPage=12); no code path assumes >60 sites
  - gap: Scope is a stated boundary, not an enforced gate (nothing caps site count), which is acceptable — nothing to build here. Sizing detail lives in §14.2.
- **[§1] IMPLEMENTED** — Regula fundamentală: Manager operates and measures, never analyzes (no SEO/CRO audit module)
  - evidence: routes/web.php has no SEO/CRO/audit routes; modules are operational (uptime, updates, backups, security checks). SEO/audit deleted in phase 1.
  - gap: None — no analysis/audit surface present in the codebase.
- **[§1] IMPLEMENTED** — Out-of-scope domains live elsewhere: billing/contracts, sales pipeline, WAF/malware scanning, server/staging provisioning
  - evidence: No billing, invoicing, pipeline, WAF, or provisioning modules found under app/Livewire or routes/web.php.
  - gap: None — these domains are absent as required.
- **[§1] PARTIAL** — Single user (Andrei); no roles, no teams, no invitations, no client portal
  - evidence: Roles machinery remains: app/Models/User.php:88 casts role to App\Enums\UserRole (Admin/Manager/Viewer, isAdmin/isManager/isViewer at 152-164); tenant scoping via app/Livewire/Traits/WithVisibleSites; client-portal report view at resources/views/client-portal/report.blade.php.
  - gap: Spec calls for a single-user app with no roles and no client portal, but a three-role system, per-user site visibility scoping, and a client-portal report view still exist — the §1 simplification was not applied.
- **[§10] MISSING** — Un singur ecran, nu un wizard (single screen, NOT a wizard)
  - evidence: app/Livewire/Sites/CreateSiteWizard.php:17 public int $step=1; goToStep/nextStep/previousStep (:115-137); resources/views/livewire/sites/create-site-wizard.blade.php renders 4 discrete steps — Step 1 URL (:29), Step 2 Client (:72), Step 3 Plan (:137), Step 4 Confirm (:185) — with a numbered stepper and Next/Back buttons
  - gap: The connection flow is explicitly a multi-step WIZARD (class literally named CreateSiteWizard, 4 gated steps), the exact pattern §10 says to avoid ('Un singur ecran, nu un wizard'). No single-screen connect experience exists.
- **[§10] PARTIAL** — 6-step flow: paste URL → connector installs → auto-detect (WP/PHP/plugins/Woo/form plugin/GSC) → app proposes complete profile → confirm/adjust → on save: first backup + reference scan
  - evidence: Step1 paste URL ✓ (CreateSiteWizard.php:51 updatedFormUrl auto-fills name); checkConnectivity (:72-108) is a curl NOBODY/HEAD reachability+SSL check only; createSite (:159-190) creates Site(status 'pending') then Site::created hook dispatches ApplyPlanToSite; ModuleConfigService seeds next_backup_at=now()->addDay()->setTime(3,0) (:315) and next_scan_at=now()->addMinutes(rand(60,1440)) (:332)
  - gap: Only step 1 (paste URL) is met. Step 2: no connector INSTALL from the flow — only an HTTP reachability probe. Step 3: NO auto-detection of WP version/PHP/plugins/WooCommerce/form plugin/Search-Console at connect time (that data is filled later by background sync jobs). Step 4: app does NOT propose a complete profile (key URLs / risk list / backup schedule) — the wizard only lets you pick an existing plan + client. Step 6: NO immediate first backup or reference scan on save — backup is scheduled for the NEXT day 03:00 and the security scan is randomized 60–1440 min out; nothing is dispatched immediately. The spec's auto-detect-and-propose onboarding is essentially unbuilt; the current screen is a manual create-with-plan wizard.
- **[§11] PARTIAL** — Wordfence Intelligence as the vulnerability data source (JSON + webhook), global/free/no-auth
  - evidence: app/Services/VulnerabilityCheckService.php:172 (GET https://www.wordfence.com/api/intelligence/v2/vulnerabilities/software/plugin/{slug}), cached 24h at :182; dispatched by app/Jobs/CheckPluginVulnerabilities.php
  - gap: Pull/JSON polling of the Wordfence Intelligence v2 feed is fully wired (per-plugin fetch, 24h cache, feed-error handling that refuses to cache failures). The spec table says 'JSON + webhook' — there is no webhook ingestion endpoint; updates arrive only on the next scheduled fleet scan, not pushed.
- **[§11] IMPLEMENTED** — Wordfence, NOT WPScan (no WPScan client, feed must be cacheable)
  - evidence: app/Services/VulnerabilityCheckService.php:166-184 uses wordfence.com intelligence endpoint and Cache::put(...86400); grep for wpscan finds no client code
  - gap: None. Correct source; results are cached (Wordfence permits caching, unlike WPScan).
- **[§11] IMPLEMENTED** — Google Search Console integration (clicks, impressions, avg position, indexed pages, coverage errors), single account
  - evidence: app/Services/GoogleSearchConsoleService.php:9 (extends GoogleApiService), getOverview() posts to webmasters/v3 searchAnalytics/query; single global account resolved via GoogleConnection::where('is_active',true)->first() (app/Livewire/Sites/Detail/SiteSearchConsole.php:243); FetchSearchConsoleData job + SearchConsoleConnection/SearchConsoleCache models
  - gap: None material. Single-account model matches 'un singur cont'.
- **[§11] IMPLEMENTED** — Google Analytics 4 integration (sessions, users, conversions, sources, top pages), single account
  - evidence: app/Services/GoogleAnalyticsService.php:7 (analyticsdata.googleapis.com/v1beta), listProperties via analyticsadmin; global account via GoogleConnection::where('is_active',true)->first() (app/Livewire/Sites/Detail/SiteAnalytics.php:254); FetchAnalyticsData job, AnalyticsConnection/AnalyticsCache models
  - gap: None material.
- **[§11] IMPLEMENTED** — Cloudflare integration (requests, cache %, saved traffic, threats blocked), single token
  - evidence: app/Services/CloudflareService.php:215-311 GraphQL fetch summing requests, cachedRequests, bytes, cachedBytes, threats; validateToken() at :22; single token via CloudflareConnection::where('is_valid',true) (app/Livewire/Sites/Detail/SiteCloudflare.php:61); SyncCloudflareZone job
  - gap: None. All four named metrics (requests, cache %, saved bandwidth, threats) are gathered.
- **[§11] IMPLEMENTED** — Telegram as the single critical-alert channel (existing SAD Hub bot)
  - evidence: app/Services/Notifications/TelegramNotificationSender.php:54 posts to api.telegram.org/bot{token}/sendMessage; wired in app/Jobs/SendNotificationJob.php:87; channel type validated in app/Livewire/Forms/ChannelFormData.php:15 (in:email,slack,discord,webhook,telegram)
  - gap: None. Telegram is one of several channel types; the operator configures bot_token/chat_id per channel.
- **[§11] MISSING** — app.simplead.ro read-only, unidirectional task API (tasks per site)
  - evidence: none — grep for app.simplead.ro / task API client across app/ and config/services.php returns no HTTP client; SiteTodoService.php aggregates only local signals (uptime, vulns, hardening, updates), not remote app.simplead.ro tasks
  - gap: No integration client reads tasks per site from app.simplead.ro. This also blocks §12.2 section 3's 'sarcini finalizate din app.simplead.ro'. Spec §12.2 note defers only the SEO/CRO audit data ('când va exista'); the task feed itself is listed as a §11 integration with no code.
- **[§11] IMPLEMENTED** — Storage destinations for backups (NAS, cloud)
  - evidence: app/Models/StorageDestination.php + storage_destinations table (schema: type, config jsonb, quota_bytes, last_test_passed); app/Livewire/Forms/StorageDestinationFormData.php:15 type in:local,s3,b2,hetzner_objectstorage,dropbox; ReplicateBackup job
  - gap: Cloud destinations are covered (S3, Backblaze B2, Hetzner Object Storage, Dropbox). 'NAS' has no dedicated type (no SFTP/NFS driver) — only reachable via a 'local' mounted path, so a true NAS destination is not a first-class option.
- **[§11] PARTIAL** — Vulnerability model carries 3-state value from the start: vulnerabil / actualizat / mitigat ('mitigat' unused until Patchstack, to avoid a migration)
  - evidence: vulnerability_alerts.status = varchar default 'active' (database/schema/pgsql-schema.sql:4383); code writes only 'active' and 'fixed' (VulnerabilityCheckService.php:97,126); VulnerabilityAlert.php has scopeActive() only, no 'mitigated' constant/scope; getStatusLabelAttribute() just ucfirst()s whatever is stored
  - gap: The migration-avoidance intent is technically met (free varchar can hold any state), but the three states are not modeled: values used are 'active'/'fixed' (not vulnerabil/actualizat/mitigat), and 'mitigat'/'mitigated' appears nowhere as an enum, constant, scope, or label. Adding Patchstack later needs code changes though not necessarily a schema migration.
- **[§11] PARTIAL** — Critical alerts re-sent every 15 minutes until acknowledged
  - evidence: Acknowledgement infra exists: SendNotificationJob.php:71-79 mints stable ack token + ack URL; escalation runs via app/Jobs/ProcessNotificationEscalations.php scheduled everyFiveMinutes (routes/console.php:290), picks unacked logs older than rule.delay_minutes and escalates
  - gap: Behaviour does not match 'retrimit din 15 în 15 minute până la confirmare'. Each unacked notification escalates ONCE to a DIFFERENT channel and is then flagged escalated=true (ProcessNotificationEscalations.php:84), so the same alert is never re-sent on a repeating 15-min cadence to the same channel until ack; cadence is a 5-min sweep with a rule-configurable delay, not a fixed 15-min repeat. No loop that keeps re-notifying until acknowledged_at is set.
- **[§12.1] IMPLEMENTED** — One report per site (not per client), automatic, white-label, PDF
  - evidence: app/Services/ReportGeneratorService.php builds per-site $viewData, white-label branding (company_logo/client_logo at :65-72, primary_color), renders cover+body+closing and produces PDF via GotenbergService::htmlToPdf() (:134-135); scheduled generation via app/Jobs/GenerateReport.php + ReportSchedule model
  - gap: None. Per-site, white-label (logos + brand color), Gotenberg PDF, auto-scheduled.
- **[§12.2] PARTIAL** — Six sections in the client-importance order: 1 Summary, 2 Availability, 3 Maintenance, 4 Security, 5 Performance, 6 Traffic
  - evidence: Render order in resources/views/reports/maintenance-report.blade.php: overview(23) → technical-stability/uptime(34) → infrastructure(48) → updates(56) → backups(64) → analytics(72) → search-console(80) → performance(88) → plugin-inventory/db-health/cloudflare/wp-users → security-checks(128) → recommendations(136)
  - gap: All six categories exist, but ordering deviates from §12.2: Traffic (analytics/search-console) and Performance are rendered BEFORE Security, and Security (security-checks) sits near the very end — spec wants Security(4) → Performance(5) → Traffic(6). Also the Maintenance grouping omits 'sarcini finalizate din app.simplead.ro' (no such integration; see §11). 'Proven/verified restore' data exists but is under backups, not a distinct maintenance line.
- **[§12.3] IMPLEMENTED** — Do not auto-send if there is an unresolved critical incident on the site
  - evidence: app/Services/Reports/ReportSendGate.php:48-62 counts open UptimeIncident (resolved_at null) and checks site->is_up===false, holds report; wired in app/Jobs/GenerateReport.php:200-222 (sets send_held_reason/send_held_at + notifies operator, does not send)
  - gap: None for the incident barrier. Fail-safe: an exception in evaluate() returns send=false (ReportSendGate.php:77-80).
- **[§12.3] MISSING** — Do not auto-send if a section has no data because an integration failed (omit rather than show zeros)
  - evidence: none — ReportSendGate.php:23-27 documents this barrier as DEFERRED: sections carry no uniform failure marker, so a failed integration cannot be distinguished from a not-configured one; evaluate() implements no such check
  - gap: This mandatory §12.3 barrier is not implemented. A report whose analytics/GSC/Cloudflare section silently returned empty (integration down) would still pass the gate and auto-send.
- **[§12.3] PARTIAL** — Do not auto-send if a value is out of a reasonable range (uptime below 99%, traffic jump of several times)
  - evidence: app/Services/Reports/ReportSendGate.php:33,65-72 holds the report when data.uptime.uptime_percentage < 99.0
  - gap: Only the uptime<99% floor is enforced. The 'traffic jump of several times' case is explicitly deferred (ReportSendGate.php:28, needs prior-period comparison); no other out-of-range value checks exist.
- **[§12.4] PARTIAL** — Maintenance section surfaces the concrete figure that justifies the invoice (e.g. 'identified 847 warnings from plugin X 2.1.3, applied 2.1.4, active errors now 0')
  - evidence: Building blocks present: app/Services/Reports/Sections/ErrorLogGatherer.php:43-65 returns warning_count, unresolved_count (=active errors), and top_errors by count; updates section renders from_version→to_version (resources/views/reports/partials/updates.blade.php:109-111); rendered in infrastructure.blade.php:96-100
  - gap: The quantitative data exists (warning counts, per-message top errors, versions applied, active/unresolved error count) but it is presented as separate metric fields, not composed into the source-attributed preventive-work narrative the spec illustrates ('X warnings from plugin Y at version A → applied version B → active errors now 0'). top_errors carries message+count but no plugin/source attribution linking a warning volume to the specific update that resolved it.
- **[§13] MISSING** — Only 10 settings surfaced in the UI out of the 55 available in the connector; the rest stay in code but disappear from the interface.
  - evidence: app/Services/SiteTweaksSettingsService.php:14-64 (VALID_SETTING_KEYS lists ~55 keys across 4 categories, all still exposed); resources/views/livewire/sites/detail/tweaks/partials/tweaks-tabs.blade.php (4 full tweak tabs Performance/Site Control/Admin UX/Content & Media rendered in UI)
  - gap: The reduction from 55 to a curated 10-setting surface was never done. The full tweak catalogue is still rendered across four tabs; there is no 10-item preset view. This is the core §13 product decision and it is not implemented.
- **[§13] PARTIAL** — 5 settings applied automatically on all sites: Oprire update-uri automate, Curatare standard head, Control Heartbeat, Limitare revizii, Limite imagini la upload.
  - evidence: app/Services/SiteTweaksSettingsService.php:15-42 — underlying keys exist (disable_all_updates, heartbeat_control, revisions_control, image_upload_control, disable_generator_tag/disable_emojis/etc.); app/Livewire/Sites/Detail/Tweaks/TweaksPerformance.php:17 RECOMMENDED_TOGGLES + enableRecommended()
  - gap: The individual settings all exist as connector features, but there is no grouped 'auto-applied on all sites' set and no automatic application. Enablement is a manual per-tab 'Enable Recommended' button, not an automatic package.
- **[§13] PARTIAL** — 2 conditional settings applied only where WooCommerce is detected: Fragmente cos (disable cart fragments) and Woo scripts on non-shop pages.
  - evidence: app/Livewire/Sites/Detail/Tweaks/TweaksPerformance.php:140,282 (wooDisableCartFragments); SiteTweaksSettingsService.php:33 (optimize_woocommerce); wordpress-plugin/.../class-performance-tweaks.php:342 (disable_cart_fragments handling)
  - gap: Both underlying settings exist and there is Woo-conditional handling in the Performance tab, but they are not modelled as a formal 'conditional, only where Woo detected' preset group tied to a package.
- **[§13] PARTIAL** — 3 on-demand tools: Inlocuire fisier media, Ascundere notificari pluginuri, CSS si subsol admin.
  - evidence: app/Services/SiteTweaksSettingsService.php:44-63 (media_replacement, hide_admin_notices, custom_admin_css, custom_admin_footer keys exist under admin_ux/content_media)
  - gap: The three tools exist as ordinary toggles inside the admin_ux/content_media tabs; they are not surfaced as a distinct 'on-demand tools' group per §13.
- **[§13] MISSING** — 'Oprire update-uri automate' flagged as Esentiala (essential — without it the site self-updates and Manager loses control).
  - evidence: app/Services/SiteTweaksSettingsService.php:35 (disable_all_updates present in site_control as a plain toggle); grep for essential/Esential in tweaks views returns none
  - gap: There is no 'essential' flag, badge, or special treatment for disable_all_updates anywhere. It is a normal toggle among the site_control settings, indistinguishable from the others.
- **[§13] MISSING** — Default package 'Standard SimpleAD' applied automatically at connection, with per-site deviations.
  - evidence: app/Livewire/Sites/CreateSiteWizard.php:30-32 (applies MaintenancePlan::getDefault()); database/seeders/MaintenancePlanSeeder.php:14-33 default plan 'Full Monitoring' has include_tweaks=false; no seeder or code references 'Standard SimpleAD'
  - gap: The default applied at connection is a monitoring-MODULE plan (uptime/backup/ssl/etc.) with include_tweaks=false, not a WordPress-presets package. No 'Standard SimpleAD' tweak package exists; the §13 default-preset-at-connection concept is unimplemented.
- **[§13bis] PARTIAL** — 19 design tokens: surfaces (page/card/hover), text (primary/secondary/muted), borders (0.5px normal/strong), semantic (ok/warn/critical/inactive), radii (8px controls/12px cards), spacing (4/8/12/16/24).
  - evidence: resources/css/app.css:28-86 (:root defines --surface-app/card/muted/elevated/sidebar tokens, --border-subtle/border/strong, --text-primary..placeholder 7 levels, plus a dark override block)
  - gap: A CSS token layer exists but does not match the specified 19-token taxonomy: it has ~13 surface/border/text tokens (more granular than the 3+3+2 spec) plus a full dark-mode duplicate set, and lacks the semantic (ok/warn/critical/inactive), radii, and spacing tokens as named tokens (spacing/radii come from stock Tailwind, semantics from stock red/yellow/green).
- **[§13bis] PARTIAL** — 12 Blade components: x-layout.fleet, x-layout.site, x-sidebar-item, x-status-icon, x-site-row, x-site-card, x-metric, x-badge, x-table, x-empty-state, x-toolbar, x-confirm-dialog.
  - evidence: Present: resources/views/components/sidebar/sidebar-item.blade.php, status-icon.blade.php, site-row.blade.php, ui/badge.blade.php, ui/table.blade.php, ui/empty-state.blade.php, toolbar.blade.php (7/12). Missing: x-layout.fleet, x-layout.site (only layouts/app.blade.php exists), x-site-card, x-metric, x-confirm-dialog
  - gap: Only 7 of the 12 named components exist; layout.fleet/layout.site, site-card, metric, confirm-dialog are absent. More importantly the intended curated 12-component set is contradicted by a sprawling ~110-file component library (55 icon components, hovercards, charts, many ui/* primitives) — the opposite of the 'own small set, not a kit' decision.
- **[§13bis] MISSING** — Icons: Tabler outline, self-hosted as an SVG sprite, no CDN.
  - evidence: resources/views/components/icons/*.blade.php (66 individual inline-SVG Blade components; activity.blade.php uses the Feather 'activity' polyline path); no sprite/symbol/<use> anywhere (grep sprite/tabler/symbol = none); package.json has no tabler dependency
  - gap: Icons are individual inline Feather-style SVG Blade components, not Tabler, and are not delivered as an SVG sprite (no <symbol>/<use> sheet). Self-hosted (good) but neither the icon set nor the sprite delivery mechanism matches the spec.
- **[§13bis] PARTIAL** — Font weights: only 400 and 500 (600/700 look heavy in a dense UI); single sans family (Inter or system stack).
  - evidence: resources/css/app.css:5-18 @font-face 'Inter Variable' font-weight:100 900; tailwind.config.js:19 sans stack Inter Variable; but font-semibold/font-bold used in 118 view files, e.g. resources/views/components/ui/page-header.blade.php:9, stat-card.blade.php:39, circuit-breaker-banner.blade.php:23
  - gap: Single Inter family is satisfied, but the 400/500-only rule is violated: font-semibold(600)/font-bold(700) appear across 118 Blade files and the variable font is loaded at the full 100-900 range.
- **[§13bis] IMPLEMENTED** — Row density 44px, not 64px.
  - evidence: resources/views/components/site-row.blade.php:67 wrapper class 'group flex min-h-[44px] items-center gap-3 px-3 ...'
  - gap: None — the site list row uses min-h-[44px] as specified.
- **[§13bis] MISSING** — No shadows and no gradients — hierarchy comes from 0.5px borders and spacing.
  - evidence: shadow- utility classes appear in 57 view files; gradient classes in 2 view files (grep over resources/views)
  - gap: Shadows are used widely (57 files) and gradients in 2; the 'no shadows/gradients' rule is not enforced. Borders exist but shadows were not eliminated.
- **[§13bis] MISSING** — No dark mode in v1 (tokens make a later switch easy).
  - evidence: tailwind.config.js:6 darkMode:'class'; resources/css/app.css:65-124 full :root dark token override block + '.dark .bg-white{...}' mappings; dark: variants throughout components (e.g. ui/badge, site-row.blade.php:67 'dark:hover:bg-white/5'); layouts/app.blade.php includes a moon/sun theme toggle
  - gap: Dark mode is fully implemented and shipped (class strategy, complete dark token set, dark: variants everywhere, a UI theme toggle). This directly contradicts the 'no dark mode in v1' rule — the opposite of deferral.
- **[§13bis] IMPLEMENTED** — Own tokens + Blade components, NOT a component kit (no Flowbite/Preline/daisyUI/Filament).
  - evidence: package.json — no flowbite/preline/daisyui/filament/tabler entries; only @fontsource-variable/inter and @fontsource/geist-sans; components are hand-written Blade under resources/views/components
  - gap: None — no external component kit is used; the UI is built from own Tailwind + Blade components as the spec's core decision requires (even though the component set is larger than the intended 12).
- **[§15] IMPLEMENTED** — 60-day observation: the four modules (Tweaks 4 tabs, DatabaseCleanup, PluginConflict, Redirects) are instrumented with an access log for a data-driven keep/drop decision.
  - evidence: app/Services/ModuleUsageTracker.php (record() + 60s debounce, silent-fail); app/Models/ModuleAccessLog.php; database/migrations/2026_07_29_000030_create_module_access_logs_table.php; call sites: TweaksPerformance.php:82, TweaksSiteControl.php:38, TweaksAdminUx.php:78, TweaksContentMedia.php:58 (all 4 tabs), SiteDatabaseCleanup.php:82, SiteRedirects.php:31, and SyncWordPressSite.php:270 (plugin_conflict)
  - gap: All four modules are instrumented and logged to module_access_logs with a documented 60-day GROUP BY decision query. Minor nuance: the four Tweaks tabs, DatabaseCleanup and Redirects log on UI page access, whereas plugin_conflict is logged on conflict DETECTION during sync (no user-facing plugin-conflict page exists), so its signal is detection volume rather than UI access — a defensible but slightly different metric than the others.
- **[§2] PARTIAL** — Principle 1 — Exception-based: default screen shows only what is broken, not all green sites
  - evidence: app/Livewire/Dashboard/GlobalDashboard.php:28 default `filter = 'all'`; DashboardService.php:356-360 supports healthy/warning/critical filters; alerts/issues computed at DashboardService.php:264 (getAlerts) and 518 (getIssues).
  - gap: Exception data exists (alerts + issues list), but the default fleet screen shows ALL sites (`filter='all'`), not an exception-only view; the exception-first default the principle requires is not the landing state.
- **[§2] PARTIAL** — Principle 2 — Bulk-first: any per-site action must be possible on ~30 sites in one click
  - evidence: Bulk framework present in app/Livewire/Traits/WithBulkSiteActions.php (bulkBackup:66, bulkCheckUptime:83, bulkSync:49, bulkSetStatus, bulkMoveToClient, bulkDelete). Toolbar at resources/views/components/toolbar.blade.php:45-71 references bulkUpdate, bulkApplyPlan, bulkApplyPresets, bulkPurgeCache.
  - gap: Four toolbar bulk actions (Actualizează/bulkUpdate, Aplică plan/bulkApplyPlan, Aplică presetări/bulkApplyPresets, Golește cache/bulkPurgeCache) are wired to methods that do not exist in the trait (marked TODO) — clicking them would error. Bulk update in particular (the headline operation) is not implemented at fleet level.
- **[§2] IMPLEMENTED** — Principle 3 — Zero manual data upkeep: inventory populates itself from the connector
  - evidence: routes/console.php:49-52 scheduled 'data-sync-dispatcher' (analytics, search console, cloudflare, WP sync); WithBulkSiteActions.php:49 bulkSync; connector-driven sync jobs present.
  - gap: None material — automated sync pipelines exist and are scheduled.
- **[§2] PARTIAL** — Principle 4 — Automation in execution not decision: approval given per batch, once, not per site
  - evidence: app/Services/UpdateDecisionService.php:23-70 (hold-for-approval logic, flag-gated OFF); app/Livewire/Updates/UpdatesOverview.php:194-273 holds items awaiting approval.
  - gap: Approval is expressed per-item ('Update for {name} needs approval') and the smart-rules engine is flag-gated OFF; a single batch/lot-level approval action (approve-once-for-the-lot) is not clearly present.
- **[§2] NA** — Principle 5 — Every feature yields a report figure or an action
  - evidence: none
  - gap: Meta-principle governing feature inclusion; not a discretely verifiable code artifact. Not assessed against a single code location.
- **[§3.1] MISSING** — Fleet sidebar restructured into three groups VEDERE / OPERAȚIUNI / EVIDENȚĂ with a minimal item set
  - evidence: resources/views/components/sidebar/global-sidebar.blade.php uses old English groups Monitoring (Uptime/Performance/Security), Operations (Updates/Backups/Reports/Maintenance Plans), Insights (Activity/Error Logs/DNS/Clients); layout wires it at resources/views/components/layouts/app.blade.php:130.
  - gap: The fleet-nav restructure was NOT applied. Section labels are the pre-refactor English ones, and the fleet sidebar still carries site-scoped modules (Uptime, Performance, Security, Backups, DNS, Maintenance Plans, Error Logs) that the spec moves into site context. Only the site sidebar (§3.2) was restructured.
- **[§3.1] PARTIAL** — Search box at top of fleet sidebar: 'Caută site, client, plugin…'
  - evidence: Global search exists but in the header, not the sidebar: resources/views/components/header/page-header.blade.php (livewire:components.global-search, desktop block); scope covers Site, Client, SitePlugin per app/Livewire/Components/GlobalSearch.php:9-53.
  - gap: Search covers the right entities (site/client/plugin) but lives in the top header, not at the top of the fleet sidebar as the §3.1 layout specifies.
- **[§3.1] MISSING** — Fleet nav item 'Alerte' with a counter under VEDERE
  - evidence: No alerts route (grep confirms 'NO alerts route' in routes/web.php); alerts surface only via header dropdown app/Livewire/Components/NotificationDropdown.php (getAlerts). No Alerte item in global-sidebar.blade.php.
  - gap: There is no dedicated 'Alerte' fleet nav item/screen with a counter; alerts are only a header notification dropdown.
- **[§3.1] MISSING** — Fleet nav item 'Site-uri [58]' with count under OPERAȚIUNI
  - evidence: grep confirms 'NO sites nav item in global sidebar'; routes/web.php:93 redirects /sites → dashboard. The dashboard doubles as the site list.
  - gap: The fleet sidebar has no 'Site-uri' nav entry or [58] count; the site list is reached only via the Dashboard/logo, not a dedicated sidebar item.
- **[§3.1] IMPLEMENTED** — Fleet nav item 'Actualizări' with counter under OPERAȚIUNI
  - evidence: resources/views/components/sidebar/global-sidebar.blade.php:41-49 Updates item with :count=$updatesCount to route('updates.index').
  - gap: Present and counted, but grouped under the old 'Operations' label rather than 'OPERAȚIUNI'.
- **[§3.1] IMPLEMENTED** — Fleet nav items 'Rapoarte' and 'Activitate' under EVIDENȚĂ
  - evidence: global-sidebar.blade.php:59-65 Reports (route reports.index), :77-83 Activity (route activity.index).
  - gap: Both destinations exist but are split across the old 'Operations' and 'Insights' groups, not a single 'EVIDENȚĂ' group as specified.
- **[§3.2] IMPLEMENTED** — Site sidebar replaces the global sidebar entirely, with sections Prezentare / MENTENANȚĂ / SUPRAVEGHERE / DATE / Setări site and the specified expandable groups
  - evidence: resources/views/components/sidebar/site-sidebar.blade.php: Prezentare:136, Mentenanță:146, Supraveghere:194, Date:304, Setări site:360; layout swaps to it when $siteContext set (app.blade.php:127-131). Groups Pluginuri și teme, Backupuri, Uptime, Securitate, Performanță, Verificări, Trafic, Rapoarte all present.
  - gap: Structure and group headers match the spec closely; global sidebar is fully replaced in site context.
- **[§3.2] PARTIAL** — Full sub-section list per group (e.g. Teme, Licențe premium, Actualizări group, Restore verificat, Programare, Incidente, SSL, Vulnerabilități, Integritate, Core Web Vitals, Formulare, WooCommerce, Linkuri rupte, Erori PHP, Sarcini)
  - evidence: site-sidebar.blade.php marks many spec sub-items as non-existent routes via TODO comments: Teme/Licențe premium (165-166), Actualizări group (169-171), Restore verificat/Programare (188-189), Incidente/SSL (209-210), Vulnerabilități/Integritate (238-239), Core Web Vitals (270), Formulare/WooCommerce/Linkuri rupte/Erori PHP (281-284), Sarcini (338).
  - gap: Group scaffolding is correct but ~15 of the spec's leaf sub-sections have no route yet and are only placeholder comments — notably the entire 'Actualizări' group (Disponibile/Reguli automate/Ignorate/Istoric), Restore verificat, Incidente, SSL și domeniu, Vulnerabilități, Integritate, and the Formulare/WooCommerce/Linkuri rupte/Erori PHP checks.
- **[§3.3] IMPLEMENTED** — Settings not repeated under each module; exception is 'Reguli automate' under Actualizări
  - evidence: site-sidebar.blade.php:359-367 has a single 'Setări site' at the bottom; no per-module settings entries.
  - gap: Rule satisfied. The named exception ('Reguli automate' under Actualizări) cannot be present because the Actualizări group itself has no route yet (TODO at 169-171).
- **[§3.3] PARTIAL** — Quick buttons (wp-admin, backup, verifică) stay visible across ALL site sections
  - evidence: wp-admin button component exists (resources/views/components/ui/wp-admin-button.blade.php) and is rendered on specific pages only: site-overview.blade.php, site-plugins.blade.php, site-redirects.blade.php, site-row.blade.php. Not present in the shared layout/header (page-header.blade.php ends at notifications, no site quick-action bar).
  - gap: No persistent quick-action bar across all site sections; wp-admin appears only on a few pages, and a per-site 'backup'/'verifică' quick trigger is not rendered on every site section (e.g. uptime, performance, security, backups pages).
- **[§3.3] IMPLEMENTED** — Site name acts as a switcher: change site without leaving the current section
  - evidence: resources/views/components/sidebar/site-sidebar.blade.php:75-132 dropdown switcher listing $switcherSites with search when >6, fed by SiteSwitcherComposer.
  - gap: Switcher navigates to sites.overview of the target site rather than preserving the exact current sub-section, so 'without leaving the section' is only partially honored — but the switcher itself is fully present.
- **[§3.3] IMPLEMENTED** — Sidebar has a collapse button
  - evidence: resources/views/components/layouts/app.blade.php:118-121 toggleSidebar() button; state persisted in localStorage (73-74), width collapses lg:w-64 → lg:w-16 (101).
  - gap: None.
- **[§4] PARTIAL** — Main site-list screen modeled on WPMU DEV Hub with two switchable views (list + grid)
  - evidence: app/Livewire/Sites/SitesList.php:34-59 (viewMode grid/list toggle); resources/views/livewire/sites/sites-list.blade.php:36-77 (list+grid switch); routes/web.php:89-93
  - gap: The list/grid-toggle screen (SitesList) is orphaned — no route renders it and /sites redirects to route('dashboard'). The actually-routed main screen is Dashboard\GlobalDashboard, which has a single list view with NO grid toggle. So the spec's two-view WPMU-Hub model exists only in unreachable code.
- **[§4.1] PARTIAL** — Row structure: [avatar][name+client][nr. update-uri] │ [grup1] │ [grup2] │ [grup3] │ [uptime][⋮]
  - evidence: resources/views/components/site-row.blade.php:66-162 (favicon, name+client, updates badge, 3 bordered groups, uptime bar, ⋮ menu — exact spec order)
  - gap: x-site-row matches §4.1 exactly, but it is only rendered by the orphaned SitesList (sites-list.blade.php:64) which no route serves. The live row (x-dashboard.site-row, used by GlobalDashboard) has a different layout: identity, ~10 flat status icons in non-spec groupings, health bar — not the [avatar][name+client][updates]|g1|g2|g3|[uptime][⋮] structure.
- **[§4.2] PARTIAL** — Exactly three icon groups — Disponibilitate (uptime, SSL, backup), Sănătate (securitate, performanță, erori PHP), Funcționare (formulare, WooCommerce)
  - evidence: resources/views/components/site-row.blade.php:92-113 (group1 uptime/SSL/backup; group2 securitate/perf/PHP; group3 formulare/WooCommerce, each border-separated)
  - gap: The three-group layout exists only in orphaned x-site-row; erori PHP (line 52), formulare and WooCommerce (lines 56-57) are hardcoded 'na' (no model signal). The live x-dashboard.site-row shows different icons (uptime, response-time, analytics, search-console, plugins, users, wp-conn, backup, wp-version, reports) grouped differently — it does NOT implement the spec's 3 semantic groups.
- **[§4.2] NA** — Reserved 4th group for later (linkuri rupte, licențe)
  - evidence: resources/views/components/site-row.blade.php (no 4th group present)
  - gap: Spec explicitly marks it 'rezervat pentru mai târziu'; absence is expected. Not built anywhere.
- **[§4.3] IMPLEMENTED** — Four color codes — verde=ok, galben=atenție, roșu=critic, gri=nu se aplică; final bar gri if clean month, roșu if downtime
  - evidence: resources/views/components/status-icon.blade.php:8-15 (ok→green-500, warning→amber-500, critical→red-500, na→gray-400); site-row.blade.php:60-61,119 (bar bg-red-500 if downtime else bg-gray-300)
  - gap: Color system is correct in the reusable x-status-icon component and the x-site-row uptime bar. Caveat: this is wired only into the orphaned SitesList row; the live GlobalDashboard row uses a health-score bar (green/yellow/red by score, resources/views/livewire/dashboard/global-dashboard.blade.php via SiteStatusHelper) rather than the spec's gri/roșu downtime bar.
- **[§4.4] MISSING** — Screen tabs: Toate · Actualizări [contor] · Alerte [contor] · Planuri
  - evidence: resources/views/livewire/sites/sites-list.blade.php:15-19 (tabs are all/healthy/warning/critical); global-dashboard.blade.php:349-372 (health filter pills)
  - gap: Neither screen has the spec tabs. SitesList shows health-state filter-tabs (Toate/Healthy/Warning/Critical); GlobalDashboard has health/client/status filter dropdowns. No 'Actualizări [contor]', 'Alerte [contor]', or 'Planuri' tab exists.
- **[§4.4] MISSING** — Grouping control: client / etichetă / plan
  - evidence: none
  - gap: No grouping (group-by) control on either screen. GlobalDashboard offers a client FILTER dropdown (global-dashboard.blade.php:324-347) and sort, but no group-by-client/tag/plan that visually clusters rows.
- **[§4.4] PARTIAL** — Filters: client, etichetă, plan, stare, prezență WooCommerce, versiune WP, versiune PHP
  - evidence: sites-list.blade.php:20-33 (tag/etichetă + search); global-dashboard.blade.php:324-412 (client filter, health/stare filter, SiteStatus filter)
  - gap: Only client, etichetă (tag), and stare (health/status) filters exist, split across the two screens. Missing: plan filter, WooCommerce-presence filter, WP-version filter, PHP-version filter (4 of 7 spec filters absent).
- **[§4.4] PARTIAL** — Multi-select action bar with verbs: actualizează · backup · aplică plan · aplică presetări · golește cache · verifică acum
  - evidence: resources/views/components/toolbar.blade.php:44-77 (all 6 buttons present); app/Livewire/Sites/SitesList.php:135-186 (bulkUpdate/bulkApplyPlan/bulkApplyPresets are info-toast stubs; bulkPurgeCache real; bulkBackup/bulkCheckUptime from trait)
  - gap: The spec-verb toolbar exists but only on the orphaned SitesList, and 3 of 6 verbs (actualizează, aplică plan, aplică presetări) are stubs that emit 'nu este disponibilă încă' toasts (SitesList.php:135-155). The live GlobalDashboard bulk bar (global-dashboard.blade.php:201-284) has different verbs — Set Status, Move to Client, Sync, Backup, Check Uptime, Delete — missing actualizează/aplică plan/aplică presetări/golește cache.
- **[§4.4] PARTIAL** — Bottom actions: „Conectează site existent" and „Conectare în masă"
  - evidence: sites-list.blade.php:5-10 and global-dashboard.blade.php:8-13 (single 'Add Site' → route('sites.create'))
  - gap: A single-site connect ('Add Site') exists but is placed at the top, not bottom. 'Conectare în masă' (bulk connect) does not exist anywhere in resources/ or app/ (grep found no match).
- **[§4.4] MISSING** — Grid view: auto-generated homepage thumbnail (miniatură)
  - evidence: resources/views/livewire/components/site-card.blade.php:7 (uses x-site-favicon, not a homepage screenshot)
  - gap: The grid card renders a favicon, not an auto-generated homepage thumbnail. No screenshot/thumbnail generation for cards. (And the grid view itself is only in the orphaned SitesList.)
- **[§4.5] MISSING** — Panou: exactly three bands (1: cine necesită atenție, grupat pe client, ordonat după gravitate, desfășurabil; 2: ce așteaptă aprobarea; 3: restul într-o linie) — Fără grafice, fără widget-uri decorative
  - evidence: routes/web.php:89 (dashboard → GlobalDashboard); resources/views/livewire/dashboard/global-dashboard.blade.php:63-193 (5 mini stat cards) + 195-483 (sites list)
  - gap: The routed Panou (GlobalDashboard) is 5 decorative mini stat cards (Sites, Uptime, Backup Storage, Backups Today, Alerts) followed by a flat sites list. It does NOT implement the three prescribed bands (attention-needed grouped-by-client/severity/expandable, awaiting-approval, rest-in-one-line), and the stat-card row directly violates the spec's 'Fără widget-uri decorative' rule.
- **[§5.1] PARTIAL** — Uptime cadence: 1 min on critical sites, 5 min on the rest
  - evidence: app/Livewire/Forms/MonitorFormData.php:19,48 (interval_minutes default 5, rule min:1|max:1440); app/Services/ModuleConfigService.php:103 (DEFAULT_INTERVALS uptime=>5); app/Jobs/CheckUptime.php:377 (next_check_at = now()->addMinutes(interval_minutes))
  - gap: Interval is a free per-plan/per-monitor knob (1..1440 min); the 1-min cadence is achievable but there is NO built-in 'critical site' tier that automatically drops those monitors to 1 min while keeping the rest at 5. Classification is left entirely to manual configuration.
- **[§5.1] PARTIAL** — Anti-false-positive: two consecutive failures before opening an incident, ideally from two locations
  - evidence: app/Jobs/CheckUptime.php:362-368 (consecutive_failures vs alert_after_failures, default 3 per MonitorFormData.php:38), 440-451 (SiteWentDown dispatched only when consecutive_failures === alert_after_failures), saveCheck sets 'location'=>'primary' hard-coded (line ~318)
  - gap: Threshold gating exists (default 3, ≥2 satisfied) but two nuances miss the spec: (a) the incident ROW is created on the FIRST failure (handleFailure: incidents()->create) — only the notification is threshold-gated; (b) 'two locations' is not implemented at all — every check records location='primary', so a single-VPS network blip still hits the whole fleet, which is exactly the scenario the spec warns about.
- **[§5.1] IMPLEMENTED** — SSL: validity, expiry, issuer
  - evidence: app/Jobs/CheckSsl.php:50-90 (opens TLS on 443, parses validTo, stores ssl_expires_at/ssl_issuer); dispatched on slow cadence via MonitoringDispatcher.php:56-66 (dispatchSslChecks, next_ssl_check_at gate)
  - gap: None material.
- **[§5.1] IMPLEMENTED** — Response-time time series
  - evidence: app/Jobs/CheckUptime.php:305-317 (saveCheck writes response_time per check); updateUptimeStats computes avg_response_time
  - gap: None.
- **[§5.1] IMPLEMENTED** — Domain-expiry early alert
  - evidence: app/Services/DomainExpiryService.php:24 (EXPIRING_SOON_DAYS=30), RDAP lookup with PSL-free label walk; app/Jobs/CheckDomainExpiry.php
  - gap: None; 30-day threshold.
- **[§5.1] IMPLEMENTED** — DNS-change detection (unexpected changes)
  - evidence: app/Jobs/CheckDns.php:71-118 (baseline on first check, detectChanges vs current_records, two-consecutive-identical confirmation before flagging); dispatched via MonitoringDispatcher dispatchDnsChecks
  - gap: None.
- **[§5.2] IMPLEMENTED** — Vulnerabilities in plugins/themes/core (source: Wordfence Intelligence)
  - evidence: app/Services/VulnerabilityCheckService.php:172 (GET wordfence.com/api/intelligence/v2/vulnerabilities/software/plugin/{slug}), 24h cache, per-plugin feed-error handling; scheduled daily via routes/console.php php-error/vuln block
  - gap: None on source/wiring.
- **[§5.2] PARTIAL** — New administrator users
  - evidence: wordpress-plugin/.../class-audit-logger.php:181-186 (user_register hook logs 'user_created', object=user_login only); app/Jobs/PullSecurityActivityLogs.php ingests /audit-logs; SecurityActivityService::categorizeEvent maps user_* to 'user'
  - gap: User creation is captured generically but the audit event does NOT record the role, and there is NO admin-role-specific detection or alert (no delta 'a new administrator appeared' signal). New-admin surfacing that the spec calls out as a distinct security signal is not implemented — it is just one undifferentiated 'user_created' row.
- **[§5.2] PARTIAL** — Spam users
  - evidence: app/Services/SpamUserDetectionService.php:25-124 (heuristic scoring: gibberish username, suspicious email, bulk-registration windows, gmail-dot variants); invoked only from app/Livewire/Sites/Detail/Security/SecurityUsers.php:257
  - gap: Detection logic is solid but runs ONLY on demand from the Security > Users UI. There is no scheduled/automated spam-user scan or alert across the fleet, so a site nobody opens is never checked.
- **[§5.2] IMPLEMENTED** — Blocked login attempts / attacks
  - evidence: app/Services/SecurityActivityService.php:194-223 (getFailedLoginStats: totals, unique IPs/usernames, top IPs from event_type='failed_login'); logs pulled via PullSecurityActivityLogs, dispatched from SyncWordPressSite.php:292
  - gap: None material; depends on connector emitting failed_login rows.
- **[§5.2] PARTIAL** — Theme file integrity
  - evidence: app/Jobs/CheckThemeIntegrity.php + app/Services/ThemeIntegrityService.php exist; core integrity IS dispatched in MonitoringDispatcher.php:133 (CheckCoreFileIntegrity)
  - gap: CheckThemeIntegrity is orphaned — grep finds no dispatch/schedule/UI trigger anywhere. Core-file integrity rides the security cadence, but theme-file integrity code is never invoked in production.
- **[§5.3] IMPLEMENTED** — EOL WordPress
  - evidence: app/Services/WordPressEolService.php:19 (EOL_BEFORE='6.0'), classify()/check() with severity + notify; getLatestVersion from api.wordpress.org
  - gap: Fixed EOL cutoff (<6.0) rather than a maintained EOL calendar, but functional.
- **[§5.3] MISSING** — EOL PHP
  - evidence: php_version synced in app/Jobs/SyncWordPressSite.php:62; no EOL classifier found (grep for php EOL logic returns only backup/manifest php_version usages, WordPressEolService is WP-only)
  - gap: PHP version is stored but never classified against PHP's EOL calendar (e.g. 8.1 EOL) and no alert is raised. The spec lists 'EOL WordPress și PHP' — the PHP half is absent.
- **[§5.3] IMPLEMENTED** — Abandoned plugins
  - evidence: app/Services/PluginAbandonmentService.php:60-75 (wp.org last_updated; is_abandoned when older than config plugin_abandonment_years, default 2y)
  - gap: None.
- **[§5.3] IMPLEMENTED** — Plugin risk rating
  - evidence: app/Services/PluginRiskAssessmentService.php:19-101 (AI/Claude assessment returning score 0-100 + level safe/caution/risky + reasons + recommendation, from changelog/popularity/compat)
  - gap: None; note it is update-risk oriented and depends on the LLM call.
- **[§5.3] MISSING** — Aggregated PHP errors with attribution to the responsible plugin or theme
  - evidence: app/Models/PhpErrorLog.php:29-31 (columns: level, message, file, line, message_hash — no plugin/theme field); app/Jobs/FetchPhpErrorLogs.php:71 (dedup hash = md5(level+message)); no wp-content/plugins|themes path parsing in Manager or connector error-logs endpoint
  - gap: Errors are aggregated (file/line captured, deduped, scheduled every 6h) but there is NO attribution step: file paths are never mapped to a plugin/theme slug against the inventory as §5.3/§5.6 require. Blame-by-path is absent.
- **[§5.3] PARTIAL** — Cron functional
  - evidence: connector class-cron-endpoint.php (cron-list/run/enable/disable) + app/Livewire/Sites/Detail/SiteCron.php:67 (getCronList on demand); Woo scheduled-sales cron checked in app/Services/WooHealthService.php:64
  - gap: Only an on-demand cron LISTING UI plus a Woo-specific scheduled-sales cron flag. No automated 'cron health' monitoring/alert (overdue/stuck WP-cron, or DISABLE_WP_CRON without an external trigger) across the fleet.
- **[§5.3] IMPLEMENTED** — Database health and size
  - evidence: app/Services/DatabaseHealthService.php:17-70 (table sizes, autoload size, overhead, MyISAM, top-10 tables, warning thresholds via config monitoring.db_*); db_size_mb synced in SyncWordPressSite.php:65
  - gap: None.
- **[§5.3] PARTIAL** — Disk space
  - evidence: connector class-monitoring-endpoint.php:25-27,88-104 (disk_total/used/free via disk_*_space(ABSPATH)); Manager-side disk_free_space calls are all for the MANAGER host (HealthCheckController.php:75, AppBackupCreator.php:43, Backup/DiskSpaceGuard.php)
  - gap: The connector exposes the WP site's disk figures, but no Manager code consumes /monitoring disk_free/disk_used — grep finds zero references. Monitored-site disk space is therefore not surfaced, alerted, or fed into the health score.
- **[§5.3] IMPLEMENTED** — Health score
  - evidence: app/Services/HealthScoreService.php:29-84 (four 25-pt components: uptime, security, updates, performance; refresh() off uptime/scan pipelines + nightly RecordHealthScores)
  - gap: Score omits disk/DB/cron dimensions, but a composite health score exists and is wired to dashboard sort/filter.
- **[§5.3] IMPLEMENTED** — Premium license expiry alerts
  - evidence: app/Jobs/CheckLicenseExpiry.php:17-46 (daily sweep of licensed plugins, alert on expired or within 30 days, per-plugin dedup via license_alert_sent_at)
  - gap: None.
- **[§5.4] PARTIAL** — Contact forms: real weekly submission with marker, integrations suppressed during test, plus email-delivery verification
  - evidence: app/Services/ContactFormChecker.php:12 ('owner-gated, on-demand'), 143-169 (runGatedTest -> connector POST /form-test/run); connector class-form-test-endpoint.php:18-20,145-156 (active-flag transient, per-plugin suppression filters BEFORE submit, +samtest marker, entry deleted, flag cleared in finally)
  - gap: Two spec misses: (1) NOT weekly/automated — no scheduler dispatches it (grep of Dispatchers/console for ContactFormChecker/form-test = none); it only runs when an owner clicks. (2) Email DELIVERY is not verified — the connector's generic safety net (pre_wp_mail short-circuit, endpoint line ~191) actively ABORTS delivery of the marked email, the opposite of the spec's 'verificare de livrare a emailului'. Third-party-integration suppression (the precaution) IS correctly implemented via a duration flag, not just post-hoc entry deletion.
- **[§5.4] IMPLEMENTED** — WooCommerce (conditional): checkout 200, payment gateway responds, orders stuck in pending, discount cron
  - evidence: app/Services/WooHealthService.php:40-104 (checkout_status probe, gatewaysHealthy, pending_orders_count/over_threshold, scheduled_sales_cron_present); app/Jobs/CheckWooHealth.php scheduled daily 05:30 (routes/console.php:316), Woo-gated
  - gap: None; all four sub-checks present.
- **[§5.5] PARTIAL** — PageSpeed + Core Web Vitals, monthly (not continuous), desktop and mobile
  - evidence: app/Jobs/RunPerformanceTest.php:85 (device 'both' => mobile+desktop), 255-256 (CWV: lcp, cls, etc.); scheduled via MonitoringDispatcher.php:82-99 (only frequency in ['daily','weekly'] auto-run); default interval app/Services/ModuleConfigService.php:105 performance=>10080 (7 days), frequency set to 'daily' at line ~324
  - gap: Desktop+mobile+CWV are correct, but cadence contradicts 'lunar, nu continuu': the default is a 7-day (weekly) cadence and the auto-runner only recognizes 'daily'/'weekly' frequencies — there is no 'monthly' option. Monthly is only reachable by hand-setting interval_minutes≈43200, and the shipped default runs 4× more often than the spec wants.
- **[§5.6] MISSING** — Connector must use its OWN handler (set_error_handler + register_shutdown_function), NOT read from debug.log
  - evidence: wordpress-plugin/.../endpoints/class-error-logs-endpoint.php:25-33 (reads ini_get('error_log') file AND WP_CONTENT_DIR/debug.log, parse_log_file with regex); grep for set_error_handler/register_shutdown_function across the entire connector returns ZERO hits
  - gap: The implementation does exactly what the spec forbids: it tails/parses debug.log and the PHP error_log file. No custom error handler or shutdown function exists. The entire mandated architecture of §5.6 is absent.
- **[§5.6] MISSING** — Local dedup by signature: file + line + message template with variable values stripped
  - evidence: connector class-error-logs-endpoint.php:45 (hash = md5(level . message)); Manager side app/Jobs/FetchPhpErrorLogs.php:71 (hash = md5(level+message))
  - gap: Dedup key is md5(level + full message) on BOTH sides — the raw message including variable values, not a normalized (file+line+templated) signature. Two errors that differ only by a variable value (e.g. an ID in the text) are treated as distinct, defeating the required signature dedup.
- **[§5.6] NA** — Send in batch, not per error
  - evidence: Pull model: routes/console.php:221-228 (Manager pulls /error-logs every 6h); no connector-side per-error send path exists
  - gap: Because there is no own error handler emitting events, 'batch vs per-error' is moot — the connector never sends errors, it exposes a parsed-file snapshot the Manager polls. Not implemented in the sense the spec means (the batching requirement presupposes the handler architecture that is missing).
- **[§5.6] MISSING** — try/catch everywhere with silent failure (in the error handler)
  - evidence: No handler exists (see §5.6 own-handler finding)
  - gap: N/A because the handler it guards does not exist; the requirement cannot be satisfied without the mandated handler.
- **[§5.6] MISSING** — Remote kill switch from the Manager to disable error collection
  - evidence: grep for kill/disable-error across connector finds only cron-hook and directory-listing toggles; the error-logs endpoint has no enable/disable flag
  - gap: There is no remote on/off for PHP-error collection. The endpoint always parses and returns; the Manager cannot silence a noisy site's error handler remotely.
- **[§5.7] IMPLEMENTED** — Internal links from content: posts, pages, products, menus, widgets
  - evidence: connector class-content-urls-endpoint.php:73-75 (post_types post,page,product-if-exists), :94-100 (menus + text/HTML widgets on page 1), :65 (anchor href extraction); Manager app/Services/BrokenLinkChecker.php:62-64 (internal always checked)
  - gap: None; all five content sources covered.
- **[§5.7] IMPLEMENTED** — Broken images (broken src)
  - evidence: connector class-content-urls-endpoint.php:68 (extract <img src>), fed into the same HEAD-check pipeline
  - gap: Images are extracted and HEAD-checked, though not separately labeled as 'image' vs 'link' in results — minor, coverage is present.
- **[§5.7] IMPLEMENTED** — Internal redirect chains (A -> B -> C)
  - evidence: app/Services/BrokenLinkChecker.php:171-201 (checkUrl builds chain hop-by-hop with allow_redirects=false, MAX hops), :90 stores redirect_chain when count(chain)>1
  - gap: None.
- **[§5.7] IMPLEMENTED** — Connector scans DB for unique URLs; Manager verifies via HEAD request
  - evidence: connector reads wp_posts directly (class-content-urls-endpoint.php:80); app/Services/BrokenLinkChecker.php:211-224 (probe: HEAD, GET fallback on 405)
  - gap: None; matches 'verify 200 addresses, don't crawl 500 pages' model.
- **[§5.7] IMPLEMENTED** — External links optional, disabled by default
  - evidence: app/Services/BrokenLinkChecker.php:49 (check_external_links flag, FILTER_VALIDATE_BOOLEAN, default off), :62-64 (external only when explicitly enabled)
  - gap: None.
- **[§5.7] IMPLEMENTED** — Report on difference not state (new broken / resolved vs last month)
  - evidence: app/Services/BrokenLinkChecker.php:96-97 (newBroken/resolved via array_diff against previous run), 104-105 (new_broken_count/resolved_count persisted per run)
  - gap: None.
- **[§5.7] IMPLEMENTED** — Alert threshold: notify only when a link breaks on a top Search Console page
  - evidence: app/Services/BrokenLinkChecker.php:281-341 (maybeAlert fires only for newBroken URLs whose source page is in topSearchConsolePages()); non-top breakages stay in the report only
  - gap: None; depends on a GSC connection being present.
- **[§5.7] IMPLEMENTED** — Cadence: monthly, before report generation
  - evidence: routes/console.php:310-313 (CheckBrokenLinks scheduled monthlyOn(1, '02:00'))
  - gap: Runs on the 1st at 02:00; report generation runs later in the month, so ordering holds. Coupling is temporal, not an explicit 'before report' dependency.
- **[§6.1] IMPLEMENTED** — Single common engine exposing the prepare() → execute() → verify() → rollback() interface that every operation flows through
  - evidence: app/Operations/Contracts/Operation.php:19-63 (interface with all four phases + key/durationClass/isReversible/requiresApproval); lifecycle driven uniformly in app/Jobs/RunOperationJob.php:77-116
  - gap: Interface and per-site lifecycle driver match the spec exactly. Only caveat is coverage (see §6.2): just one operation is migrated onto it so far.
- **[§6.1] PARTIAL** — Engine gives 'for free': site selection, queue, concurrency limit, progress, per-site result, retry, timeout, log, approval gate, end-of-run notification
  - evidence: selection via scoped Collection (OperationRunner.php:40-62); queue+retry+timeout from OperationDuration.php:26-57 wired in RunOperationJob.php:50-53; progress+per-site result OperationBatch.php:56-127; log JobTracker::appendLog RunOperationJob.php:81-116; approval gate OperationRunner.php:46-104; concurrency via Horizon supervisor-ops-instant config/horizon.php:312-316
  - gap: 'Notificarea la final' is only a JobTracker::complete() tracker update (OperationBatch.php:139) — no push/Telegram NotificationService dispatch at batch completion. All other free features present.
- **[§6.1] IMPLEMENTED** — Separate queues per duration class (a cache purge must not wait behind a 20-min backup)
  - evidence: app/Operations/OperationDuration.php:13-29 (Instant='ops-instant', Short='uptime', Medium='default', Long='backups'); dispatched onto op's queue in OperationRunner.php:53,57; dedicated fast lane supervisor-ops-instant in config/horizon.php:312-316
  - gap: None — duration classes map to distinct Horizon queues/supervisors.
- **[§6.1] IMPLEMENTED** — Rollback is not mandatory — interface must allow 'cannot revert'
  - evidence: Operation::isReversible() app/Operations/Contracts/Operation.php:35; RunOperationJob.php:103 only invokes rollback() when isReversible(); pilot op returns false + no-op rollback PurgeCloudflareCacheOperation.php:32-34,82-86
  - gap: None.
- **[§6.1] PARTIAL** — Consolidate/reuse existing CircuitBreakerService, JobTracker, app/Dispatchers
  - evidence: JobTracker reused/mirrored by the engine (OperationBatch.php:49,139-145; RunOperationJob.php:64,81,106). CircuitBreakerService and app/Dispatchers still exist but are not referenced anywhere under app/Operations (grep: no CircuitBreaker in app/Operations).
  - gap: Only JobTracker is integrated into the engine. CircuitBreakerService and the four Dispatchers (Backup/DataSync/Monitoring/Report) remain separate and are not consolidated into or leveraged by the operations engine.
- **[§6.2] PARTIAL** — The 11 operation types all run through the common engine (safe update+rollback, backup, restore, verified restore, plugin install/activate/delete, theme mgmt, apply maintenance plan, apply WP presets, copy settings between sites, Cloudflare cache purge, instant wp-admin login)
  - evidence: Only Cloudflare purge runs through the engine (PurgeCloudflareCacheOperation.php; wired SitesList.php:162-186). The other 10 capabilities exist but on legacy paths: SafeUpdateService.php/RunSafeUpdate, CreateBackup.php, RestoreBackup.php, RunProvenRestore.php, PluginManagerService.php, MaintenancePlanService+ApplyPlanToSite.php, BulkSettingsCopyService.php, instant login SitesList.php:64-86
  - gap: §6.1 says ALL operations go through one engine, but only the pilot (cloudflare.purge) is migrated; OperationRegistry.php:18-20 registers a single op. Also 'apply WP presets' and bulk 'apply maintenance plan' are UI stubs (SitesList.php:146,152-155 — 'nu este disponibilă încă'), so that operation type is effectively missing for bulk use.
- **[§7.1] IMPLEMENTED** — Global view on a single axis — the plugin, not the site ('Elementor 3.22 on 18 sites' → batch approval → execution)
  - evidence: groupBy='item' groups updates by plugin slug (UpdatesOverview.php:135-142); fleet-wide execution across all sites with the update via updatePluginAcrossSites($slug) (UpdatesOverview.php:314-415)
  - gap: Plugin-axis grouping and cross-site batch execution are present. 'Batch approval' is per-SafeUpdate approval (approveUpdate, UpdatesOverview.php:448-478), not a single one-click lot approval for the whole plugin group — a minor detail mismatch.
- **[§7.2] IMPLEMENTED** — Smart rules exist and are the approval-expressed-once engine, flag-gated
  - evidence: UpdateDecisionService.php:39-99 routes to 3 tracks via UpdateRoute enum (AutoMinor/AwaitApproval/CriticalBypass, app/Enums/UpdateRoute.php:20-22); gated OFF by default config/updates.php:19 and trait WithSmartUpdateRouting.php:24-27; consumed in UpdatesOverview.php:503-510
  - gap: None for the routing engine itself — correctly reported as implemented-behind-flag (default OFF).
- **[§7.2] PARTIAL** — Rule 1 preconditions: version ∈ {minor,patch} AND plugin not on site risk list AND a backup newer than 24h exists AND site has no open incident
  - evidence: Minor/patch gate via Semver::isMajorBump (UpdateDecisionService.php:73) and risk-list gate isFlaggedRisky (UpdateDecisionService.php:77,123-129)
  - gap: The 'backup newer than 24h' and 'no open incident' preconditions are NOT checked anywhere in UpdateDecisionService — decide() only inspects version bump, risk list, AI level, and critical vuln. Two of the four Rule-1 conditions are missing.
- **[§7.2] MISSING** — Rule 1 canary rollout: apply on 2 canary sites, wait 30 min, run smoke check on key URLs, if pass apply to rest, if fail rollback + create task in app.simplead.ro
  - evidence: none
  - gap: No canary orchestration exists. AutoMinor route simply dispatches RunSafeUpdate per site immediately (UpdatesOverview.php:505-512) with no 2-site canary subset, no 30-min wait, no key-URL smoke gate before the rest, and no automatic rollback+task creation on canary failure.
- **[§7.2] IMPLEMENTED** — Rule 2 — major version OR risk-listed plugin → create approval request, do not execute
  - evidence: UpdateDecisionService.php:73-91 routes major bumps and risk-listed plugins to AwaitApproval; WithSmartUpdateRouting.php:60-64 sets approval_required; held (not dispatched) in UpdatesOverview.php:505-510 and surfaced via awaitingApprovals()/approveUpdate()
  - gap: None (AI risk level 'risky'/'unknown' also correctly held as fail-safe).
- **[§7.2] PARTIAL** — Rule 3 — severity ≥ high AND patch available → apply immediately on ALL affected sites regardless of version type, bypassing approval, and notify on Telegram
  - evidence: UpdateDecisionService.php:62-68,106-121 returns CriticalBypass for an active critical vuln with a reachable fix, bypassing the approval hold
  - gap: Only checks severity 'critical' (not the broader '≥ mare/high'). CriticalBypass merely skips the approval gate; there is no automatic fan-out to all affected sites (that still needs an operator to trigger updatePluginAcrossSites) and no Telegram notification is fired by the rule itself.
- **[§7.3] PARTIAL** — Per-site risk list auto-populated at connection from plugin category (page builders, commerce, payments, forms, cache) and incident history, manually overridable
  - evidence: Model SiteRiskyPlugin.php (source 'auto'|'manual', is_risky) + relation Site.php:328-331; read by the engine UpdateDecisionService.php:123-129; migration 2026_07_29_000011_create_site_risky_plugins_table.php
  - gap: No code auto-populates the list at connection: no category classifier (page builder/commerce/payments/forms/cache) and no incident-history seeding write to SiteRiskyPlugin anywhere (grep finds only the read in UpdateDecisionService). No manual-override UI/action exists to flag/unflag a plugin. The table is effectively always empty in practice.
- **[§7.4] PARTIAL** — Stage 1 smoke check (no images) on 3-5 key URLs: HTTP 200, no 'Fatal error' in body, canary selector present, DOM node count vs prior measurement
  - evidence: UpdateSmokeCheckService.php:56-155 implements all four signals exactly (status 200, Fatal/Parse error scan, canary selector w/ <body fallback, DOM node count ±20% vs stored reference); Site has smoke_canary_selector/smoke_dom_reference columns (Site.php:180-181)
  - gap: The service is only referenced by its own unit test (tests/Unit/Services/UpdateSmokeCheckServiceTest.php) — it is NOT wired into any update pipeline; SafeUpdateService uses its own healthCheck+visual regression instead, never calling UpdateSmokeCheckService. Also it probes a single homepage URL ($site->url), not the '3-5 key URLs' the spec requires.
- **[§7.4] PARTIAL** — Stage 2 — before/after screenshots stored for manual verification and reporting; retention: last 3 sets per site
  - evidence: SafeUpdateService captures and stores before/after screenshots (captureScreenshot SafeUpdateService.php:493-508; runVisualRegression:510-527; persisted to screenshot_before/after_path)
  - gap: Retention of 'last 3 sets per site' is not enforced: ScreenshotService::cleanup(siteId, safeUpdateId) (ScreenshotService.php:138-141) deletes only one update's directory and has zero callers in app/ — no pruning to the newest 3 sets per site is scheduled or invoked.
- **[§7.4] NA** — Stage 3 — pixel diff with masked zones is NOT to be implemented
  - evidence: No masked-zone pixel-diff implementation exists; ScreenshotService::compare (ScreenshotService.php:73-136) is only a naive whole-image resized pixel delta with no zone masking, used by legacy visual regression
  - gap: Correctly absent per spec (explicitly deferred). Note the legacy unmasked compare() is not the spec's stage 3 and is a separate feature.
- **[§7.5] MISSING** — Key URLs derived automatically & overridable: homepage; top 3 Search Console pages; contact page (form-detected); a Woo product/category; sitemap + most-linked fallback when no Search Console; quarterly recalculation locking manual overrides
  - evidence: none
  - gap: No key-URL derivation exists. There is no key_urls storage, no derivation from GoogleSearchConsoleService top pages, no contact-form detection for URL selection, no Woo product/category pick, no sitemap/most-linked fallback, and no quarterly recalc job. Only smoke_canary_selector and smoke_dom_reference columns exist on Site; the smoke service runs against the homepage only.
- **[§8] IMPLEMENTED** — Backup programat, multi-destinație (scheduled, multi-destination)
  - evidence: routes/console.php (BackupDispatcher scheduled everyMinute); app/Dispatchers/BackupDispatcher.php:42-73 (picks BackupConfig where next_backup_at<=now, staggers, dispatches CreateBackup/CreateIncrementalBackup with storage_destination_id); app/Jobs/CreateBackup.php:600-603 (ReplicateBackup to secondary_storage_destination_id for 3-2-1); app/Services/Backup/Storage/StorageFactory.php:14 (local/dropbox/s3/b2/hetzner drivers); app/Models/StorageDestination.php
  - gap: None material. Scheduling is per-site BackupConfig (frequency daily/weekly/monthly + time/tz). Multi-destination = one primary destination + optional single secondary replica; not arbitrary N-way fan-out, but satisfies the 3-2-1 intent.
- **[§8] IMPLEMENTED** — Politici de retenție (retention policies)
  - evidence: app/Services/Backup/RetentionService.php:32-95 (chain-aware, replica-aware retention by retention_type days|count + retention_value on BackupConfig); app/Jobs/RetentionCleanup.php scheduled dailyAt 03:00 (routes/console.php); pre-update backups locked (RetentionService.php:96-141)
  - gap: config/backups.php:22 defaults retention_dry_run => env('BACKUP_RETENTION_DRY_RUN', true): unless the prod env sets it false, RetentionService only LOGS 'would delete' and never reclaims (no override found in repo config/.env). Policy engine is complete; effective deletion depends on that flag being flipped in production.
- **[§8] IMPLEMENTED** — Restore complet — fișiere și bază de date (full restore of files + DB)
  - evidence: app/Jobs/RestoreBackup.php:811 sendRestoreData(api,'files',...) and :828 sendRestoreData(api,'database',...); restoreFiles/restoreDatabase component flags (:1060-1061); staged atomic swap + PostRestoreVerifier; selective file restore also supported (:805-994)
  - gap: None. Full and selective restore of both files and database are wired through the connector with site-lock and post-restore verification.
- **[§8] IMPLEMENTED** — Restore VERIFIED automatically — lunar, container efemer (THE DIFFERENTIATOR)
  - evidence: app/Jobs/RunProvenRestore.php (rotates to the longest-unproven site, restores latest completed backup into sandbox, health-checks, records ProvenRestore row, alerts critical on fail); app/Services/Backup/SandboxRestoreService.php:35-116 (restoreInto + runHealthChecks: homepage 200, login reachable, connector loopback, DB-row coherence vs manifest); scheduled weeklyOn(0,'04:30') in routes/console.php; app/Models/ProvenRestore.php + Site.latestProvenRestore
  - gap: Deviates from spec detail in three ways: (1) runs WEEKLY, not monthly (more frequent — acceptable/better); (2) restores into a PERSISTENT registered sandbox WP site (sites.is_sandbox), NOT an ephemeral container that is spun up/torn down per run; (3) gated to opt-in pilot sites only (sites.proven_restore_enabled) and requires format=='v3-zip' — sites on other formats or without the flag are never proven. Core differentiator ('this backup was tested on <date>') is real and recorded, but coverage is pilot-scoped, not fleet-wide.
- **[§8] IMPLEMENTED** — Descărcare prin link semnat (signed-link download)
  - evidence: routes/web.php:144 backups.download via BackupDownloadController with ['signed','throttle:10,1']; routes/web.php:225 app-backups.download signed; app/Http/Controllers/BackupDownloadController.php
  - gap: None. Signed, throttled download routes exist for both site backups and app backups (local storage path).
- **[§8] IMPLEMENTED** — Backup al aplicației însăși (app self-backup — ops, not product)
  - evidence: app/Jobs/CreateAppBackup.php + app/Services/AppBackup/AppBackupService.php; app/Models/AppBackup.php + AppBackupConfig.php; scheduled 'app-backup:schedule-check' everyFifteenMinutes, 'app-backups:recover-stuck', 'app:backup-cleanup' in routes/console.php; plus independent db:dump + db:dump-offsite daily
  - gap: None. App-level backup subsystem (config, scheduler, recovery, cleanup, offsite DB dump) is present and wired independently of site backups.
- **[§9] PARTIAL** — Plan = 3 reusable templates named Bază/Standard/Premium defining uptime level, backup rhythm, active checks, report cadence
  - evidence: database/seeders/MaintenancePlanSeeder.php:15-71 seeds exactly 3 plans; app/Models/MaintenancePlan.php + MaintenancePlanModule (module_key + is_enabled + interval_minutes: uptime/backup/ssl/performance/security/analytics/... with intervals); backup schedule applied via MaintenancePlanService::applyBackupConfigFromPlan
  - gap: Names are 'Full Monitoring' / 'Standard Maintenance' / 'Basic', NOT 'Bază/Standard/Premium' (spec-mandated names absent). Plan defines active checks + intervals (uptime level) yes; backup rhythm only as enabled + a per-plan backup config (frequency), partial. REPORT CADENCE is NOT part of the plan — report frequency lives in a separate per-site ReportSchedule (database/migrations/...report_schedules...), so 'cadență raport' is not a plan attribute. Plan also carries security/tweak/module snapshots (a config-clone template) rather than the spec's tidy 4-attribute template.
- **[§9] IMPLEMENTED** — Plan bound to site (not client); sites of same client may differ
  - evidence: app/Models/MaintenancePlan.php:60 sites() hasMany(Site,'maintenance_plan_id'); app/Models/Site.php fillable 'maintenance_plan_id' + 'is_plan_customized'; ApplyPlanToSite job applies per-site
  - gap: None. Plan is a per-site foreign key (maintenance_plan_id), independent of client; each site can carry a different plan and even a customized override (is_plan_customized).
- **[§9] PARTIAL** — Profil = one per site: URL-uri cheie, selector-canar, listă de risc, adresă de test formulare
  - evidence: selector-canar: app/Models/Site.php:180 smoke_canary_selector (+smoke_dom_reference); listă de risc: SiteRiskyPlugin via Site.php:328 riskyPlugins() (table site_risky_plugins); form detection: form_checks table (migration 2026_07_29_000010) + app/Services/ContactFormChecker.php
  - gap: No unified 'Profile' entity — profile fields are scattered on the Site row/relations. URL-uri cheie (key URLs) MISSING: no per-site key/critical-URL list; smoke check hits only the site homepage (app/Services/UpdateSmokeCheckService.php:47) and there is no monitored-key-URLs field. Adresă de test formulare (form-test recipient address) MISSING: form_checks records detection/suppression outcomes but there is no configurable test-recipient address field. Canary selector and risk list are the only two profile elements actually present.
