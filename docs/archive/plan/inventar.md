# Inventar module existente — Faza A (22 iulie 2026, HEAD `0351c29`)

Scurt inventar al modulelor SimpleAd Manager, ca bază pentru propunerile din Faza B.
Cifre generale: Laravel 11.48 · 104 componente Livewire · 91 modele · 60 joburi · 30 comenzi ·
~700 metode de test · conector WP 2.17.1 (~6.200 linii) · producție pe dasher, 11 containere
(app, horizon, scheduler, nginx, pgsql, pgbouncer, redis, gotenberg, certbot + sam-test-pgsql/redis).

## Monitorizare & incidente
- **Uptime** — `CheckUptime` job, 2 workeri (`config/horizon.php`, `HORIZON_UPTIME_WORKERS`),
  praguri per monitor, incidente + escaladare. Coloana `check_locations` (jsonb) există în schemă
  și model dar e nefolosită (pregătire multi-locație, Faza F).
- **SSL / DNS / domenii** — verificări programate certificate, DNS, expirare domenii, status pages publice.
- **Error logs** — colectare din conector (`/error-logs`), UI dedicat.
- **PageSpeed** — măsurători programate (PSI), istoric per site; sursa de LCP pentru acceptanța webp (Faza E).

## Backup & restore
- **Backup v3** full+incremental (`CreateBackup`, 1.118 linii) — chunk-uri, direct upload, manifest,
  verificare A/B; **Restore** (`RestoreBackup`, 1.132 linii) — staged restore, transport sincron
  2 POST-uri 1800s (țintă C2), token `/restore-download/{token}` fără expirare (țintă C1).
- **Offsite** — destinații configurabile; fără validare activă a credențialelor/ultimei replicări (țintă C2).
- Recovery: `backups:recover-stuck-restores` la 15 min.

## Safe updates & administrare WP
- **Safe updates** cu rollback (plugin/temă/core) + health-check + feed Wordfence vulnerabilități.
- **Conector 2.17.1** — HMAC (`METHOD|PATH|TIMESTAMP|NONCE|BODY`, anti-replay nonce, ±300s),
  ~70 rute REST semnate sub `simplead/v1`: info/health, plugins (update/activate/deactivate/delete —
  **fără install-from-slug**), themes, core, users, security, backup (20 rute), rollback, database,
  cron, site-tweaks, SEO (9 rute — scrie deja chei **Yoast/RankMath/AIOSEO** + fallback `_sam_*`),
  redirects, posts, cache, self-update, key-rotation. Versiune raportată pull-based via `/info` →
  `sites.connector_version`; **fără handshake de capabilități** (țintă C2). Push: job
  `PushConnectorPlugin` + rută semnată `download.connector-plugin.signed` + hash sha256 verificat.
- **Securitate WP** — hardening semnat, 2FA email pentru site-urile clienților (conector 2.17.0),
  IP ban/whitelist, integritate core/teme, captcha, presets. Fără scanner de semnături malware (țintă E, bifabil).
- **Site tweaks** — performance/site-control/admin-UX/content-media, hub comun cu Security.

## SEO (modulul care va fi ÎNLOCUIT — autopsie completă la R3)
- Crawler propriu `CrawlSitePages` (797 linii) → `AnalyzeSeoPages` → `CalculateSeoScores`
  (scoruri ponderate 0–100 din `config/seo.php`); dispatcher la 5 min pe `seo_monitors`.
- UI: `SeoOverview`, `SeoQuickAudit`, `SiteSeoAudit` (787 linii, Livewire) + GSC (`SiteSearchConsole`).
- **GSC** — `GoogleSearchConsoleService`/`GoogleApiService` (OAuth, refresh cu lock), keyword
  rankings zilnice; curat separabil de crawler — candidat SUPRAVIEȚUIEȘTE.
- **Bulk-fix** — `ApplySeoBulkFix` → `/seo/update-meta|canonical|og`, non-destructiv (E-10) —
  candidat SUPRAVIEȚUIEȘTE (fundația pentru aplicarea fix-urilor AI din D4).
- Tabele active: `seo_monitors`, `seo_audits`, `seo_pages`, `seo_links`, `seo_images`, `seo_issues`,
  `seo_keyword_rankings`, `search_console_connections`, `search_console_cache`.
  Tabele orfane (fără model/consum): vezi raport-faza-A punctul 4.

## Rapoarte & portal
- **Rapoarte PDF lunare** RO/EN white-label prin Gotenberg (gatherers per secțiune, inclusiv
  `SeoGatherer`/`SearchConsoleGatherer` — de rescris pe modulul nou în D).
- **Portal client** token-izat (`hash_equals`, revocare, throttle) — modelul pentru raportul public din D6.

## Notificări & integrare
- **Notificări** 5 canale cu escaladare + ack; **fără agregare cross-site** a furtunilor (țintă C2).
- **Cloudflare** — zone, purge (bază pentru geo/WAF rules, Faza E bifabilă).
- **AI incident response** — construit, dezactivat, fără cheie în prod (decizie la Faza F).
- **Profitabilitate + planuri mentenanță** — `ModuleConfigService::MODULE_MAP` (gating per-site
  per-modul; cheia `seo` lipsește din MAP — relevant pentru feature-flag-ul tranziției D).

## Tooling & calitate
- CI blocant (`ci.yml`) + gate fail-closed în `deploy.sh`; Pint · PHPStan (baseline 50KB, 1.411
  linii — țintă F) · PHPUnit (~700 metode; infra test: containere `sam-test-pgsql`/`sam-test-redis`).
- Fără PHP pe host — tooling prin `docker run … simplead-app:latest`.
- `.env.example` lipsă; `database/schema/pgsql-schema.sql` stale (14 mai) — ținte C1.
