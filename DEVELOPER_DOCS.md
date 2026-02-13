# SimpleAD Manager - Developer Documentation

> Internal developer reference for the SimpleAD Manager WordPress site management platform.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Project Structure](#2-project-structure)
3. [Database Schema](#3-database-schema)
4. [Models & Relationships](#4-models--relationships)
5. [Services Layer](#5-services-layer)
6. [Jobs & Background Processing](#6-jobs--background-processing)
7. [Livewire Components](#7-livewire-components)
8. [Routes](#8-routes)
9. [Middleware](#9-middleware)
10. [WordPress Connector Plugin](#10-wordpress-connector-plugin)
11. [Frontend](#11-frontend)
12. [Integrations](#12-integrations)
13. [Authorization & Security](#13-authorization--security)
14. [Deployment](#14-deployment)

---

## 1. Architecture Overview

### Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11 (PHP 8.3) |
| Frontend | Livewire 3 + Alpine.js 3.4 + Tailwind CSS 3.1 |
| Database | PostgreSQL 16 |
| Cache/Queue | Redis 7 |
| Queue Worker | Laravel Horizon |
| Asset Bundling | Vite |
| Charts | Chart.js 4.5 |
| Drag & Drop | SortableJS 1.15 |
| PDF Reports | DomPDF |
| Containerization | Docker Compose |
| SSL | Let's Encrypt (Certbot) |
| Web Server | Nginx |

### Communication Flow

```
                  ┌─────────────────┐
                  │   Browser/User  │
                  └────────┬────────┘
                           │ HTTPS
                  ┌────────▼────────┐
                  │     Nginx       │
                  └────────┬────────┘
                           │ FastCGI
                  ┌────────▼────────┐
              ┌───│  Laravel App    │───┐
              │   │  (PHP-FPM)      │   │
              │   └────────┬────────┘   │
              │            │            │
     ┌────────▼───┐  ┌─────▼─────┐  ┌──▼───────────┐
     │ PostgreSQL  │  │   Redis   │  │  Horizon     │
     │ (Data)      │  │(Cache/Q)  │  │ (Job Worker) │
     └─────────────┘  └───────────┘  └──────────────┘
                           │
              HMAC-SHA256 REST API
                           │
              ┌────────────▼────────────┐
              │  WordPress Sites        │
              │  (Connector Plugin)     │
              └─────────────────────────┘
```

The Laravel app communicates with WordPress sites via HMAC-SHA256 authenticated REST API calls to the connector plugin installed on each managed site. All destructive operations are rate-limited. Background jobs handle periodic syncing, monitoring, and notifications.

---

## 2. Project Structure

```
simplead-manager/
├── app/
│   ├── Http/
│   │   ├── Controllers/         # 8 controllers (downloads, auth callbacks, health, status pages)
│   │   └── Middleware/          # 4 middleware (security headers, locale, site context, custom domains)
│   ├── Jobs/                    # 40 background jobs
│   ├── Livewire/
│   │   ├── Backups/             # Backup overview component
│   │   ├── Clients/             # Client CRUD components (3)
│   │   ├── Components/          # Reusable Livewire components (5)
│   │   ├── Dashboard/           # Dashboard + widgets (5 pages, 7 widgets)
│   │   ├── Performance/         # Performance overview
│   │   ├── Reports/             # Reports overview
│   │   ├── Settings/            # Settings pages (7) + sub-components (2)
│   │   ├── Sites/               # Site list, create, detail pages (22)
│   │   ├── StatusPages/         # Status page management (2)
│   │   ├── Traits/              # Reusable traits (4)
│   │   └── Uptime/              # Uptime overview + config (2)
│   ├── Models/                  # 70 Eloquent models
│   ├── Policies/                # 4 authorization policies
│   ├── Providers/               # AppServiceProvider, HorizonServiceProvider
│   └── Services/                # 34+ service classes
│       ├── AppBackup/           # AppBackupService
│       ├── Backup/Storage/      # Storage drivers (Local, Dropbox, S3)
│       └── Notifications/       # Multi-channel notification senders (5)
├── bootstrap/
│   └── app.php                  # Middleware registration, exception handling
├── config/
│   ├── database.php             # PostgreSQL connection config
│   ├── monitoring.php           # Thresholds for DB health, security, backups
│   ├── services.php             # API keys for Google, Cloudflare, Dropbox, PageSpeed
│   └── ...
├── database/
│   └── migrations/              # 91 migration files
├── docker/
│   └── php/Dockerfile.prod      # PHP 8.3-FPM Alpine image
├── docker-compose.prod.yml      # Production Docker Compose (7 services)
├── resources/
│   └── views/
│       ├── components/          # 100+ Blade components (UI, charts, icons, etc.)
│       ├── layouts/             # App, guest, status-page layouts
│       ├── livewire/            # Livewire component views
│       ├── mail/                # Email alert templates
│       ├── emails/              # Additional email templates
│       └── reports/             # PDF report templates
├── routes/
│   ├── web.php                  # All web routes
│   ├── auth.php                 # Authentication routes (Breeze)
│   └── console.php              # Scheduled tasks (30+ schedules)
├── storage/                     # Logs, backups, cache (volume-mounted in Docker)
├── wordpress-plugin/            # WordPress connector plugin
│   └── simplead-manager-connector/
│       ├── simplead-manager-connector.php  # Plugin bootstrap
│       └── includes/
│           ├── class-admin.php             # Admin UI
│           ├── class-authentication.php    # HMAC-SHA256 auth
│           ├── class-audit-logger.php      # Audit logging
│           ├── class-login-handler.php     # One-time login tokens
│           ├── class-rate-limiter.php      # API rate limiting
│           ├── class-rest-api.php          # REST API registration
│           └── endpoints/                  # 18 REST API endpoint classes
├── tailwind.config.js           # Tailwind CSS config
├── vite.config.js               # Vite build config
└── package.json                 # Frontend dependencies
```

---

## 3. Database Schema

91 migration files define the complete schema. Tables are organized by domain below.

### Core

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `users` | Application users | `name`, `email`, `password`, `is_admin`, `timezone`, `date_format`, `language`, `two_factor_enabled`, `two_factor_secret`, `two_factor_recovery_codes`, `avatar_path` |
| `clients` | Client organizations (soft deletes) | `name`, `email`, `phone`, `company`, `website`, `address`, `city`, `country`, `vat_number`, `registration_number`, `logo`, `notes`, `status`, `is_active` |
| `sites` | Managed WordPress sites (soft deletes) | `name`, `url`, `user_id`, `client_id`, `status`, `sort_order`, `type`, `health_score`, `is_up`, `uptime_percentage`, `wp_version`, `php_version`, `is_multisite`, `is_connected`, `api_key` (encrypted), `api_secret` (encrypted), `api_endpoint` (encrypted), `ssl_ok`, `ssl_expiry`, `backup_ok`, `last_backup_at`, `pending_updates_count`, `core_update_version`, `db_size_mb`, `uploads_size_mb`, `favicon_path`, `screenshot_path`, `has_woocommerce`, `notes`, `last_synced_at` |
| `app_settings` | Global key-value configuration | `group`, `key` (unique), `value`, `type` (string/boolean/integer/json) |
| `activity_logs` | Comprehensive audit trail | `site_id`, `user_id`, `type`, `severity`, `title`, `description`, `metadata` (JSON), `icon`, `url` |
| `dashboard_widgets` | User dashboard layouts | `user_id`, `widget_type`, `position`, `is_visible`, `config` |

### Uptime Monitoring

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `uptime_monitors` | Monitor configuration | `site_id`, `type` (http/https/keyword/ping), `url`, `interval`, `timeout`, `http_method`, `keyword`, `keyword_type`, `check_ssl`, `ssl_expiry_threshold`, `auth_type`, `alert_after_failures`, `alert_contacts` (JSON), `status` (active/paused), `current_state` (up/down/degraded), `uptime_24h/7d/30d/365d`, `avg_response_time`, `last_response_time` |
| `uptime_checks` | Individual check results | `monitor_id`, `is_up`, `response_time`, `status_code`, `failure_reason`, `keyword_found`, `ssl_expires_at`, `checked_at` |
| `uptime_incidents` | Downtime events | `monitor_id`, `status` (ongoing/resolved), `cause`, `started_at`, `resolved_at`, `notified_via` (JSON), `notified_at` |

### SSL & Domain Monitoring

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `ssl_certificates` | SSL certificate tracking | `site_id`, `domain`, `issuer`, `issuer_organisation`, `san_domains` (JSON), `signature_algorithm`, `key_size`, `issued_at`, `expires_at`, `days_remaining`, `chain_valid`, `status` (pending/valid/expiring_soon/expired/error), `alerts_enabled`, `warn_days` |
| `ssl_check_history` | SSL check log | `ssl_certificate_id`, check results |
| `domain_monitors` | Domain expiry monitoring | `site_id`, `domain`, `registrar`, `expires_at`, `warn_days` |
| `domain_check_history` | Domain check log | `domain_monitor_id`, check results |

### Plugins & Themes

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `site_plugins` | Installed plugins per site | `site_id`, `file`, `slug`, `name`, `version`, `author`, `is_active`, `auto_update`, `has_update`, `update_version`, `requires_wp`, `requires_php`, `wp_org_last_updated`, `is_on_wp_org`, `is_abandoned`, `is_closed`, `closed_reason` |
| `site_themes` | Installed themes per site | Similar to plugins |
| `update_logs` | Update history | `site_id`, `type`, `name`, `from_version`, `to_version`, `status` |
| `plugin_conflicts` | Known conflict pairs | `plugin_a_slug`, `plugin_b_slug`, `severity`, `description` |
| `site_plugin_conflicts` | Detected conflicts per site | `site_id`, `plugin_conflict_id` |

### Backups

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `storage_destinations` | Backup storage configuration | `name`, `type` (local/dropbox/s3), `config` (JSON), `is_default`, `quota_bytes`, `used_bytes` |
| `backup_configs` | Site backup schedules | `site_id`, `is_enabled`, `frequency` (daily/weekly/monthly), `time`, `day_of_week`, `day_of_month`, `timezone`, `type` (full/database), `exclude_paths` (JSON), `exclude_tables` (JSON), `storage_destination_id`, `retention_type` (count/days), `retention_value`, `backup_before_updates`, `next_backup_at` |
| `backups` | Backup records | `site_id`, `storage_destination_id`, `type` (full/database), `trigger` (manual/scheduled/pre_update), `status` (pending/in_progress/completed/failed), `file_path`, `file_name`, `file_size`, `checksum`, `wp_version`, `php_version`, `is_locked`, `lock_reason`, `expires_at`, `stage`, `progress_percent`, `progress_message`, `restore_status`, `restore_stage`, `restore_progress_percent` |
| `app_backup_configs` | Application-level backup config | `is_enabled`, `frequency`, `type`, `storage_destination_id`, `retention_value`, `next_backup_at` |
| `app_backups` | Application database backups | `type`, `trigger`, `status`, `file_path`, `file_size` |

### Security

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `security_scans` | Security scan results | `site_id`, `score` (0-100), `scores_breakdown` (JSON), `critical_count`, `high_count`, `medium_count`, `low_count`, `scan_duration`, `scanned_at` |
| `security_issues` | Individual security issues | `security_scan_id`, `site_id`, `category`, `severity`, `title`, `description`, `remediation`, `is_fixable`, `is_ignored` |
| `security_recommendations` | Security best practices | `site_id`, `category`, `severity`, `title`, `description`, `status` |
| `vulnerability_alerts` | CVE and vulnerability tracking | `site_id`, `plugin_slug`, `severity`, `title`, `affected_versions`, `fixed_version`, `cve_id` |
| `ip_rules` | Firewall IP rules | `site_id`, `ip_address`, `type` (allow/block), `reason`, `expires_at`, `hits` |
| `blocked_requests` | Firewall blocked requests log | `site_id`, `ip_address`, `uri`, `method`, `blocked_at` |
| `core_file_checks` | WordPress core file integrity | `site_id`, `status` (clean/modified/error), `modified_files` (JSON), `missing_files` (JSON), `unknown_files` (JSON) |
| `wp_audit_logs` | WordPress audit trail (synced) | `site_id`, `action`, `object_type`, `object_name`, `user_login`, `user_ip`, `details`, `action_at` |

### Performance & Links

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `performance_monitors` | Performance test configuration | `site_id`, `is_active`, `frequency`, `test_url`, `next_test_at` |
| `performance_tests` | PageSpeed test results | `performance_monitor_id`, `strategy` (desktop/mobile), `scores` (JSON), `metrics` (JSON), `opportunities` (JSON), `diagnostics` (JSON) |
| `link_monitors` | Link scan configuration | `site_id`, `is_active`, `max_depth`, `max_pages`, `next_scan_at` |
| `link_scans` | Link scan executions | `link_monitor_id`, `status`, `pages_scanned`, `links_found`, `broken_count` |
| `links` | Individual link results | `link_scan_id`, `source_url`, `target_url`, `status_code`, `is_broken`, `link_text` |

### Google Integrations

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `google_connections` | Google OAuth tokens | `user_id`, `google_id`, `email`, `name`, `access_token` (encrypted), `refresh_token` (encrypted), `token_expires_at`, `scopes` |
| `analytics_connections` | GA4 property links | `site_id`, `google_connection_id`, `property_id`, `property_name`, `data_stream_id`, `data_stream_url`, `is_active`, `last_sync_at` |
| `search_console_connections` | GSC property links | `site_id`, `google_connection_id`, `property_url`, `is_active`, `last_sync_at` |
| `analytics_cache` | Cached GA data | `site_id`, `period`, `metric`, `data` (JSON), `fetched_at` |
| `search_console_cache` | Cached GSC data | `site_id`, `period`, `metric`, `data` (JSON), `fetched_at` |

### Cloudflare Integration

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `cloudflare_connections` | API token per user | `user_id`, `api_token` (encrypted), `account_id`, `account_email`, `is_valid` |
| `site_cloudflare` | Site-to-zone mapping | `site_id`, `cloudflare_connection_id`, `zone_id`, `zone_name`, `plan`, `status` |
| `cloudflare_cache_purges` | Cache purge log | `site_cloudflare_id`, `type`, `targets`, `purged_at` |

### Reports

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `report_templates` | Customizable report templates | `name`, `sections` (JSON), `branding` (JSON), `is_default` |
| `report_schedules` | Automated report generation | `site_id`, `report_template_id`, `frequency`, `period`, `is_active`, `recipients` (JSON), `next_run_at` |
| `reports` | Generated reports | `site_id`, `report_template_id`, `report_schedule_id`, `title`, `period_start`, `period_end`, `file_path`, `file_name`, `file_size`, `page_count`, `status` (pending/generating/completed/failed), `trigger` (scheduled/manual), `was_sent`, `sent_to` (JSON), `data_snapshot` (JSON), `generated_at` |

### Status Pages

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `status_pages` | Public status page configuration | `user_id`, `name`, `slug` (unique), `custom_domain`, `logo`, `description`, `is_public`, `password_hash`, `branding` (JSON), `show_uptime_chart` |
| `status_page_sites` | Sites shown on status page | `status_page_id`, `site_id`, `display_name`, `sort_order` |
| `status_page_incidents` | Status page incidents | `status_page_id`, `site_id`, `title`, `status` (investigating/identified/monitoring/resolved), `severity`, `started_at`, `resolved_at` |
| `status_page_incident_updates` | Incident update timeline | `status_page_incident_id`, `status`, `message` |

### Other Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `notification_channels` | Multi-channel alerting config | `name`, `type` (email/slack/discord/telegram/webhook), `config` (JSON), `is_default`, `is_active` |
| `notification_logs` | Notification delivery log | `channel_id`, `event`, `title`, `status`, `sent_at` |
| `site_users` | WordPress users per site | `site_id`, `username`, `email`, `role`, `last_login` |
| `site_cron_jobs` | WordPress cron jobs per site | `site_id`, `hook`, `schedule`, `interval`, `next_run_at`, `is_disabled` |
| `database_cleanups` | DB cleanup operations log | `site_id`, `type`, `items_cleaned`, `size_freed` |
| `database_health_checks` | DB health analysis results | `site_id`, `total_size`, `autoload_size`, `overhead`, `status`, `details` (JSON) |
| `email_health_checks` | Email deliverability results | `site_id`, `score`, `checks` (JSON) |
| `error_logs` | Synced PHP error logs | `site_id`, `level`, `message`, `file`, `line`, `context` (JSON), `hash`, `is_resolved`, `occurrence_count` |
| `dns_records_cache` | Cached DNS records | `site_id`, `records` (JSON), `email_security_score`, `fetched_at` |
| `maintenance_windows` | Planned downtime | `site_id`, `title`, `start_at`, `end_at`, `status`, `update_status_page`, `notify_channels` |
| `resource_checks` | Server resource snapshots | `site_id`, `cpu_percent`, `memory_total/used`, `disk_total/used`, `load_average`, `checked_at` |
| `seo_checks` | SEO audit results | `site_id`, `score`, `checks` (JSON), `recommendations` (JSON) |
| `rollback_points` | Plugin/theme rollback snapshots | `site_id`, `plugin_slug`, `version`, `file_path`, `expires_at` |
| `safe_updates` | Update with rollback info | `site_id`, `rollback_point_id`, `plugin_slug`, `from_version`, `to_version`, `status` |
| `woocommerce_stats` | WooCommerce metrics | `site_id`, `revenue`, `order_count`, `avg_order_value`, `products`, `customers`, `period` |
| `woocommerce_alerts` | WooCommerce alerts | `site_id`, `type`, `product_id`, `product_name`, `stock_quantity` |
| `tracked_keywords` | Keyword rank tracking | `site_id`, `keyword`, `search_console_connection_id` |
| `keyword_positions` | Keyword position history | `tracked_keyword_id`, `position`, `clicks`, `impressions`, `date` |

---

## 4. Models & Relationships

70 Eloquent models in `app/Models/`. Key models and their relationships:

### User (`app/Models/User.php`)

```
User
├── hasMany → Site
├── hasMany → DashboardWidget
├── hasMany → ActivityLog
└── hasMany → CloudflareConnection
```

- **Fillable**: name, email, password, is_admin, timezone, date_format, language, two_factor_enabled/secret/recovery_codes, avatar_path
- **Hidden**: password, remember_token, two_factor_secret, two_factor_recovery_codes
- **Casts**: password→hashed, two_factor_enabled→boolean, two_factor_secret→encrypted, two_factor_recovery_codes→encrypted:array
- **Accessors**: `getInitialsAttribute()` - user initials from name

### Site (`app/Models/Site.php`)

```
Site
├── belongsTo → User
├── belongsTo → Client
├── belongsTo → SiteStatus
├── hasOne    → UptimeMonitor, SslCertificate, DomainMonitor,
│               PerformanceMonitor, LinkMonitor, BackupConfig,
│               AnalyticsConnection, SearchConsoleConnection,
│               DnsRecordCache, SiteCloudflare
└── hasMany   → SitePlugin, SiteTheme, SiteUser, UpdateLog,
                Backup, ReportSchedule, Report, ActivityLog,
                MaintenanceWindow, CoreFileCheck, SitePluginConflict,
                SiteCronJob, DatabaseCleanup, ErrorLog,
                DatabaseHealthCheck, EmailHealthCheck, SecurityScan,
                SecurityIssue, SecurityRecommendation,
                VulnerabilityAlert, WpAuditLog, IpRule,
                RollbackPoint, SafeUpdate, ResourceCheck,
                SeoCheck, WooCommerceStats, WooCommerceAlert,
                TrackedKeyword
```

- **Encrypted**: api_key, api_secret, api_endpoint
- **Soft deletes**: enabled
- **Scopes**: `healthy()` (score >= 90 & is_up), `warning()` (70-89 & is_up), `critical()` (< 70 or !is_up), `searchable($term)`, `connected()`, `withPendingUpdates()`
- **Accessors**: `getDomainAttribute()`, `getOverallStatusAttribute()`, `getFaviconUrlAttribute()`, `getScreenshotUrlAttribute()`
- **Boot events**: Auto-assign sort_order on create; auto-create monitors (SSL, domain, performance, link, uptime) and fetch favicon on created

### Client (`app/Models/Client.php`)

- **Relationships**: `hasMany(Site::class)`
- **Soft deletes**: enabled
- **Scopes**: `active()`, `search($search)` - filters by name, email, company, phone
- **Accessors**: `getDisplayNameAttribute()`, `getInitialsAttribute()`

### Backup (`app/Models/Backup.php`)

- **Relationships**: `belongsTo(Site)`, `belongsTo(StorageDestination)`
- **Scopes**: `completed()`, `failed()`, `forSite($siteId)`
- **Accessors**: `getFileSizeFormattedAttribute()`, `getStatusColorAttribute()`, `getRestoreStatusColorAttribute()`, `getIsRestoringAttribute()`, `getSizeDiffAttribute()`

### SecurityScan (`app/Models/SecurityScan.php`)

- **Relationships**: `belongsTo(Site)`, `hasMany(SecurityIssue)`
- **Accessors**: `getScoreColorAttribute()` (green >=80, yellow >=50, red <50), `getScoreLabelAttribute()`, `getTotalIssuesAttribute()`

### UptimeMonitor (`app/Models/UptimeMonitor.php`)

- **Relationships**: `belongsTo(Site)`, `hasMany(UptimeCheck)`, `hasMany(UptimeIncident)`, `hasOne(UptimeCheck)->latestOfMany()`
- **Scopes**: `active()`, `due()`
- **Encrypted**: auth_password, auth_token
- **Casts**: http_headers→array, accepted_status_codes→array, alert_contacts→array

### Other Notable Models

| Model | Key Relationships |
|-------|------------------|
| `Report` | belongsTo Site, ReportTemplate, ReportSchedule |
| `CloudflareConnection` | belongsTo User; hasMany SiteCloudflare |
| `GoogleConnection` | belongsTo User; hasMany AnalyticsConnection |
| `AnalyticsConnection` | belongsTo Site, GoogleConnection |
| `BackupConfig` | belongsTo Site, StorageDestination |
| `StatusPage` | belongsTo User; hasMany StatusPageSite, StatusPageIncident |
| `SitePlugin` | belongsTo Site; scopes: `abandoned()`, `closed()`, `problematic()`, `withUpdates()`, `active()`, `inactive()` |
| `ActivityLog` | belongsTo Site, User; scopes: `ofType()`, `ofSeverity()`, `recent()` |

---

## 5. Services Layer

34 service classes in `app/Services/`, organized by domain.

### Core Infrastructure

| Service | Location | Purpose |
|---------|----------|---------|
| `WordPressApiService` | `app/Services/WordPressApiService.php` | HMAC-authenticated HTTP client for WordPress REST API. Core `request()` method signs all requests. Methods: `getInfo()`, `getPlugins()`, `getThemes()`, `updatePlugins()`, `updateThemes()`, `activatePlugin()`, `deactivatePlugin()`, `deletePlugin()`, `getSecurityCheck()`, `applySecurityFix()`, `getCoreIntegrityCheck()`, `getDatabaseHealth()`, `getCleanupStats()`, `runCleanup()`, `getBackupDb()`, `getBackupFiles()`, `getCronList()`, `runCron()`, `getServerResources()`, `getAuditLogs()`, `syncIpRules()`, `getBlockedRequests()`, `generateLoginUrl()`, `getErrorLogs()`, `getSeoCheck()`, `getWooStats()` |
| `ActivityLogger` | `app/Services/ActivityLogger.php` | Static service for creating audit trail records. Methods: `log()`, `siteDown()`, `siteRecovered()`, `backupCompleted()`, `backupFailed()`, `pluginUpdated()`, `themeUpdated()`, `pluginActivated()`, `pluginDeactivated()`, `pluginDeleted()`, `themeActivated()`, `themeDeleted()`, `pluginRolledBack()`, `coreUpdated()`, `performanceScoreDrop()`, `linkScanCompleted()`, `reportGenerated()`, `reportSent()`, `appBackupCompleted()`, `appBackupFailed()`, `appDatabaseRestored()`, `userLogin()`, `userLogout()`, `userLoginFailed()` |
| `JobTracker` | `app/Services/JobTracker.php` | Cache-based job progress tracking. Methods: `start()`, `progress()`, `complete()`, `fail()`, `get()`. Stores state in Redis with TTLs (1 hour running, 5 min finished). Used by Livewire `WithJobTracking` trait. |
| `SettingsService` | `app/Services/SettingsService.php` | Database-driven application settings (app_settings table). Get/set key-value pairs grouped by category. |
| `DashboardService` | `app/Services/DashboardService.php` | Dashboard data aggregation. Methods: `getStats()`, `getAlerts()`, `getSitesOverview()`, `getUptimeOverview()`, `getRecentActivity()`, `getSummaryStats()`, `getHealthDistribution()`, `getSitesNeedingAttention()`, `getBackupStatus()`. Groups alerts by severity (critical, warning, info). |
| `WidgetService` | `app/Services/WidgetService.php` | Dashboard widget management and positioning. |

### Monitoring & Sync

| Service | Location | Purpose |
|---------|----------|---------|
| `AuditLogService` | `app/Services/AuditLogService.php` | Syncs WordPress audit logs from connected sites. Methods: `sync()`, `export()`. Deduplicates based on action type, timestamp, and user. |
| `ErrorLogService` | `app/Services/ErrorLogService.php` | Syncs PHP error logs from WordPress. Methods: `sync()`, `resolve()`, `resolveAll()`. Deduplicates by MD5 hash. Throttles fatal error notifications to 30 min/site. |
| `CronManagerService` | `app/Services/CronManagerService.php` | WordPress cron job management. Methods: `sync()`, `run()`, `disable()`, `enable()`. |
| `DatabaseHealthService` | `app/Services/DatabaseHealthService.php` | Database performance analysis. Methods: `check()`. Analyzes table sizes, overhead, engine types. Configurable thresholds via `config/monitoring.php`. Returns status: clean/warning/critical. |
| `DatabaseCleanupService` | `app/Services/DatabaseCleanupService.php` | WordPress database cleanup. Methods: `getStats()`, `run()`. Cleans revisions, auto-drafts, trash, spam, transients, orphaned meta. |
| `CoreFileIntegrityService` | `app/Services/CoreFileIntegrityService.php` | WordPress core file verification against official checksums. Methods: `fetchOfficialChecksums()`, `check()`. Detects modified, missing, unknown files. |
| `PluginAbandonmentService` | `app/Services/PluginAbandonmentService.php` | Checks WordPress.org API for abandoned/closed plugins. Methods: `checkPlugin()`, `checkAllForSite()`. Default threshold: 2 years. |
| `ResourceMonitorService` | `app/Services/ResourceMonitorService.php` | Server resource monitoring (CPU, memory, disk) via WordPress API. |

### Security

| Service | Location | Purpose |
|---------|----------|---------|
| `SecurityScanService` | `app/Services/SecurityScanService.php` | Comprehensive security scanning. Methods: `scan()`, `checkHeaders()`, `resolveIssue()`, `ignoreIssue()`. Checks HTTP security headers, SSL status, core integrity, vulnerabilities. Calculates score (0-100) with category breakdown. |
| `SecurityRecommendationService` | `app/Services/SecurityRecommendationService.php` | Configuration-based security recommendations. |
| `VulnerabilityCheckService` | `app/Services/VulnerabilityCheckService.php` | CVE/vulnerability tracking for plugins. |
| `IpFirewallService` | `app/Services/IpFirewallService.php` | IP blocking/allowlisting. Methods: `addRule()`, `removeRule()`, `syncToSite()`, `fetchBlockedRequests()`. Tracks blocked request hits per rule. |

### Integrations

| Service | Location | Purpose |
|---------|----------|---------|
| `CloudflareService` | `app/Services/CloudflareService.php` | Full Cloudflare API integration. DNS: `listDnsRecords()`, `createDnsRecord()`, `updateDnsRecord()`, `deleteDnsRecord()`. Cache: `purgeEverything()`, `purgeByUrls()`, `purgeByTags()`. Security: `getSecurityLevel()`, `setSecurityLevel()`, `listFirewallRules()`, `createFirewallRule()`, `blockIpViaCloudflare()`. SSL: `getSslMode()`. Analytics: `getAnalytics()`. Rate limited to 200 req/min. |
| `GoogleApiService` | `app/Services/GoogleApiService.php` | Base class for Google API services. Handles OAuth token refresh. |
| `GoogleAnalyticsService` | `app/Services/GoogleAnalyticsService.php` | GA4 Data API integration. Methods: `listProperties()`, `getOverview()`, `getUsersOverTime()`, `getTrafficSources()`, `getTopPages()`, `getDevices()`, `getCountries()`, `getReferralSources()`, `getLandingPages()`, `getDemographics()`, `getRealtimeData()`, `getCities()`. |
| `GoogleSearchConsoleService` | `app/Services/GoogleSearchConsoleService.php` | Search Console API. Methods: `listProperties()`, `getOverview()`, `getPerformanceOverTime()`, `getTopQueries()`, `getTopPages()`, `getCountries()`, `getDevices()`, `getSitemaps()`, `inspectUrl()`, `getFilteredResults()`, `getQueryPositionHistory()`. |
| `DnsService` | `app/Services/DnsService.php` | DNS record checking. Methods: `lookup()`, `fetchAndCache()`, `detectWww()`, `detectCloudflare()`, `detectSpf()`, `detectDmarc()`, `detectDkim()`, `detectMailProvider()`, `calculateEmailSecurityScore()`. Checks A, AAAA, CNAME, MX, TXT, NS, SOA. |

### Content & Performance

| Service | Location | Purpose |
|---------|----------|---------|
| `PageSpeedService` | `app/Services/PageSpeedService.php` | Google PageSpeed Insights API. Methods: `analyze()`, `parseResults()`. Returns performance/accessibility/best-practices/SEO scores, Core Web Vitals (FCP, LCP, CLS, TBT, SI, TTI), field data from CrUX, opportunities, diagnostics. |
| `LinkCheckerService` | `app/Services/LinkCheckerService.php` | BFS link crawler. Methods: `scan()`. Configurable depth and page limits. Batches external link checks. Detects broken links, redirects, SSL errors, DNS errors, timeouts. |
| `SeoService` | `app/Services/SeoService.php` | SEO analysis. Methods: `fetchAndStore()`, `calculateScore()`, `getRecommendations()`. Checks: title, meta description, sitemap, robots.txt, OG tags, Twitter cards, schema markup, canonical, heading hierarchy. |
| `WooCommerceService` | `app/Services/WooCommerceService.php` | WooCommerce data integration via WordPress API. |

### Reporting & Notifications

| Service | Location | Purpose |
|---------|----------|---------|
| `ReportGeneratorService` | `app/Services/ReportGeneratorService.php` | PDF report generation using DomPDF. Methods: `generate()`, `gatherData()`. Sections: overview, updates, uptime, backups, analytics, search console, performance, links. |
| `StatusPageService` | `app/Services/StatusPageService.php` | Public status page management. Methods: `createAutoIncident()`, `resolveAutoIncident()`, `createMaintenanceIncident()`, `resolveMaintenanceIncident()`, `getPublicData()`, `verifyPassword()`. Caches public data for 60 seconds. |
| `NotificationService` | `app/Services/Notifications/NotificationService.php` | Multi-channel notification dispatch. Static method: `notifyAppEvent()`. Routes to active channels. |
| `EmailNotificationSender` | `app/Services/Notifications/EmailNotificationSender.php` | Email delivery |
| `SlackNotificationSender` | `app/Services/Notifications/SlackNotificationSender.php` | Slack webhook delivery |
| `DiscordNotificationSender` | `app/Services/Notifications/DiscordNotificationSender.php` | Discord webhook delivery |
| `TelegramNotificationSender` | `app/Services/Notifications/TelegramNotificationSender.php` | Telegram bot delivery |
| `WebhookNotificationSender` | `app/Services/Notifications/WebhookNotificationSender.php` | Generic webhook delivery |

### Backup & Recovery

| Service | Location | Purpose |
|---------|----------|---------|
| `AppBackupService` | `app/Services/AppBackup/AppBackupService.php` | Application-level database backup/restore. Methods: `create()`, `restore()`, `cleanupExpired()`. |
| `StorageFactory` | `app/Services/Backup/Storage/StorageFactory.php` | Factory for backup storage drivers. |
| `LocalDriver` | `app/Services/Backup/Storage/LocalDriver.php` | Local filesystem backup storage. |
| `DropboxDriver` | `app/Services/Backup/Storage/DropboxDriver.php` | Dropbox backup storage. |
| `S3Driver` | `app/Services/Backup/Storage/S3Driver.php` | S3-compatible backup storage. |
| `StorageDriver` | `app/Services/Backup/Storage/StorageDriver.php` | Abstract base for storage drivers. |
| `RollbackService` | `app/Services/RollbackService.php` | Plugin/theme rollback. Methods: `createPoint()`, `rollback()`, `cleanExpired()`. |
| `SafeUpdateService` | `app/Services/SafeUpdateService.php` | Safe update with automatic rollback on failure. |

### Other

| Service | Location | Purpose |
|---------|----------|---------|
| `MaintenanceService` | `app/Services/MaintenanceService.php` | Maintenance window scheduling. Methods: `isSiteInMaintenance()`, `processScheduledWindows()`, `processEndingWindows()`, `startMaintenance()`, `endMaintenance()`, `cancelMaintenance()`. Creates status page incidents, pauses monitors. |
| `PluginConflictService` | `app/Services/PluginConflictService.php` | Plugin compatibility checking. |
| `EmailDeliverabilityService` | `app/Services/EmailDeliverabilityService.php` | Email deliverability testing. |

---

## 6. Jobs & Background Processing

40 job classes in `app/Jobs/`, processed by Laravel Horizon.

### Monitoring Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `CheckUptime` | Every minute (due monitors) | HTTP/HTTPS/keyword/ping checks. Creates/resolves incidents. Sends notifications on state change. |
| `CheckSslCertificate` | Every 12 hours | Validates SSL certificates, tracks expiration, sends alerts. |
| `CheckDomainExpiry` | Daily | Monitors domain registration expiry via WHOIS. |
| `CheckResourceUsage` | Every 15 minutes | Monitors server CPU, memory, disk via WordPress API. |
| `CheckVulnerabilities` | Daily at 3:00 AM | Checks plugin vulnerabilities against CVE databases. |
| `CheckAbandonedPluginsJob` | On-demand / during sync | Identifies abandoned/closed plugins via WordPress.org API. |
| `CheckCoreFileIntegrity` | On-demand | Verifies WordPress core files against official MD5 checksums. |
| `CheckDatabaseHealthJob` | On-demand | Analyzes database table sizes, overhead, autoload bloat. |
| `CheckEmailDeliverabilityJob` | Weekly (Wednesday 4 AM) | Tests email deliverability and DNS configuration. |

### Data Synchronization Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SyncWordPressSite` | Every 6 hours | Full WordPress data sync (plugins, themes, health, users, etc.) |
| `SyncAuditLogs` | Every 30 minutes | Syncs WordPress audit logs to local DB. |
| `SyncErrorLogsJob` | Every 15 minutes | Syncs PHP error logs from WordPress sites. |
| `SyncCloudflareZone` | Daily | Syncs Cloudflare zone details and settings. |
| `SyncWooCommerceStats` | Every 6 hours | Syncs WooCommerce revenue, orders, stock data. |
| `FetchAnalyticsData` | Daily at 6:00 AM | Fetches Google Analytics data for connected sites. |
| `FetchSearchConsoleData` | Daily at 6:00 AM | Fetches Google Search Console data. |
| `FetchKeywordPositions` | Daily at 6:30 AM | Tracks keyword ranking positions. |
| `FetchBlockedRequests` | Hourly | Fetches firewall-blocked requests from WordPress. |
| `FetchSiteFavicon` | Daily (backfill) | Downloads and stores site favicons. |

### Backup & Recovery Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `CreateBackup` | Every 15 min (due configs) | Creates full/database site backups. Downloads from WordPress, stores via storage driver. |
| `CreateAppBackup` | Every 15 min (due configs) | Creates application-level database backups. |
| `RestoreBackup` | On-demand | Restores site from a backup. |
| `ExecuteRollback` | On-demand | Rolls back a plugin/theme update. |

### Scanning & Testing Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `RunSecurityScan` | Weekly (Sunday 2 AM) | Full security audit: headers, SSL, integrity, vulnerabilities. |
| `RunPerformanceTest` | Hourly | Runs PageSpeed Insights analysis. |
| `RunLinkScan` | Hourly | BFS link crawler for broken link detection. |
| `RunSeoCheck` | Weekly (Monday 5 AM) | SEO audit (title, meta, sitemap, schema, etc.). |
| `RunSafeUpdate` | On-demand | Performs update with rollback capability. |

### Reporting Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `GenerateReport` | Hourly (due schedules) | Generates PDF reports using DomPDF. |
| `SendDailyDigest` | Daily at 7:00 AM | Sends daily health digest email. |

### Notification Jobs

| Job | Trigger | Purpose |
|-----|---------|---------|
| `SendNotificationJob` | On-demand | Generic multi-channel notification sender. |
| `NotifyIncident` | Uptime state change | Notifies on site down/up events. |
| `NotifySslAlert` | SSL check | Alerts on SSL expiration. |
| `NotifyDomainAlert` | Domain check | Alerts on domain expiration. |
| `NotifyBackupFailed` | Backup failure | Alerts on backup failure. |
| `NotifyBrokenLinks` | Link scan | Alerts on broken links found. |
| `NotifyPerformanceDrop` | Performance test | Alerts on performance score drops. |
| `NotifyBudgetViolation` | Performance test | Alerts on performance budget violations. |

### Status Page Jobs

| Job | Trigger | Purpose |
|-----|---------|---------|
| `CreateStatusPageIncident` | Uptime incident | Creates public status page incident. |
| `ResolveStatusPageIncident` | Incident resolved | Resolves public status page incident. |

### Scheduled Maintenance Tasks (console.php)

In addition to jobs, `routes/console.php` defines 30+ scheduled tasks:

| Task | Schedule | Purpose |
|------|----------|---------|
| Maintenance windows | Every minute | Process starting/ending maintenance windows |
| Expired backup cleanup | Daily | Delete expired, unlocked backups |
| Expired app backup cleanup | Daily at 4:00 AM | Clean expired application backups |
| Security cleanup | Daily | Clean expired IP rules, old audit logs (90d), old blocked requests (30d) |
| Rollback cleanup | Daily | Clean expired rollback points |
| Activity log purge | Daily at 4:00 AM | Delete records older than 180 days |
| Performance test purge | Daily at 4:10 AM | Delete records older than 90 days |
| Resource check prune | Daily | Delete records older than 90 days |
| Uptime check prune | Daily | Remove checks older than 90 days |
| Failed jobs prune | Daily | Prune failed jobs older than 7 days |
| VACUUM ANALYZE | Weekly (Sunday 3 AM) | PostgreSQL maintenance |
| Horizon snapshot | Every 5 minutes | Queue metrics snapshot |
| Horizon health check | Every 5 minutes | Alert if Horizon is not running |
| Favicon backfill | Daily | Fetch missing favicons |
| Screenshot refresh | Weekly (Sunday 3 AM) | Refresh 10 oldest screenshots |

---

## 7. Livewire Components

66 Livewire components organized across modules.

### Reusable Traits (`app/Livewire/Traits/`)

| Trait | Purpose |
|-------|---------|
| `WithJobTracking` | Tracks background job progress via cache. Properties: job tracking keys. Methods: `initJobTracking()`, `dispatchTrackedJob()`, `checkJobProgress()`, `onJobFinished()`. Uses `wire:poll` to update UI. Abstract method: `jobTrackingKeys()`. |
| `WithSorting` | URL-based column sorting with toggle direction. Properties: `$sortBy`, `$sortDir` (both `#[Url]`). Methods: `sort()`, `applySorting()`. |
| `WithTableFilters` | URL-based search/filter with pagination. Properties: `$search`, `$filter` (both `#[Url]`). Uses `WithPagination`. Methods: `applySearch()`. |
| `WithSiteAuthorization` | Site access authorization. Methods: `authorizeSiteAccess()`, `authorizeSiteModification()`. Checks user ownership or admin role. |

### Dashboard (`app/Livewire/Dashboard/`)

| Component | Route | Purpose |
|-----------|-------|---------|
| `GlobalDashboard` | `GET /` | Main dashboard with stats, alerts, site overview |
| `WidgetDashboard` | `GET /dashboard/widgets` | Customizable dashboard with draggable widgets. Methods: `saveLayout()`, `addWidget()`, `removeWidget()`, `toggleWidgetVisibility()`, `resetToDefaults()` |
| `GlobalUpdates` | `GET /updates` | Pending updates across all sites |
| `GlobalActivity` | `GET /activity` | Recent activity stream |
| `GlobalErrors` | `GET /errors` | System-wide error aggregation |

### Dashboard Widgets (`app/Livewire/Dashboard/Widgets/`)

| Widget | Purpose |
|--------|---------|
| `BaseWidget` | Abstract base class for all widgets |
| `StatsOverviewWidget` | Key metrics: total sites, uptime %, backup status |
| `AlertCenterWidget` | Critical alerts grouped by severity |
| `BackupStatusWidget` | Backup statistics and recent failures |
| `SitesNeedingAttentionWidget` | Sites with low health scores |
| `HealthDistributionWidget` | Health score distribution chart |
| `RecentActivityWidget` | Activity timeline |
| `QuickActionsWidget` | Common action shortcuts |

### Sites (`app/Livewire/Sites/`)

| Component | Route | Purpose |
|-----------|-------|---------|
| `SitesList` | `GET /sites` (redirects to `/`) | Browse all sites with search/filter/pagination. Filters: all, healthy, warning, critical. Uses `WithTableFilters`. |
| `CreateSite` | `GET /sites/create` | Add sites. Modes: `connect` (single), `bulk` (multiple). Validates unique URLs. Auto-creates monitors. |

### Site Detail Pages (`app/Livewire/Sites/Detail/`)

All site detail components receive a `Site` model via route parameter.

| Component | Route | Purpose |
|-----------|-------|---------|
| `SiteOverview` | `GET /sites/{site}` | Main site dashboard. Analytics (28d selectable), updates, backups, reports. Uses `WithJobTracking`. Methods: `syncNow()`, `runBackup()`, `updateAll()`, `openWpAdmin()`, `saveCredentials()`. |
| `SitePlugins` | `GET /sites/{site}/plugins` | Plugin management with bulk updates, abandonment checks. |
| `SiteUpdates` | `GET /sites/{site}/updates` | Core/plugin/theme updates with safe update option. |
| `SiteSecurity` | `GET /sites/{site}/security` | Security scan results (score 0-100), category breakdown, issue remediation. |
| `SiteCoreIntegrity` | `GET /sites/{site}/core-integrity` | WordPress core file integrity results. |
| `SiteAuditLog` | `GET /sites/{site}/audit-log` | WordPress audit log viewing and export. |
| `SiteFirewall` | `GET /sites/{site}/firewall` | IP firewall rules and blocked requests. |
| `SitePerformance` | `GET /sites/{site}/performance` | PageSpeed metrics, Core Web Vitals, trends, opportunities. |
| `SiteBackups` | `GET /sites/{site}/backups` | Backup history, create manual, restore, schedule config. |
| `SiteUptime` | `GET /sites/{site}/uptime` | Uptime monitoring stats and incident history. |
| `SiteLinks` | `GET /sites/{site}/links` | Link scan results with broken link details. |
| `SiteAnalytics` | `GET /sites/{site}/analytics` | Google Analytics data display (users, sessions, pageviews, sources). |
| `SiteSearchConsole` | `GET /sites/{site}/search-console` | Search Console data (clicks, impressions, position, CTR). |
| `SiteMaintenance` | `GET /sites/{site}/maintenance` | Maintenance window scheduling and management. |
| `SiteCronManager` | `GET /sites/{site}/cron` | WordPress cron job management (list, run, disable, enable). |
| `SiteDns` | `GET /sites/{site}/dns` | DNS record inspection and email security scoring. |
| `SiteCloudflare` | `GET /sites/{site}/cloudflare` | Cloudflare management (DNS, cache, firewall, WAF, security level). |
| `SiteErrorLogs` | `GET /sites/{site}/errors` | PHP error log viewing and resolution. |
| `SiteDatabaseCleanup` | `GET /sites/{site}/database` | Database cleanup tools and health stats. |
| `SiteResources` | `GET /sites/{site}/resources` | Server resource monitoring (CPU, memory, disk). |
| `SiteSeo` | `GET /sites/{site}/seo` | SEO audit results and recommendations. |
| `SiteWooCommerce` | `GET /sites/{site}/woocommerce` | WooCommerce metrics (revenue, orders, stock alerts). |
| `SiteReports` | `GET /sites/{site}/reports` | Report generation, scheduling, and sending. |

### Site Detail Sub-Components (`app/Livewire/Sites/Detail/Components/`)

| Component | Purpose |
|-----------|---------|
| `BackupScheduleForm` | Configure backup schedule (frequency, time, retention, storage). |
| `RestoreConfirmation` | Confirm restore action with details. |

### Clients (`app/Livewire/Clients/`)

| Component | Route | Purpose |
|-----------|-------|---------|
| `ClientsList` | `GET /clients` | Browse clients with search/filter. |
| `ClientDetail` | `GET /clients/{client}` | Client details and associated sites. |
| `ClientForm` | `GET /clients/create`, `GET /clients/{client}/edit` | Create/edit client form. |

### Settings (`app/Livewire/Settings/`)

| Component | Route | Purpose |
|-----------|-------|---------|
| `GeneralSettings` | `GET /settings` | Application-wide configuration. |
| `ProfileSettings` | `GET /settings/profile` | User profile, password, 2FA setup. |
| `NotificationSettings` | `GET /settings/notifications` | Notification channel configuration (email, Slack, Discord, Telegram, webhook). |
| `IntegrationsSettings` | `GET /settings/integrations` | Third-party API connections (Google, Cloudflare, Dropbox). |
| `ReportTemplatesSettings` | `GET /settings/report-templates` | Customize report templates and sections. |
| `ApplicationBackup` | `GET /settings/application-backup` | Application database backup and restore. |

### Settings Sub-Components (`app/Livewire/Settings/Components/`)

| Component | Purpose |
|-----------|---------|
| `ChannelForm` | Configure notification channels. |
| `StorageDestinationForm` | Configure backup storage destinations. |

### Other Modules

| Component | Route | Purpose |
|-----------|-------|---------|
| `BackupsOverview` | `GET /backups` | Global backup statistics and management. |
| `PerformanceOverview` | `GET /performance` | Performance metrics across all sites. |
| `UptimeOverview` | `GET /uptime` | Uptime statistics and incident history. |
| `ConfigureMonitor` | Modal/nested | Configure uptime monitoring settings. |
| `ReportsOverview` | `GET /reports` | Report archive and scheduling management. |
| `StatusPagesList` | `GET /status-pages` | Browse public status pages. |
| `StatusPageEdit` | `GET /status-pages/create`, `GET /status-pages/{statusPage}/edit` | Create/edit status pages with branding. |

### Reusable Livewire Components (`app/Livewire/Components/`)

| Component | Purpose |
|-----------|---------|
| `SiteCard` | Reusable site card with quick metrics. |
| `UptimeBar` | Visual uptime indicator bar. |
| `UptimeStatsCard` | Uptime statistics display. |
| `NotificationDropdown` | Header notification center dropdown. |
| `ResponseTimeChart` | Real-time response time charting. |

---

## 8. Routes

All routes are defined in `routes/web.php` and `routes/auth.php`.

### Public Routes (no auth)

| Method | Path | Handler | Rate Limit |
|--------|------|---------|------------|
| `GET` | `/health` | `HealthCheckController` | 30/min |
| `GET` | `/status/{slug}` | `StatusPageController@__invoke` | status-page (30/min) |
| `POST` | `/status/{slug}/auth` | `StatusPageController@authenticate` | login (5/min) |
| `GET` | `/api/status/{slug}` | `StatusPageController@api` | status-page (30/min) |

### Authenticated Routes (auth + verified middleware)

**Dashboard**

| Method | Path | Name | Component |
|--------|------|------|-----------|
| `GET` | `/` | `dashboard` | `Dashboard\GlobalDashboard` |
| `GET` | `/dashboard/widgets` | `dashboard.widgets` | `Dashboard\WidgetDashboard` |

**Sites**

| Method | Path | Name | Component |
|--------|------|------|-----------|
| `GET` | `/sites/create` | `sites.create` | `Sites\CreateSite` |
| `GET` | `/sites/{site}` | `sites.overview` | `Sites\Detail\SiteOverview` |
| `GET` | `/sites/{site}/plugins` | `sites.plugins` | `Sites\Detail\SitePlugins` |
| `GET` | `/sites/{site}/updates` | `sites.updates` | `Sites\Detail\SiteUpdates` |
| `GET` | `/sites/{site}/security` | `sites.security` | `Sites\Detail\SiteSecurity` |
| `GET` | `/sites/{site}/core-integrity` | `sites.core-integrity` | `Sites\Detail\SiteCoreIntegrity` |
| `GET` | `/sites/{site}/audit-log` | `sites.audit-log` | `Sites\Detail\SiteAuditLog` |
| `GET` | `/sites/{site}/firewall` | `sites.firewall` | `Sites\Detail\SiteFirewall` |
| `GET` | `/sites/{site}/performance` | `sites.performance` | `Sites\Detail\SitePerformance` |
| `GET` | `/sites/{site}/backups` | `sites.backups` | `Sites\Detail\SiteBackups` |
| `GET` | `/sites/{site}/uptime` | `sites.uptime` | `Sites\Detail\SiteUptime` |
| `GET` | `/sites/{site}/links` | `sites.links` | `Sites\Detail\SiteLinks` |
| `GET` | `/sites/{site}/analytics` | `sites.analytics` | `Sites\Detail\SiteAnalytics` |
| `GET` | `/sites/{site}/search-console` | `sites.search-console` | `Sites\Detail\SiteSearchConsole` |
| `GET` | `/sites/{site}/maintenance` | `sites.maintenance` | `Sites\Detail\SiteMaintenance` |
| `GET` | `/sites/{site}/cron` | `sites.cron` | `Sites\Detail\SiteCronManager` |
| `GET` | `/sites/{site}/dns` | `sites.dns` | `Sites\Detail\SiteDns` |
| `GET` | `/sites/{site}/cloudflare` | `sites.cloudflare` | `Sites\Detail\SiteCloudflare` |
| `GET` | `/sites/{site}/errors` | `sites.errors` | `Sites\Detail\SiteErrorLogs` |
| `GET` | `/sites/{site}/database` | `sites.database` | `Sites\Detail\SiteDatabaseCleanup` |
| `GET` | `/sites/{site}/resources` | `sites.resources` | `Sites\Detail\SiteResources` |
| `GET` | `/sites/{site}/seo` | `sites.seo` | `Sites\Detail\SiteSeo` |
| `GET` | `/sites/{site}/woocommerce` | `sites.woocommerce` | `Sites\Detail\SiteWooCommerce` |
| `GET` | `/sites/{site}/reports` | `sites.reports` | `Sites\Detail\SiteReports` |

**Global Views**

| Method | Path | Name | Component |
|--------|------|------|-----------|
| `GET` | `/backups` | `backups.index` | `Backups\BackupsOverview` |
| `GET` | `/performance` | `performance.index` | `Performance\PerformanceOverview` |
| `GET` | `/uptime` | `uptime.index` | `Uptime\UptimeOverview` |
| `GET` | `/updates` | `updates.index` | `Dashboard\GlobalUpdates` |
| `GET` | `/activity` | `activity.index` | `Dashboard\GlobalActivity` |
| `GET` | `/errors` | `errors.index` | `Dashboard\GlobalErrors` |
| `GET` | `/reports` | `reports.index` | `Reports\ReportsOverview` |

**Clients**

| Method | Path | Name | Component |
|--------|------|------|-----------|
| `GET` | `/clients` | `clients.index` | `Clients\ClientsList` |
| `GET` | `/clients/create` | `clients.create` | `Clients\ClientForm` |
| `GET` | `/clients/{client}` | `clients.show` | `Clients\ClientDetail` |
| `GET` | `/clients/{client}/edit` | `clients.edit` | `Clients\ClientForm` |

**Status Pages**

| Method | Path | Name | Component |
|--------|------|------|-----------|
| `GET` | `/status-pages` | `status-pages.index` | `StatusPages\StatusPagesList` |
| `GET` | `/status-pages/create` | `status-pages.create` | `StatusPages\StatusPageEdit` |
| `GET` | `/status-pages/{statusPage}/edit` | `status-pages.edit` | `StatusPages\StatusPageEdit` |

**Settings**

| Method | Path | Name | Handler |
|--------|------|------|---------|
| `GET` | `/settings` | `settings.general` | `Settings\GeneralSettings` |
| `GET` | `/settings/notifications` | `settings.notifications` | `Settings\NotificationSettings` |
| `GET` | `/settings/profile` | `settings.profile` | `Settings\ProfileSettings` |
| `GET` | `/settings/integrations` | `settings.integrations` | `Settings\IntegrationsSettings` |
| `GET` | `/settings/report-templates` | `settings.report-templates` | `Settings\ReportTemplatesSettings` |
| `GET` | `/settings/application-backup` | `settings.application-backup` | `Settings\ApplicationBackup` |
| `GET` | `/settings/storage/dropbox/auth` | `dropbox.auth` | `DropboxAuthController@redirect` |
| `GET` | `/settings/storage/dropbox/callback` | `dropbox.callback` | `DropboxAuthController@callback` |
| `GET` | `/settings/google/auth` | `google.auth` | `GoogleAuthController@redirect` |
| `GET` | `/settings/google/callback` | `google.callback` | `GoogleAuthController@callback` |

**Downloads**

| Method | Path | Name | Handler | Middleware |
|--------|------|------|---------|------------|
| `GET` | `/backups/{backup}/download` | `backups.download` | `BackupDownloadController` | signed |
| `GET` | `/reports/{report}/download` | `reports.download` | `ReportDownloadController` | - |
| `GET` | `/settings/app-backups/{appBackup}/download` | `app-backups.download` | `AppBackupDownloadController` | signed |
| `GET` | `/download/connector-plugin` | `download.connector-plugin` | Closure (returns zip) | - |

---

## 9. Middleware

4 custom middleware registered in `bootstrap/app.php`.

### SecurityHeaders (`app/Http/Middleware/SecurityHeaders.php`)

**Position**: Prepended to web middleware stack.

Sets security headers on all responses:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Content-Security-Policy` with `'unsafe-inline'` for scripts/styles
- `Strict-Transport-Security` (HSTS) on secure requests only

### StatusPageCustomDomain (`app/Http/Middleware/StatusPageCustomDomain.php`)

**Position**: Prepended to web middleware stack.

Detects requests to custom domain status pages (non-application host):
- Looks up `StatusPage` by `custom_domain`
- Returns JSON API for `/api` path
- Renders status page view for `/` path
- Handles POST auth for password-protected pages
- Short-circuits normal routing when custom domain is detected

### SetLocale (`app/Http/Middleware/SetLocale.php`)

**Position**: Appended to web middleware stack.

- Gets authenticated user's `language` preference (default: `'en'`)
- Supports: `'en'`, `'ro'`
- Sets `App::setLocale()` and `Carbon::setLocale()`

### SetCurrentSite (`app/Http/Middleware/SetCurrentSite.php`)

**Position**: Appended to web middleware stack. Also aliased as `site.context`.

- Extracts `{site}` route parameter
- Resolves `Site::findOrFail()` if needed
- Shares `$siteContext` with all Blade views via `View::share()`
- Merges `currentSite` into the request object

---

## 10. WordPress Connector Plugin

**Location**: `wordpress-plugin/simplead-manager-connector/`
**Version**: 1.2.0
**REST Namespace**: `simplead/v1`

### Architecture

```
simplead-manager-connector.php    # Bootstrap: constants, activation hooks, autoloader
includes/
├── class-authentication.php      # HMAC-SHA256 request validation
├── class-rate-limiter.php        # Per-key rate limiting
├── class-audit-logger.php        # WordPress action audit logging
├── class-login-handler.php       # One-time login token generation
├── class-admin.php               # WordPress admin UI (dashboard + connection tabs)
├── class-rest-api.php            # REST API route registration
└── endpoints/                    # 18 REST API endpoint classes
    ├── class-info-endpoint.php
    ├── class-health-endpoint.php
    ├── class-plugins-endpoint.php
    ├── class-themes-endpoint.php
    ├── class-core-endpoint.php
    ├── class-security-endpoint.php
    ├── class-backup-endpoint.php
    ├── class-database-endpoint.php
    ├── class-monitoring-endpoint.php
    ├── class-cron-endpoint.php
    ├── class-firewall-endpoint.php
    ├── class-audit-endpoint.php
    ├── class-login-endpoint.php
    ├── class-rollback-endpoint.php
    ├── class-error-log-endpoint.php
    ├── class-seo-endpoint.php
    ├── class-users-endpoint.php
    └── class-woocommerce-endpoint.php
```

### Authentication (HMAC-SHA256)

Every API request must include three headers:
- `X-SAM-Key` - API key (32 characters)
- `X-SAM-Timestamp` - Unix timestamp (must be within 5 minutes)
- `X-SAM-Signature` - HMAC-SHA256 signature

**String to sign**: `METHOD|PATH|TIMESTAMP|BODY`

**Validation flow**:
1. Check `X-SAM-Key` matches stored `sam_api_key` option
2. Verify timestamp is within 5-minute window (replay attack prevention)
3. Compute HMAC-SHA256 of string-to-sign using `sam_api_secret` (64 chars)
4. Compare signature using `hash_equals()` (timing-safe)

**Credentials**: Generated on plugin activation, stored as WordPress options. Can be regenerated via admin UI.

### Rate Limiting

- **General**: 60 requests/minute per API key
- **Destructive operations**: 5 requests/minute
- **Destructive routes**: `backup/db`, `backup/files`, `security-fix`, `db-cleanup-run`, `plugins/*`, `cron/*`, `ip-rules/sync`
- **Storage**: WordPress transients (in-memory counters)

### REST API Endpoints

All endpoints are under `/wp-json/simplead/v1/`. Each extends `SAM_Endpoint_Base` which provides `check_permission()` (rate limit + HMAC), `success()`, and `error()` methods.

| Endpoint Class | Method | Path | Purpose |
|----------------|--------|------|---------|
| Info | `GET` | `/info` | WordPress version, PHP, MySQL, timezone, language, debug mode, memory limit |
| Health | `GET` | `/health` | Database, uploads, PHP/WP version, SSL, cron status, update counts |
| Plugins | `GET` | `/plugins` | List all plugins with status and update availability |
| Plugins | `POST` | `/plugins/update` | Bulk update plugins |
| Plugins | `POST` | `/plugins/activate` | Activate a plugin |
| Plugins | `POST` | `/plugins/deactivate` | Deactivate a plugin |
| Plugins | `POST` | `/plugins/delete` | Delete a plugin (deactivates first). Path validation: no traversal, no null bytes, must be known plugin. |
| Themes | `GET` | `/themes` | List themes with status and updates |
| Themes | `POST` | `/themes/update` | Update themes |
| Themes | `POST` | `/themes/activate` | Switch active theme |
| Themes | `POST` | `/themes/delete` | Remove a theme |
| Core | `POST` | `/core/update` | Update WordPress core with audit logging |
| Security | `GET` | `/security-check` | 12-point security audit (file permissions, debug mode, default admin, SSL, file editor, DB prefix, XML-RPC, etc.) |
| Security | `POST` | `/security-fix` | Apply fixable security issues (disable file editor, disable XML-RPC). Atomic file writes with `.bak` backup. |
| Security | `GET` | `/core-integrity-check` | MD5 hash verification against WordPress.org checksums |
| Backup | `POST` | `/backup/db` | Database backup (mysqldump with PHP fallback, proc_open) |
| Backup | `POST` | `/backup/files` | wp-content backup (tar with ZipArchive fallback) |
| Database | `GET` | `/database-health` | Table stats (size, rows, overhead) |
| Database | `GET` | `/db-cleanup-stats` | Count cleanable items |
| Database | `POST` | `/db-cleanup-run` | Clean revisions, auto-drafts, trash, spam, transients, orphaned meta |
| Monitoring | `GET` | `/server-resources` | CPU, memory, disk, load average, uptime (reads /proc/) |
| Cron | `GET` | `/cron-list` | Scheduled hooks with timestamps and intervals |
| Cron | `POST` | `/cron-run` | Execute hook immediately |
| Cron | `POST` | `/cron-disable` | Disable a cron hook |
| Cron | `POST` | `/cron-enable` | Re-enable with schedule |
| Firewall | `POST` | `/ip-rules/sync` | Sync IP rules (allow/block). Validates IPs/CIDR. Atomic .htaccess writes. |
| Firewall | `GET` | `/blocked-requests` | Recent blocked requests |
| Audit | `GET` | `/audit-logs` | Get logs with optional `since` filter |
| Login | `POST` | `/login-url` | Generate one-time login URL (2-min expiry, SHA-256 stored, single-use) |
| Rollback | `POST` | `/rollback` | Plugin rollback |
| Error Log | `GET` | `/error-logs` | PHP error log contents |
| SEO | `GET` | `/seo-check` | SEO checks |
| Users | `GET` | `/users` | WordPress user list |
| WooCommerce | `GET` | `/woo/stats` | Revenue, orders, avg order value, products, customers |
| WooCommerce | `GET` | `/woo/low-stock` | Products with low stock |
| WooCommerce | `GET` | `/woo/out-of-stock` | Out of stock products |

### Audit Logger

- Hooks into WordPress actions: `wp_login`, `activate_plugin`, `deactivate_plugin`, `transition_post_status`, `switch_theme`, `_core_updated_successfully`
- Batch inserts on shutdown for performance
- Custom DB table (`{prefix}sam_audit_log`) with indexes on `created_at` and `action`
- Auto-purges entries older than 90 days (daily cron)
- IP detection: Cloudflare `CF-Connecting-IP` → `X-Forwarded-For` → `REMOTE_ADDR`

### Login Handler

- Generates one-time login tokens via `/login-url` endpoint
- Token stored as SHA-256 hash in `sam_login_tokens` option
- 2-minute expiry, single-use
- Requires admin capabilities on target user
- Hooks into `init` to check for `sam_login_token` query parameter
- On success: logs in user, redirects to `/wp-admin/`

### Admin Interface

- Adds menu page "SAM Connector" to WordPress admin
- **Dashboard tab**: Health checks, server resources, security scan results
- **Connection tab**: API credentials display, copy, regenerate
- AJAX handlers for all admin operations

---

## 11. Frontend

### Build System

- **Vite** (`vite.config.js`) for asset bundling
- **Entry points**: `resources/css/app.css`, `resources/js/app.js`
- Dev server with HMR support

### Tailwind CSS Configuration (`tailwind.config.js`)

- **Version**: 3.1.0
- **Dark mode**: Enabled (class strategy)
- **Custom colors**: sidebar `#1A1A2E`, accent purple `#8D5CF5`
- **Font**: Inter var
- **Plugins**: `@tailwindcss/forms`
- **Content paths**: `resources/views/**/*.blade.php`, `app/Livewire/**/*.php`

### JavaScript Dependencies (`package.json`)

| Package | Version | Purpose |
|---------|---------|---------|
| Alpine.js | 3.4.2 | Reactive UI interactions |
| Chart.js | 4.5.1 | Data visualization (line, bar, donut charts) |
| SortableJS | 1.15.6 | Drag-and-drop for dashboard widgets |
| Tailwind CSS | 3.1.0 | Utility-first CSS |
| Vite | - | Asset bundling |

### Blade Component Library

100+ Blade components in `resources/views/components/`:

**Layouts** (`components/layouts/`)

| Component | Purpose |
|-----------|---------|
| `app.blade.php` | Main authenticated app layout with sidebar |
| `guest.blade.php` | Auth page layout (login, register) |
| `status-page.blade.php` | Public status page layout |

**UI Components** (`components/ui/`)

| Component | Props | Purpose |
|-----------|-------|---------|
| `card` | - | White rounded container with ring-1 shadow |
| `button` | variant (primary/secondary/danger/ghost), size (sm/md/lg) | Button with variants |
| `modal` | - | Alpine.js modal with backdrop, keyboard escape |
| `job-progress` | - | Real-time job status indicator |
| `table`, `th`, `td` | - | Table markup wrappers |
| `alert` | type | Alert banners |
| `badge` | color | Status badges |
| `tooltip` | - | Hover tooltips |
| `dropdown` | - | Dropdown menus |
| `filter-tabs` | - | Tab-based filtering |
| `empty-state` | - | Empty state illustrations |
| `input`, `select`, `checkbox`, `toggle` | - | Form inputs |
| `form-group` | - | Form field wrapper |
| `search-input` | - | Search input with icon |
| `date-range-selector` | - | Date range picker |
| `stat-card` | - | Stat display card |
| `progress-bar` | - | Progress indicator |
| `spinner` | - | Loading spinner |
| `collapsible` | - | Expandable section |
| `hovercard` | - | Hover-triggered card |
| `page-header` | - | Page title + actions |
| `flash-alert` | - | Session flash message display |

**Charts** (`components/charts/`)

| Component | Purpose |
|-----------|---------|
| `line-chart` | Time series line charts (Chart.js) |
| `bar-chart` | Bar charts (Chart.js) |
| `donut-chart` | Donut/pie charts (Chart.js) |
| `sparkline` | Inline sparkline charts |

**Performance** (`components/performance/`)

| Component | Purpose |
|-----------|---------|
| `score-gauge` | Circular score indicator (0-100) |
| `metric-item` | Core Web Vital metric display |
| `wp-health` | WordPress health status |
| `third-party-table` | Third-party script analysis |
| `budget-status` | Performance budget status |
| `image-audit` | Image optimization audit |
| `dom-info` | DOM complexity info |
| `filmstrip` | Page load filmstrip |
| `unused-code` | Unused code analysis |
| `page-selector` | Multi-page test selector |

**Security** (`components/security/`)

| Component | Purpose |
|-----------|---------|
| `ssl-card` | SSL certificate status |
| `domain-card` | Domain expiry status |
| `core-integrity-card` | Core file integrity status |

**Sidebar** (`components/sidebar/`)

| Component | Purpose |
|-----------|---------|
| `global-sidebar` | Main navigation sidebar |
| `site-sidebar` | Site-specific navigation sidebar |
| `sidebar-item` | Individual sidebar link item |
| `sidebar-section` | Sidebar section grouping |

**Dashboard** (`components/dashboard/`)

| Component | Purpose |
|-----------|---------|
| `widget-container` | Widget wrapper with drag handle |
| `widget-skeleton` | Loading skeleton for widgets |
| `chart-container` | Chart wrapper with title |
| `period-selector` | Time period dropdown |
| `site-row` | Site summary row in dashboard |

**Hovercards** (`components/hovercards/`)

12 hover card components: analytics, backup, domain, links, plugins, reports, response-time, ssl, uptime, users, wordpress, wp-version.

**Icons** (`components/icons/`)

27 SVG icon components: activity, alert-triangle, arrow-left, bar-chart-2, bell, check-circle, chevron-down, clock, cloud, database, file-search, file-text, globe, hard-drive, home, inbox, layers, layout-dashboard, link, log-out, mail, menu, plus, puzzle, refresh-cw, search, settings, shield-alert, shield, shopping-cart, users, wrench, x-circle, x, zap.

**Other**

| Component | Purpose |
|-----------|---------|
| `client-avatar` | Client avatar with initials fallback |
| `site-favicon` | Site favicon display |
| `scripts/data-table` | Alpine.js data table functionality |

### Mail Templates (`resources/views/mail/`)

Alert email templates: uptime-alert, ssl-alert, domain-alert, backup-alert, broken-links-alert, report-generated.

### Report Templates (`resources/views/reports/`)

PDF report sections: cover, intro, overview, updates, uptime, backups, analytics, search-console, performance, links.

---

## 12. Integrations

### Cloudflare

- **Service**: `CloudflareService` takes `CloudflareConnection` model in constructor
- **Auth**: API token stored encrypted in `cloudflare_connections` table
- **Rate limit**: 200 requests/minute
- **Cache pattern**: `Cache::remember("cf:{connectionId}:key", TTL, fn)`, `Cache::forget()` after mutations
- **Features**: DNS management, cache purging (all/URLs/tags/prefix), security level, firewall rules, WAF, IP blocking, SSL mode, analytics

### Google Analytics (GA4)

- **Service**: `GoogleAnalyticsService` extends `GoogleApiService`
- **Auth**: OAuth 2.0 with automatic token refresh via `GoogleConnection` model
- **API**: GA4 Data API (`analyticsdata.googleapis.com/v1beta`)
- **Data**: Users, sessions, pageviews, traffic sources, top pages, devices, countries, demographics, realtime
- **Caching**: Results cached in `analytics_cache` table

### Google Search Console

- **Service**: `GoogleSearchConsoleService` extends `GoogleApiService`
- **Auth**: OAuth 2.0 (shared `GoogleConnection`)
- **Data**: Clicks, impressions, average position, CTR, top queries, top pages, URL inspection
- **Keyword tracking**: `TrackedKeyword` + `KeywordPosition` models for rank tracking
- **Caching**: Results cached in `search_console_cache` table

### Dropbox

- **Auth**: OAuth 2.0 via `DropboxAuthController`
- **Purpose**: Backup storage destination
- **Driver**: `DropboxDriver` implements `StorageDriver`
- **Config**: App key/secret stored in DB or .env

### Notification Channels

| Channel | Sender | Config |
|---------|--------|--------|
| Email | `EmailNotificationSender` | Default Laravel mail |
| Slack | `SlackNotificationSender` | Webhook URL |
| Discord | `DiscordNotificationSender` | Webhook URL |
| Telegram | `TelegramNotificationSender` | Bot token + chat ID |
| Webhook | `WebhookNotificationSender` | Custom URL + headers |

Notifications dispatched via `NotificationService::notifyAppEvent()` which routes to all active default channels.

### PageSpeed Insights

- **Service**: `PageSpeedService`
- **API**: Google PageSpeed Insights API v5
- **Config**: API key in `config/services.php`
- **Data**: Performance/accessibility/best-practices/SEO scores, Core Web Vitals, opportunities, diagnostics

---

## 13. Authorization & Security

### Authentication Flow

1. **Login**: Laravel Breeze session-based auth (`/login`)
2. **Email verification**: Required (`verified` middleware)
3. **Two-factor authentication**: Optional TOTP + recovery codes
   - Setup: `ProfileSettings` component
   - Secrets stored encrypted: `two_factor_secret`, `two_factor_recovery_codes`
4. **Session**: Stored in database/Redis
5. **Rate limits**: 5 login attempts/minute per email+IP

### Authorization

**Admin role**: `User.is_admin` boolean field.

**Policies** (`app/Policies/`):

| Policy | Resource | Rules |
|--------|----------|-------|
| `SitePolicy` | Site | viewAny (all), view (owner or admin), create (all), update (owner or admin), delete (owner or admin) |
| `ClientPolicy` | Client | viewAny (all), view (has sites or admin), create (all), update (has sites or admin), delete (admin only) |
| `BackupPolicy` | Backup | view/create/delete/restore (site owner or admin) |
| `StatusPagePolicy` | StatusPage | viewAny (all), view/update/delete (owner or admin), create (all) |

**Livewire trait**: `WithSiteAuthorization` provides `authorizeSiteAccess()` and `authorizeSiteModification()` methods used by site detail components.

**Horizon access**: Gated to admin users only via `HorizonServiceProvider` (`viewHorizon` gate).

### Security Headers

The `SecurityHeaders` middleware sets:
- CSP (Content Security Policy)
- HSTS (HTTP Strict Transport Security) on HTTPS
- X-Frame-Options, X-Content-Type-Options, X-XSS-Protection
- Referrer-Policy
- Permissions-Policy

### WordPress API Security

- HMAC-SHA256 authentication on all API calls
- 5-minute timestamp window (replay attack prevention)
- Timing-safe comparisons (`hash_equals()`)
- Rate limiting (60/min general, 5/min destructive)
- Path validation (no directory traversal, no null bytes)
- Atomic file writes with `.bak` backups
- One-time login tokens (2-min expiry, single-use, SHA-256 hashed storage)

### Data Encryption

- Site API credentials: `api_key`, `api_secret`, `api_endpoint` (Laravel encrypted)
- Google OAuth tokens: `access_token`, `refresh_token` (encrypted)
- Cloudflare API tokens: `api_token` (encrypted)
- 2FA secrets: `two_factor_secret`, `two_factor_recovery_codes` (encrypted)
- Uptime monitor auth: `auth_password`, `auth_token` (encrypted)

### Exception Handling (`bootstrap/app.php`)

- JSON responses for API consumers on 401/403/404 errors
- Generic error message in production (no stack traces leaked)
- All unhandled exceptions logged with class, message, file, line

---

## 14. Deployment

### Docker Compose Configuration

7 containers defined in `docker-compose.prod.yml`:

| Container | Image | Role | Resources |
|-----------|-------|------|-----------|
| `simplead-app` | `simplead-app:latest` (PHP 8.3-FPM Alpine) | Main Laravel application | 512 MB RAM, 1.0 CPU |
| `simplead-scheduler` | Same image | `php artisan schedule:work` | 256 MB RAM |
| `simplead-horizon` | Same image | Queue worker (Horizon) | 384 MB RAM |
| `simplead-nginx` | Nginx Alpine | Reverse proxy + SSL termination | 128 MB RAM |
| `simplead-pgsql` | PostgreSQL 16 Alpine | Database (shared_buffers=256MB, max_connections=100) | 512 MB RAM |
| `simplead-redis` | Redis 7 Alpine | Cache + queue backend (256MB maxmemory, allkeys-lru) | 320 MB RAM |
| `simplead-certbot` | Certbot | Let's Encrypt SSL renewal | On-demand |

### PHP Extensions

The Docker image includes: bcmath, exif, gd, intl, mbstring, opcache, pcntl, pdo_pgsql, pgsql, xml, zip, imagick, redis.

### Volume Mounts

Only `storage/` is volume-mounted to persist across container recreations. Application code is baked into the Docker image at `/var/www/html/`.

### Code Deployment Workflow

Since code is baked into the image, hot-deploying changes requires:

```bash
# 1. Copy changed files to all 3 app containers
docker cp app/Services/MyService.php simplead-app:/var/www/html/app/Services/MyService.php
docker cp app/Services/MyService.php simplead-scheduler:/var/www/html/app/Services/MyService.php
docker cp app/Services/MyService.php simplead-horizon:/var/www/html/app/Services/MyService.php

# 2. Reload PHP-FPM (graceful restart)
docker exec simplead-app kill -USR2 1

# 3. Clear caches
docker exec simplead-app php artisan view:clear
docker exec simplead-app php artisan config:clear
docker exec simplead-app php artisan route:clear

# 4. Restart Horizon for job changes
docker restart simplead-horizon

# 5. Restart scheduler for schedule changes
docker restart simplead-scheduler
```

For full deployments, rebuild the Docker image:

```bash
# Build new image
docker build -f docker/php/Dockerfile.prod -t simplead-app:latest .

# Recreate containers
docker compose -f docker-compose.prod.yml up -d
```

### Database

- **Engine**: PostgreSQL 16
- **Maintenance**: Weekly `VACUUM ANALYZE` (scheduled Sunday 3 AM)
- **Connection**: Configured in `config/database.php`, default driver `pgsql`

### Queue System

- **Driver**: Redis via Laravel Horizon
- **Monitoring**: Horizon dashboard at `/horizon` (admin-only)
- **Health check**: Every 5 minutes, alerts if no supervisor processes found
- **Metrics**: Snapshot every 5 minutes
- **Failed job cleanup**: Daily, prune jobs older than 7 days

### Monitoring & Health

- **Health endpoint**: `GET /health` (throttled 30/min, no auth)
- **Horizon health check**: Every 5 minutes with critical notification if stopped
- **Job failure tracking**: Cache-based, notifies on 3rd failure within 1 hour
- **Long wait detection**: Horizon `LongWaitDetected` event triggers admin notification

---

*Last updated: February 2026*
