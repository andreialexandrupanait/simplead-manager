---
name: Session 28 martie 2026 — full roadmap execution
description: Complete session summary — what was built, what remains, known bugs, and next steps for tomorrow
type: project
---

## Session 28 martie 2026

### Ce s-a facut

**25 commits** (`b475f0a` → `e7ef982`) pe `main`, tot pushed + deployed prod.

#### Tech Debt (Faza 1 + 2)
- **PHPStan baseline 35 → 0** (zero erori, zero baseline)
- **109 teste noi**: 18 job tests, 37 gatherer tests, 38 storage driver tests, 16 notification sender tests
- **SafeUpdateFactory** creat
- **Exception cleanup**: 11 generic catches → specifice (QueryException, RuntimeException, etc.)
- **Paginare**: StatusPagesList, ReportTemplatesSettings, PresetManager
- **HTTP caching headers**: status page API (60s), health (no-store), connector plugin (1h+ETag), reports (24h+ETag)
- **SiteReports refactorizat** → ReportManagementService extras (scheduling, send, delete, bulk ops)
- **CI improvements**: PHPStan baseline guard (max 0), test coverage reporting (pcov), migration rollback verification, DB_HOST fix pt CI
- **Bug fixes**: UptimeGatherer avg() float cast, UptimeStatsCard number_format float/int cast, getenv()→config(), glob() safe return

#### Features implementate (22 total)
1. **FQW-01**: Status page SVG badge (`/status/{slug}/badge.svg`)
2. **FQW-05**: Maintenance windows pe uptime monitors (start/end/reason, skip checks, UI modal + badge)
3. **FQW-06**: Incident templates pt status pages (model + template picker in incident form)
4. **F-01**: User invitation system (model, mailable, controller, Livewire UserManagement, accept flow, 72h expiry)
5. **F-02**: Personal API tokens (model, Bearer auth middleware, ProfileSettings UI, `/api/v1/me` + `/api/v1/sites`)
6. **F-03**: Notification message templates (model cu placeholders, service integration, CRUD UI)
7. **F-04**: Performance trend summary (30d avg delta, 180d range adaugat)
8. **F-05**: Team hierarchy — client_user pivot, User.canAccessSite(), policies actualizate, client assignment UI
9. **F-06**: Analytics anomaly detection (Z-score >2σ pe daily users, WoW trend cards)
10. **F-07**: Uptime multi-location foundation (location column pe checks, check_locations config pe monitors)
11. **F-08**: SLA tracking pe status pages (target vs actual, 3 luni istoric din snapshots, public display)
12. **F-09**: Backup encryption at rest (AES-256-GCM, opt-in pe backup config, encrypt/decrypt in CreateBackup/RestoreBackup)
13. **F-10**: Client report portal (token-based, site health overview, report list + download + interactive view)
14. **F-11**: Plugin license tracking (encrypted key, expiry date, status display, isLicenseExpiring/Expired helpers)
15. **F-12**: Notification escalation (rules, ack tokens, ProcessNotificationEscalations job every 5min, ack endpoint)
16. **F-13**: Interactive report dashboard (ReportView Livewire + portal report.blade cu 17 sectiuni)
17. **F-14**: Google SSO via Socialite (google_id pe users, GoogleSsoController, "Sign in with Google" pe login)
18. **F-15**: MFA enforcement (EnforceTwoFactor middleware, admin toggle in General Settings)
19. **F-16**: Plugin rollback (rollbackPlugin() via WP API, rollback button in plugin detail modal)
20. **F-17**: Competitor benchmarking (competitor_urls pe monitors, comparison table, add/remove UI)

#### Migratii (14)
```
2026_03_28_000001 — maintenance window on uptime_monitors
2026_03_28_000002 — status_page_incident_templates
2026_03_28_000003 — invitations
2026_03_28_000004 — personal_access_tokens
2026_03_28_000005 — notification_templates
2026_03_28_000006 — sla_target on status_pages
2026_03_28_000007 — check_location on uptime
2026_03_28_000008 — client_user pivot
2026_03_28_000009 — license fields on site_plugins
2026_03_28_000010 — encrypt_backups on backup_configs
2026_03_28_000011 — portal_token on clients
2026_03_28_000012 — escalation support (rules + ack on logs)
2026_03_28_000013 — google_id on users
2026_03_28_000014 — competitor benchmarking
```

### Ce ramane de facut

#### Urgent/Bugs
- **Client portal UX** — pagina de raport arata basic, fara grafice. Necesita Chart.js pt uptime chart, analytics users over time, performance scores. Design UX serios. User a confirmat ca revenim.
- **Rapoarte generare** — de verificat daca bug-ul `number_format` (fixat in UptimeStatsCard) era singura cauza. Daca rapoartele tot nu se genereaza, verifica log-urile.
- **Nginx DNS cache on staging deploy** — de fiecare data cand staging se rebuild-uieste, nginx prod cache-uieste IP-ul vechi → 502. Trebuie adaugat `docker compose -f docker-compose.prod.yml restart nginx` in `deploy-staging.sh`. Idem PgBouncer pt migratii DDL.

#### Tech Debt ramas
- **TD-13**: Teste Livewire — 52 componente netestate (din 74). Target: 60+. XL effort.
- **TD-14**: E2E smoke tests cu Laravel Dusk. L effort.
- **TD-16**: Exception cleanup — ~110 generic catches ramase. L effort (repetitiv).
- **TD-17**: PHPStan level 6 — 1298 erori. Sesiune dedicata necesara. M effort.

#### Features ramase
- **F-18**: Synthetic monitoring — multi-step user flows via headless browser. XL, necesita Docker sidecar pt Playwright. Cel mai complex feature.
- **Client portal v2** — grafice interactive, design profesional, responsive mobile. L effort.

### Lectii invatate
1. **PgBouncer restart obligatoriu** dupa ALTER TABLE migrations — cached prepared statements cauzeaza "cached plan must not change result type"
2. **PostgreSQL returneaza string** din `avg()` — trebuie cast `(float)` explicit
3. **CI DB_HOST** — phpunit.xml avea `pgsql` care nu exista in CI (GitHub Actions PostgreSQL e pe `localhost`)
4. **`@vite` nu CDN** — portalul public trebuie sa foloseasca `@vite(['resources/css/app.css'])` nu Tailwind CDN

### Deploy checklist pt maine
```bash
# Staging deploy
./deploy-staging.sh
docker compose -f docker-compose.prod.yml restart nginx
docker compose -f docker-compose.staging.yml restart db-proxy

# Production deploy
npm ci && npm run build && rm -rf node_modules
docker compose -f docker-compose.prod.yml build app nginx
docker compose -f docker-compose.prod.yml exec app php artisan down
docker compose -f docker-compose.prod.yml up -d app horizon scheduler
docker compose -f docker-compose.prod.yml exec -e DB_HOST=simplead-pgsql -e DB_PORT=5432 app php artisan migrate --force
docker compose -f docker-compose.prod.yml restart pgbouncer
docker compose -f docker-compose.prod.yml exec app php artisan queue:restart
docker compose -f docker-compose.prod.yml exec app php artisan up
docker compose -f docker-compose.prod.yml up -d nginx
curl -s https://manager.simplead.ro/health
```
