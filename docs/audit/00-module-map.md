# 00 — Module Map (Faza 0: Recon)

**Data:** 2026-07-02 · **Scope:** întreg working tree-ul (inclusiv cod necomis: export local backup) · **Sursa de adevăr:** codul, nu documentația.

## Dimensiune reală

| Zonă | Nr. | Cale |
|---|---|---|
| Controllere | 29 | `app/Http/Controllers` (+ `Api/`, `Auth/`) |
| Modele | 91 | `app/Models` |
| Servicii | 124 | `app/Services` (+ subdirectoare: `AppBackup/`, `Backup/`, `IncidentResponse/`, `Notifications/`, `Reports/`, `SeoAudit/`, `WordPress/`) |
| Job-uri | 51 | `app/Jobs` |
| Componente Livewire | 104 | `app/Livewire` |
| Comenzi consolă | 21 | `app/Console/Commands` |
| Fișiere de test | 23 (10 Feature, 13 Unit) | `tests/` — zero teste HTTP/Livewire/auth |

**CI: inexistent** (nu există `.github/`, `.gitlab-ci.yml` etc.). PHPStan level 5 cu baseline de 121 erori (`phpstan-baseline.neon`, 727 linii).

## Entry points globale

- `routes/web.php` (259 linii) — UI Livewire autentificat. Endpoint-uri **fără auth** (token/signed): `/health`, `/api/webhooks/inbound`, `/restore-download/{token}`, `/reports/{report}/download/signed`, `/r/{report}/{token}`, `/download/connector-plugin/signed`.
- `routes/api.php` (44 linii) — 3 suprafețe de auth: (1) `/backup/callback` HMAC `X-Backup-Token`; (2) `/v1/*` Bearer PAT (`/me`, `/sites`); (3) `/agent/{site_token}/security/*` HMAC → `Api/SecurityAgentController`.
- `routes/auth.php` — Breeze + Google SSO + 2FA + invitații.
- `routes/console.php` (218 linii) — ~30 task-uri programate; dispatchere pe minut: Monitoring, DataSync, Backup + procesare batch notificări.
- Connector plugin WP: `wordpress-plugin/simplead-manager-connector/` — v2.14.0, 44 fișiere PHP (~14.9k LOC), 24 clase de endpoint REST în `includes/endpoints/`, gate = IP whitelist → rate limiter → HMAC-SHA256 (`includes/class-authentication.php`; cale legacy fără nonce încă acceptată).

## Module (Faza 1) — reconciliate cu codul

