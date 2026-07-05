# Remediation Status — audit 2026-07

Tracking pentru remedierea auditului. Scope: toate P0 + P1 + P2/P3 cu impact funcțional (~137 items).
Statusuri: `pending` → `fixed` (implementat, gate verde) → `verified` (confirmat de agent verificator în Val 6) | `wontfix` (cu motiv).

Valuri: **V0** fundație testare · **V1** P0 restore/safe-update · **V2** authz sistemic · **V3** feature-uri moarte · **V4** observabilitate · **V5** per modul · **V6** re-audit · **V7** ROADMAP v2.

## P0

| ID | Titlu | Val | Status |
|---|---|---|---|
| S-01 / B-P1-2 | Restore fără authz + IDOR cross-tenant | — | fixed (PR #6, 2026-07-02) |
| B-P0-1 / QS-01 | Fără lock per-site pe restore; restore ucis rămâne in_progress | V1 | pending |
| B-P0-2 | Restore non-atomic, fără rollback, safety backup doar DB | V1 | pending |
| PM-P0-1 | SafeUpdateService: slug în loc de file + success hardcodat | V1 | pending |
| PM-P0-2 | Update-urile UI sincrone, fără backup/health/rollback; RunSafeUpdate mort | V1 | pending |

## Module 11 — Sites & Connector

| ID | Titlu | Val | Status |
|---|---|---|---|
| 11/S-P1-1 | IDOR SiteSettings/ReportRecommendationsManager (mount fără authz) | V2 | pending |
| 11/S-P1-2 / S-05 | Agent auth mort: where pe coloană encrypted | V3 | pending |
| 11/S-P1-3 | /error-logs fără tenant scoping | — | fixed (PR #11, 2026-07-02) |
| S-P2-3 | rotateApiKeys fără caller (rotire chei neimplementată) | V5 | pending |
| S-P2-4 | PushConnectorPlugin fără ShouldBeUnique (corupe plugin dir la push concurent) | V5 | pending |
| S-P2-5 | SEO calls cu header greșit (= ARH-01) | V3 | pending |
| S-P3-1 | ETag connector mereu 0.0.0 (config inexistent) | V5 | pending |

## Module 12 — Backups & Restore

| ID | Titlu | Val | Status |
|---|---|---|---|
| B-P1-1 | Restore fără failed()/uniqueFor; release-lock nu acoperă restore | V1 | pending |
| B-P1-3 | DiskSpaceGuard oprește backupurile doar cu Log::warning | V4 | pending |
| B-P1-4 | Retenția șterge lanțuri incrementale valide | V5 | pending |
| B-P1-5 | deleteBackup/bulkDelete fără guard incremental | V5 | pending |
| B-P1-6 | Export Local nu validează formatul sursă | V5 | pending |
| B-P1-7 | Content browser nu știe v3-zip; precache în cod mort | V5 | pending |
| B-P1-8 | Fără audit trail pe restore/delete | V4 | pending |
| B-P2-8 | Verify Level-B sare peste fișiere pe v3-zip | V5 | pending |
| B-P2-12 | Tar exit code ignorat la selective restore | V5 | pending |
| B-P3-1 | downloadBackup multipart-v3 → link mort | V5 | pending |

## Module 13 — Plugin Management

| ID | Titlu | Val | Status |
|---|---|---|---|
| PM-P1-1 | Rollback lookup pe name în loc de slug | V3 | pending |
| PM-P1-2 | Acțiuni distructive plugin/temă fără authorizeSiteModification | V2 | pending |
| PM-P1-3 | updatePluginAcrossSites fără canAccessSite per site | V1 | pending |
| PM-P1-4 | auto_update toggle mort + resetat la sync | V3 | pending |
| PM-P1-5 | Timeout 30s pe update-uri → false failures | V5 | pending |
| PM-P1-6 | Backup-before-update async și doar DB | V1 | pending |
| PM-P2-1 | updateCore raportează success necondiționat | V5 | pending |
| PM-P2-2 | RunSafeUpdate nedispatch-uit (cod mort) | V1 | pending |
| PM-P2-4 | executeRollback fără guard pe status + success??true | V5 | pending |
| PM-P2-5 | CheckPluginVulnerabilities monolitic, site-uri nescanate silențios | V5 | pending |
| PM-P2-6 | Conflicte plugin detectate dar fără UI | V5 | pending |

## Module 14 — Security & Incident Response

| ID | Titlu | Val | Status |
|---|---|---|---|
| 14/S-P1-5 | Query pe coloane inexistente (plugin_slug, cvss_score) în ContextGatherer | V3 | pending |
| 14/S-P1-1 | RunIncidentResponse fără failed() → incident non-terminal | V5 | pending |
| 14/S-P1-2 | Acțiuni distructive security cu authz de viewer | V2 | pending |
| 14/S-P1-3 | apply_security_fix fără allowlist server-side | V5 | pending |
| 14/S-P1-4 | Scan-uri eșuate persistent nu alertează | V4 | pending |
| 14/S-P2-1 | Playbook keys neimplementate în plugin (disable_debug, reinstall_core) | V5 | pending |
| 14/S-P2-5 | Theme integrity nedispatch-uit; playbooks fără trigger | V5 | pending |
| 14/S-P2-6 | Model AI hardcodat deprecat (claude-sonnet-4) | V5 | pending |

## Module 15 — Uptime & DNS

| ID | Titlu | Val | Status |
|---|---|---|---|
| U-P1-1 | Timeout HTTP monitor ≥ timeout job → site down nedetectat | V4 | pending |
| U-P1-2 | Circuit breaker oprește uptime din eșecuri nelegate | V4 | pending |
| U-P1-2b | Site-uri fără site_health_states nedispatch-uite | V4 | pending |
| U-P1-3 | Recovery notificat fără down notificat | V4 | pending |
| U-P1-4 | Acțiuni Livewire fără authz + SSRF prin URL monitor | V2 | pending |
| D-P1-5 | dns_get_record()===false tratat ca zero records | V4 | pending |
| U-P2-1 | is_up=false de la primul eșec (înainte de threshold) | V5 | pending |
| U-P2-2 | uptime_365d/30d fictive sub retenția reală | V5 | pending |
| U-P2-4 / QS-06 | ShouldBeUnique fără uniqueFor → monitor blocat | V5 | pending |
| D-P2-5 | Eșec DNS check doar Log::warning | V5 | pending |
| D-P2-6 | ~32 lookups sincrone în tries=1 → SIGKILL loop | V5 | pending |
| U-P3-1 | Tip "ping" acceptat dar neimplementat | V5 | pending (elimină) |
| U-P3-2 | Câmpuri fantomă (check_ssl, check_locations, alert_contacts) | V5 | pending (elimină) |
| U-P3-5 | updatedSearch gol → paginare neresetată | V5 | pending |
| — | DnsOverview TypeError pe site soft-deleted (acknowledge/rediscover/save) | V0 | fixed |

## Module 16 — Performance

| ID | Titlu | Val | Status |
|---|---|---|---|
| PF-P1-1 | Testele programate nu rulează deloc (dispatch șters la refactor) | V3 | pending |
| PF-P1-2 | Mutatori fără authz + testAllSites fără rate limit | V2 | pending |
| PF-P2-1 | Editorul de budgets șters → checkBudgets mort | V3 | pending |
| PF-P2-2 | Competitor benchmarking fără job | V5 | pending (decizie audit) |
| PF-P2-3 | „Manual only" pică validarea; monthly nehandluit | V5 | pending |
| PF-P2-4 | Erori API înghițite per-URL; 429 netratat | V5 | pending |
| PF-P2-7 | Timeout mic → teste `running` forever, fără cleanup | V5 | pending |
| PF-P2-8 | ShouldBeUnique fără uniqueFor | V5 | pending |
| PF-P2-9 | Score History labels/serii dezaliniate | V5 | pending |
| PF-P2-10 | trendSummary fără fallback pe pagina primară | V5 | pending |
| PF-P3-2 | addPage max:500 vs coloană varchar(255) → 500 | V5 | pending |
| PF-P3-8 | Site soft-deleted → $monitor->site null → Error | V5 | pending |

## Module 17 — SEO

| ID | Titlu | Val | Status |
|---|---|---|---|
| 17/S-P1-1 / ARH-01 | SEO Fix mort: header inexistent, fără HMAC | V3 | pending |
| 17/S-P1-2 | bulkFix periculos (suprascrie post_title cu text crawl-uit) | V3 | pending |
| 17/S-P1-3 | CrawlSitePages timeout → duplicate seo_pages | V4 | pending |
| 17/S-P1-4 | Buclă infinită de re-dispatch la eșec audit | V4 | pending |
| 17/S-P1-5 | Formula injection în export Excel | V5 | pending |
| 17/S-P1-6 | Acțiuni write fără authorizeSiteModification | V2 | pending |
| 17/S-P2-2 | scan_duration negativ (Carbon 3 signed diff) | V5 | pending |
| 17/S-P2-5 | FetchKeywordRankings înghite erori; delete-then-insert fără tranzacție | V5 | pending |
| 17/S-P2-7 | Security headers case-sensitive → false missing | V5 | pending |
| 17/S-P2-8 | Dispatch pierdut silențios pe lock; FixStuck șterge failed_jobs | V5 | pending |
| 17/S-P3-4 | Quick Audit creează site prospect duplicat la fiecare run | V5 | pending |

## Module 18 — Reports & Clients

| ID | Titlu | Val | Status |
|---|---|---|---|
| R-P1-1 | renderLineChart inexistent → rapoarte crăpate pe site-uri cu SEO | V3 | pending |
| R-P1-2 | Generate All: dispatch fără period args → ArgumentCountError | V3 | pending |
| R-P1-3 | Link public permanent cu vulnerabilități + PII | V3 | pending |
| R-P1-4 | IDOR deleteSchedule/saveSchedule/deleteReport | V2 | pending |
| R-P1-5 | ClientProfitability fără authorize | V2 | pending |
| R-P2-1 | „Updates Applied" numără și eșecurile | V5 | pending |
| R-P2-3 | GenerateReport înghite excepțiile → fals succes în Horizon | V5 | pending |
| R-P2-5 | Bulk download blochează adminii; temp dir lipsă → 500 | V5 | pending |
| R-P2-7 | Schedule fields nevalidate → tick-ul dispatcher-ului moare | V4 | pending |
| R-P2-8 / R-P3-5 | Uptime raportat pe altă fereastră decât perioada | V5 | pending |
| R-P2-10 | sortBy nevalidat → 500 | V5 | pending |
| R-P2-11 | regenerate nu reface data_snapshot | V5 | pending |
| R-P3-2 | Portal listează drafts netrimise | V5 | pending |
| R-P3-4 | Recomandări draft atașate la raportul greșit | V5 | pending |
| ARH-07 | Preview-urile wizard ≠ cifrele din PDF | V5 | pending |

## Module 19 — Integrations

| ID | Titlu | Val | Status |
|---|---|---|---|
| I-P1-1 | Eșecuri Google API deschid circuit breaker-ul site-ului | V4 | pending |
| I-P1-2 | Cloudflare purge/connect cu authz de viewer | V2 | pending |
| I-P1-3 | Webhook inbound public fără auth | V5 | pending (dezactivare) |
| I-P2-3 | token_expires_at null → fatal la fetch | V5 | pending |
| I-P2-1 | ValidateExternalConnections monolitic tries=1 | V5 | pending |
| I-P3-5 | Ștergere GoogleConnection lasă conexiuni orfane | V5 | pending |

## Module 20 — Notifications

| ID | Titlu | Val | Status |
|---|---|---|---|
| N-P1-1 | Canal defect = alerte pierdute silențios; fără delivery log UI | V4 | pending |
| N-P1-2 | Ack link generat dar niciodată livrat; ping-pong escaladare | V3 | pending |
| N-P1-3 | Alerta „Horizon down" prin coada Horizon | — | fixed (verificat 2026-07-05: MasterSupervisorRepository direct) |
| N-P1-4 | UI arată 12 din ~20 evenimente | V4 | pending |
| N-P2-1 | Quiet hours aruncă notificările (nu defer) | V5 | pending |
| N-P2-2 | notify_down/recovery/degraded salvate dar necitite | V5 | pending |
| N-P2-7 | Telegram Markdown neescapat → 400 → alertă pierdută | V5 | pending |
| N-P2-8 | Email „sent" la queue, nu la trimitere | V5 | pending |
| N-P2-10 | Catalog EVENTS cu evenimente inexistente | V4 | pending |
| N-P2-11 | In-app doar pt owner; evenimente app fără in-app | V5 | pending |
| N-P3-6 | Ack pe GET → unfurl bots auto-ack | V3 | pending |
| N-P3-10 | Lazy-load în escaladări → moarte silențioasă în dev | V5 | pending |
| QS-11 | Escaladare non-atomică → dublă escaladare | V5 | pending |

## Module 21 — Status Pages

| ID | Titlu | Val | Status |
|---|---|---|---|
| SP-P1-1 | Ștergerea unui user șterge cascadat status pages + istoric | V5 | pending |
| SP-P2-1 | Badge SVG ocolește parola | V5 | pending |
| SP-P2-4 | Incidente fantomă din race + incidente stuck open | V5 | pending |
| SP-P2-5 | Auto-incidente folosesc numele intern, nu display_name | V5 | pending |
| SP-P2-7 | Custom domains fără rutare/TLS | V5 | wontfix deocamdată (per audit — polish, cere infra nginx/TLS) |
| SP-P2-8 | Scheduled maintenance display-only (nimic nu scrie câmpurile) | V5 | pending |
| SP-P3-3 | updateIncidentStatus fără allowlist | V5 | pending |
| SP-P3-5 | sort_order duplicat → reordering no-op | V5 | pending |
| SP-P3-8 | Cache 60s neinvalidat la incident manual | V5 | pending |
| SP-P3-9 | Incident templates fără CRUD (dropdown gol) | V5 | pending (decizie audit) |
| SP-P3-11 | Incidente cu site_id null nu se auto-rezolvă | V5 | pending |

## Module 22 — Dashboard & Health

| ID | Titlu | Val | Status |
|---|---|---|---|
| D-P1-1 | sites.health_score niciodată scris → filtre/sort/API pe NULL | V3 | pending |
| D-P1-2 | Componenta security mereu 12/25 (citea coloana greșită) | V0 | **fixed 2026-07-05** — HealthScoreService citește sites.security_hardening_score |
| D-P1-3 | Activity timeline incomplet pe operații distructive | V4 | pending |
| D-P1-4 | Coloane snapshot cloudflare_*/seo_* nepopulate → raport N/A | V5 | pending |
| D-P2-2 | Snapshot = cache 28d la momentul agregării, nu luna calendaristică | V5 | pending |
| D-P2-3 | Cuplaj temporal snapshots→reports fără verificare | V5 | pending |
| D-P2-6 | WordPressEolService hardcodat 6.0/6.7.2 | V5 | pending |
| D-P2-7 | CheckDatabaseHealthJob niciodată programat | V5 | pending |
| D-P3-1 | Trend „pending updates" comparat cu valoare de acum 60s | V5 | pending |
| D-P3-2 | Alerts card cu trend greșit | V5 | pending |
| D-P3-4 | checkNow fără monitor consumă rate-limit degeaba | V5 | pending |
| D-P3-6 | Tooltip „latest" ignoră EOL | V5 | pending |

## Module 25 — Security app-wide

| ID | Titlu | Val | Status |
|---|---|---|---|
| S-02 | authorizeSiteAccess pe acțiuni distructive (viewer allowed) | V2 | parțial fixed (PR #8/#11); rest în V2 |
| S-03 | EnforceTwoFactor exceptează tot livewire* | V2 | pending |
| S-04 | Google SSO ocolește 2FA | V2 | pending |
| S-05 | api_key encrypted + where pe plaintext (= 11/S-P1-2) | V3 | pending |

## Cross-cutting — Infra (26), Queues (27), Testing (28), Arhitectură (29)

| ID | Titlu | Val | Status |
|---|---|---|---|
| INF-01 | Deploy ucide joburi in-flight | — | fixed (PR #9 + #12, 2026-07-02) |
| INF-02 | $config nedefinit în AppBackupCreator → orice app-backup failed | V0 | **fixed 2026-07-05** — $config = AppBackupConfig::instance() în create() |
| INF-03 | db:dump exit code = gzip, nu pg_dump | V3 | pending |
| INF-04 | PgBouncer nerestartat după DDL | — | fixed (PR #9, 2026-07-02) |
| INF-05 / QS-05 | Bugete memorie Horizon > limita containerului → OOM | V4 | pending |
| INF-08 | nginx nu reîncarcă certul TLS reînnoit | V4 | pending |
| INF-11 | Redis fail-open la parolă goală | V4 | pending |
| INF-16 | Healthcheck scheduler placebo | V4 | pending |
| QS-02 | Alertare circulară Horizon + scheduler nemonitorizat | V4 | pending |
| QS-03 | Niciun task cu runInBackground → dispatcher-ele blocate | V4 | pending |
| QS-04 | Mutex orfan withoutOverlapping → skip silențios 24h | V4 | pending |
| QS-07 | ExportBackupForLocal fără onQueue | V5 | pending |
| QS-08 | failed_jobs necurățat | V5 | pending |
| QS-09 | CleanupBackupTemp ignoră restore-* | V5 | pending |
| QS-12 | Alertă doar la exact a 3-a eșuare/oră/clasă | V4 | pending |
| T-01..T-06 | Suită ștearsă / CI / izolare / phpstan baseline | V0 | **fixed 2026-07-05** — bin/test funcțional (160 teste verzi), PHPStan rulează complet (circular dep fix) și e blocking în CI, PHPUnit blocking, baseline regenerat + triat (2 bug-uri A reparate), excludePaths eliminat |
| T-08 | Interfața WP incompletă (getErrorLogs etc.) + mock shapes greșite | V0 | **fixed 2026-07-05** — interfață completată (5 metode), FakeWordPressApiService cu shape-uri reale |
| T-10 | Doar driver local testat (S3/Dropbox nu) | V5 | pending |
| T-11 | Export Local fără teste | V5 | pending |
| ARH-01 | Canal HTTP paralel cu header inexistent (= 17/S-P1-1) | V3 | pending |
| ARH-12 | local_export_status stringly-typed | V5 | pending |
| — | Dependență circulară AppBackupService↔AppBackupRestorer (recursie infinită la app(AppBackupService)) | V0 | **fixed 2026-07-05** — extras AppBackupDownloader; asta debloca și PHPStan |

## Extra (găsite în timpul remedierii, în afara auditului)

| Descriere | Val | Status |
|---|---|---|
| DnsOverview: TypeError la acknowledge/rediscoverSelectors/saveSelectors pe monitor cu site soft-deleted; monitorii orfani listați în UI | V0 | fixed 2026-07-05 |
