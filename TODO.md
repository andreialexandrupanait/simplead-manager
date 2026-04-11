# SimpleAd Manager — Remaining Tasks

- [x] Dark mode — rafinare — CSS overrides comprehensive + UI components + guest layout

## Monitorizare
- [ ] Visual regression testing (screenshot comparison periodic)

## WordPress Management
- [x] Theme file integrity check — `ThemeIntegrityService` + baseline comparison + connector endpoint + notifications
- [x] WordPress version EOL tracking cu alerte — `WordPressEolService` + sync integration + UI badges


## Integrari
- [ ] Slack bot interactiv (commands: /status site.ro, /crawl site.ro)
- [ ] Google Ads integration (spend + conversions in dashboard)

## SEO Module (ramase)
- [x] Content AI: editor rich text (TipTap) — Alpine.js TipTap component + toolbar + `rich-editor` Blade component
- [ ] Content AI: regenerare partiala (selecteaza sectiune → regenereaza)
- [ ] Keyword Research: keyword gap analysis vs competitor
- [x] Incident Response playbook "SEO Critical Drop" — `SeoCriticalDropPlaybook` + `IncidentTriggerType::SeoCriticalDrop`
- [x] Export XLS cu multiple sheets — PhpSpreadsheet + exportXlsx (Pages, Issues, Summary sheets)

## Testing & Quality
- [ ] Test suite expansion (4 test files pt 479+ clase — prioritar Services + Livewire)
- [ ] E2E tests (Playwright/Cypress pt fluxuri critice: backup, restore, plugin update)
- [ ] Code coverage reporting in CI
- [x] PHPStan level upgrade (5 → 6) — config updated, baseline needs regeneration on CI
- [ ] N+1 query audit (102+ queries fara eager loading)

## Documentatie
- [ ] API documentation (OpenAPI/Swagger pt user API + agent API)

---

## Done (implementate)
- [x] Bulk actions pe site-uri — `WithBulkSiteActions` trait
- [x] Plugin vulnerability scanning — `VulnerabilityCheckService` + `VulnerablePluginPlaybook`
- [x] Custom uptime checks (endpoint, keyword, auth, headers) — `UptimeMonitor`
- [x] Lighthouse/PageSpeed automation pe schedule — `PerformanceMonitor`
- [x] White-label PDF reports (logo, branding) — `ReportTemplate`
- [x] Client portal (clientul vede doar site-urile lui) — `/portal/{token}`
- [x] Automated email reports pe schedule — `ReportSchedule`
- [x] Custom webhooks configurabile per event — `WebhookNotificationSender`
- [x] Maintenance windows (pause monitoring) — `UptimeMonitor` maintenance fields
- [x] Safe update rollback automatic — `SafeUpdateService` + `RunSafeUpdate` job
- [x] Playbooks customizabile (if X → do Y → notify Z) — 5 playbooks + `PlaybookRunner`
- [x] Auto-update safe plugins (whitelist) — `toggleAutoUpdate()` in `SitePlugins`

## Removed (intentionat)
- ~~SSL certificate expiry monitoring~~ — removed (migration `drop_ssl_and_domain_tables`)
- ~~Domain expiry monitoring (WHOIS)~~ — removed (same migration)