| # | Modul | Fișiere cheie | Entry points | Integrări externe | Distructiv |
|---|---|---|---|---|---|
| 11 | **Sites & Connector** | `app/Livewire/Sites/*`, `Services/WordPressApiService.php` (+`Factory`), `Services/WordPress/*`, `Models/Site.php`, `SiteUser.php`, `PersonalAccessToken.php`, `Jobs/SyncWordPressSite.php`, `PushConnectorPlugin.php`, `Http/Controllers/ConnectorPluginDownloadController.php`, `Api/SecurityAgentController.php`, `Console/Commands/BulkAddSites.php`, `ManageSites.php`, `UpdateConnectorPlugin.php`, plugin WP întreg | UI Sites, API agent HMAC, download plugin signed | site-urile WP ale clienților | **Y** (push plugin) |
| 12 | **Backups & Restore** | `Services/Backup/*` (13 fișiere), `Services/AppBackup/*`, `Services/RollbackService.php`, `WordPressBackupDownloader.php`, Jobs: `CreateBackup`, `CreateIncrementalBackup`, `RestoreBackup`, `ReplicateBackup`, `ExportBackupForLocal` (WIP necomis), `CreateAppBackup`, `PrecacheBackupFileList`, `NotifyBackupFailed`; `Livewire/Backups/*`, `Livewire/Traits/WithBackupActions.php`; Controllers: `BackupDownloadController`, `BackupLocalExportDownloadController` (WIP), `AppBackupDownloadController`, `Api/BackupCallbackController`, `BackupRelayController`; Commands: `VerifyBackupRestoreCommand`, `CleanupBackupTemp`, `BackupReleaseLock`, `ReindexBackupsFromStorageCommand`, `AppBackup*`, `DatabaseDumpCommand`; `Services/Backup/LocalFlywheelRepackager.php` (WIP) | UI Backups, `/backup/callback`, `/restore-download/{token}`, scheduler | S3, Dropbox, site-uri WP | **Y** (restore suprascrie site-ul live) |
| 13 | **Plugin Management** | `Services/PluginManagerService.php`, `SafeUpdateService.php`, `PluginConflictService.php`, `PluginRiskAssessmentService.php`, `PluginAbandonmentService.php`, `Jobs/RunSafeUpdate.php`, `CheckPluginVulnerabilities.php`, `Livewire/Updates/*`, `Livewire/Sites/Detail/SitePlugins.php` | UI Updates | site-uri WP, feed vulnerabilități | **Y** (update pe site live) |
| 14 | **Security & Incident Response** | `Services/Security*` (7), `Services/IncidentResponse/*` (AiAgentService, PlaybookRunner, Playbooks, IncidentActionExecutor), `VulnerabilityCheckService.php`, `CoreFileIntegrityService.php`, `ThemeIntegrityService.php`, `SpamUserDetectionService.php`, Jobs: `RunSecurityScan`, `RunIncidentResponse`, `CheckCoreFileIntegrity`, `CheckThemeIntegrity`, `PushSecuritySettings`, `PullSecurityActivityLogs`, `DeleteSpamUsersJob`, `NotifyIncident`; `Livewire/Security/*`, `Livewire/Sites/Detail/Security/*` (8 pagini), `Api/SecurityAgentController.php`, `Commands/SecurityMaintenanceCommand.php` | UI Security, API agent HMAC, scheduler | site-uri WP, AI (incident response) | **Y** (remediere auto, ștergere useri, ban-uri) |
| 15 | **Uptime & DNS** | `Models/UptimeMonitor|UptimeCheck|UptimeIncident|DnsMonitor|DnsChange`, `Jobs/CheckUptime.php`, `CheckDns.php`, `Services/DnsSelectorDiscoveryService.php`, `Livewire/Uptime/*`, `Livewire/Dns/*`, `Commands/BackfillMonitors.php`, `BackfillDnsMonitors.php` | UI, scheduler (dispatcher pe minut) | HTTP către site-uri, DNS resolvers | N |
| 16 | **Performance (PageSpeed)** | `Services/PageSpeedService.php`, `Jobs/RunPerformanceTest.php`, `NotifyPerformanceDrop.php`, `NotifyBudgetViolation.php`, `Livewire/Performance/*` | UI, scheduler | Google PageSpeed API | N |
| 17 | **SEO Audits** | `Services/SeoAudit/*` (SiteAuditService, ScoringService, AuditDiffService, ExcelExportService), Jobs: `RunSeoAudit`, `CrawlSitePages`, `AnalyzeSeoPages`, `CalculateSeoScores`, `FetchKeywordRankings`, `CheckBrokenResources`; `Livewire/Seo/*`, `Commands/FixStuckSeoAudits.php` | UI, scheduler (dispatcher la 5 min) | crawling site-uri, API rankings | N |
| 18 | **Reports & Clients** | `Services/Report*` (7) + `Services/Reports/Sections/`, `MaintenancePlanService.php`, `GotenbergService.php`, `Jobs/GenerateReport.php`, `NotifyUpcomingReport.php`, `Livewire/Reports/*`, `Livewire/Clients/*` (incl. `ClientProfitability`), `Livewire/MaintenancePlans.php`, Controllers: `ReportDownloadController`, `ReportViewController`, `BulkReportDownloadController`, `ClientPortalController`, `Commands/RegenerateReport.php`, `MigrateReportFiles.php`, Models: `Report`, `ReportSchedule`, `ReportTemplate`, `MaintenancePlan`, `ClientCost`, `ClientRevenue` | UI, portal client, link-uri publice `/r/{report}/{token}`, scheduler | Gotenberg (PDF), Postmark | N |
| 19 | **Integrations** | `Services/CloudflareService.php`, `GoogleAnalyticsService.php`, `GoogleSearchConsoleService.php`, `GoogleApiService.php`, `PostmarkService.php`, `UnsplashService.php`, `ScreenshotService.php`, Jobs: `SyncCloudflareZone`, `FetchAnalyticsData`, `FetchSearchConsoleData`, `ValidateExternalConnections`; Controllers: `GoogleAuthController`, `DropboxAuthController`, `WebhookController` (inbound generic — fostul „Leads": **nu există pipeline de lead-uri**), `Livewire/Settings/IntegrationsSettings.php`, Models: `CloudflareConnection`, `AnalyticsConnection`, `SearchConsoleConnection`, `GoogleConnection` | UI Settings, OAuth callbacks, `/api/webhooks/inbound` | Cloudflare, GA, GSC, Dropbox, Unsplash, Postmark | **Y** (purge cache CF) |
| 20 | **Notificări & Alerting** *(modul nou)* | `Services/Notifications/*` (sendere Email/Slack/Discord/Telegram/Webhook), Jobs: `SendNotificationJob`, `ProcessNotificationBatch`, `ProcessNotificationEscalations`, `SendDailyDigest`; `Livewire/Notifications/*`, `Controllers/NotificationAckController.php`, Models `Notification*` (6) | UI, scheduler (batch pe minut, escaladări la 5 min), ack public | Slack, Discord, Telegram, SMTP/Postmark, webhooks | N |
| 21 | **Status Pages** *(modul nou)* | `Services/StatusPageService.php`, Jobs: `CreateStatusPageIncident`, `ResolveStatusPageIncident`; `Livewire/StatusPages/*`, `Controllers/StatusPageController.php`, Models `StatusPage*` (5) | pagini publice de status | — | N |
| 22 | **Dashboard & Health Scores** *(modul nou)* | `Services/DashboardService.php`, `HealthScoreService.php`, `DatabaseHealthService.php`, `WordPressEolService.php`, Jobs: `RecordHealthScores`, `AggregateMonthlySnapshots`, `CheckDatabaseHealthJob`; `Livewire/Dashboard/GlobalDashboard.php`, Models: `DashboardWidget`, `HealthScoreHistory`, `SiteMonthlySnapshot` | UI dashboard, scheduler | — | N |

## Zone acoperite de audituri cross-cutting (Faza 2), nu de module

- **Users / 2FA / invitații / SSO** → `25-security-appwide.md` (`routes/auth.php`, `Controllers/Auth/*`, `Livewire/Settings/UserManagement.php`, `Models/Invitation.php`)
- **Docker / rețele / Redis / Postgres** → `26-infrastructure-docker.md` (`docker-compose.prod.yml` — 9 servicii; doar nginx expune porturi pe host; Redis cu parolă **doar dacă** `REDIS_PASSWORD` e setat)
- **Horizon (9 cozi, 6 supervisori) + scheduler** → `27-queues-scheduler.md` (mai multe intrări `onOneServer` fără `withoutOverlapping`)
- **Testing & CI** → `28-testing-ci.md`
- **Arhitectură & consistență** → `29-architecture-consistency.md`

## Cod care nu se încadrează curat într-un modul (de verificat ca posibil orfan / infrastructură transversală)

- `Services/CircuitBreakerService.php`, `JobTracker.php`, `RetentionPolicyService.php`, `ActivityLogger.php`, `SettingsService.php`, `ModuleConfigService.php`, `BulkSettingsCopyService.php`, `OpenApiService.php` — infrastructură transversală; evaluate în `29-architecture-consistency.md`.
- `Livewire/ErrorLogs/*` + `Jobs/FetchPhpErrorLogs.php` — fetch loguri PHP de pe site-uri; atașat modulului 11 (Sites).
- `Livewire/Activity/*` — timeline activitate; atașat modulului 22.
- `Jobs/FetchSiteFavicon.php`, `Commands/BackfillSiteScreenshots.php`, `FaviconBackfillCommand.php` — cosmetice, modulul 11.
- Modulul „Leads ingestion" din specificația inițială **nu există** — reîncadrat ca webhook generic în modulul 19.

## Operații distructive (prioritate maximă de audit)

1. Restore backup pe site live — `Jobs/RestoreBackup.php`, `RollbackService.php`, `/restore-download/{token}`
2. Restore-ul aplicației manager — `Services/AppBackup/AppBackupRestorer.php`
3. Push plugin / safe-update / rollback pe site-uri remote — `PushConnectorPlugin`, `RunSafeUpdate`, `SafeUpdateService`
4. Remediere automată de securitate + ban-uri + playbooks AI — `IncidentActionExecutor`, `Playbooks/`, `RunIncidentResponse`
5. Ștergere useri WP remote — `DeleteSpamUsersJob`, `SpamUserDetectionService`
6. Curățare DB remote — `DatabaseCleanupService`
7. Purge cache Cloudflare — `CloudflareService`
8. Ștergeri de retenție — `RetentionCleanup`, `CleanupBackupTemp`, `security:maintenance`
