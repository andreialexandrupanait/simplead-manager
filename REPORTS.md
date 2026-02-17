# Reports System — Architecture & Reference

## Overview

The reports module generates branded PDF maintenance reports for client sites, with support for scheduled delivery, manual generation, customizable templates, and email distribution. Reports aggregate data from multiple sources (uptime, backups, analytics, search console, performance, updates) into a single PDF document.

---

## Data Model

### Report (`app/Models/Report.php`)

Stores each generated PDF report.

| Column | Type | Description |
|---|---|---|
| `site_id` | FK | Associated site |
| `report_template_id` | FK | Template used |
| `report_schedule_id` | FK (nullable) | Schedule that triggered it (null = manual) |
| `title` | string | Report title |
| `period_start` / `period_end` | date | Reporting period |
| `file_path` / `file_name` | string | PDF storage location |
| `file_size` / `page_count` | int | PDF metadata |
| `status` | enum | `pending` → `generating` → `completed` / `failed` |
| `trigger` | string | `scheduled` or `manual` |
| `was_sent` / `sent_at` / `sent_to` | mixed | Email delivery tracking |
| `data_snapshot` | JSON | Cached data at generation time |
| `error_message` | text | Error details on failure |
| `generated_at` | timestamp | Completion time |

**Relations:** `site()`, `reportTemplate()`, `reportSchedule()`

---

### ReportTemplate (`app/Models/ReportTemplate.php`)

Defines report structure and branding.

| Column | Type | Description |
|---|---|---|
| `name` / `description` | string | Template identity |
| `sections` | JSON | Enabled sections array |
| `company_name` / `company_logo_path` / `company_website` | string | Branding |
| `primary_color` | string | Hex color (default `#7C3AED`) |
| `intro_text` / `closing_text` | text | Custom copy |
| `is_default` | bool | Default template flag |

**Available sections:** `overview`, `updates`, `uptime`, `backups`, `analytics`, `search_console`, `performance`

**Relations:** `schedules()`, `reports()`

---

### ReportSchedule (`app/Models/ReportSchedule.php`)

Configures automatic report generation and delivery.

| Column | Type | Description |
|---|---|---|
| `site_id` / `report_template_id` | FK | Target site & template |
| `is_active` | bool | Enable/disable toggle |
| `frequency` | enum | `weekly` or `monthly` |
| `day_of_week` | int | 0-6 (0 = Sunday) |
| `day_of_month` | int | 1-28 |
| `time` | string | HH:MM (default `08:00`) |
| `timezone` | string | Default `Europe/Bucharest` |
| `period` | enum | `last_7_days`, `last_30_days`, `last_month` |
| `recipient_emails` | JSON | Delivery recipients |
| `send_copy_to_admin` | bool | CC admin user |
| `email_subject` / `email_body` | string | Custom email content |
| `client_name` / `client_logo_path` | string | Client branding override |
| `last_generated_at` / `last_sent_at` / `next_run_at` | timestamp | Scheduling metadata |

**Key method:** `calculateNextRun()` — computes next execution based on frequency/day/time/timezone.

**Relations:** `site()`, `reportTemplate()`, `reports()`

---

### SiteReportConfig (`app/Models/SiteReportConfig.php`)

Per-site report configuration (one-to-one with Site).

| Column | Type | Description |
|---|---|---|
| `site_id` | FK (unique) | One config per site |
| `language` | enum | `en` or `ro` |
| `show_security` / `show_cloudflare` | bool | Feature toggles |
| `custom_notes` | text | Per-site notes |

---

## Generation Pipeline

### 1. Trigger

Reports can be triggered two ways:

- **Manual:** User clicks "Generate" in `SiteReports` Livewire component. Rate-limited to 10/hour per user/site.
- **Scheduled:** `ReportDispatcher` runs every 5 minutes via `routes/console.php`, finds schedules where `next_run_at <= now()`, and dispatches jobs.

### 2. Job: `GenerateReport` (`app/Jobs/GenerateReport.php`)

| Setting | Value |
|---|---|
| Queue | `reports` |
| Timeout | 300s |
| Memory | 512MB |
| Tries | 2 |
| Backoff | 60s, 120s |
| Unique | Per `site_id` + `template_id` |

**Flow:**
1. Creates `Report` record with status `generating`
2. Calls `ReportGeneratorService->generate()` to produce PDF
3. Updates Report with file path, size, page count, data snapshot
4. Sends email to recipients (if configured)
5. Updates schedule timestamps and calculates next run
6. Logs activity via `ActivityLogger`
7. On failure: sets status to `failed` with error message

### 3. Service: `ReportGeneratorService` (`app/Services/ReportGeneratorService.php`)

Orchestrates data gathering and PDF rendering.

**Data gathering methods** (conditional on enabled template sections):

| Method | Data Source |
|---|---|
| `gatherOverviewData()` | Site info, update counts, uptime %, incidents, backups, performance scores, analytics, search console |
| `gatherUpdatesData()` | Core/plugin/theme updates, success/fail counts |
| `gatherUptimeData()` | Incidents, downtime minutes, response time charts, availability % |
| `gatherBackupsData()` | Backup schedule, frequency, count, total size, last backup |
| `gatherAnalyticsData()` | Users, sessions, pageviews, bounce rate, traffic sources, top pages, devices, countries |
| `gatherSearchConsoleData()` | Clicks, impressions, CTR, position, query/page performance |
| `gatherPerformanceData()` | PageSpeed scores (mobile/desktop), Core Web Vitals (FCP, LCP, CLS, TBT, SI) |

**PDF generation:** Renders Blade view via Barryvdh/DomPDF (A4 paper). Output stored at `storage/app/local/reports/{site_id}/report-{site_id}-{timestamp}.pdf`.

### 4. Dispatcher: `ReportDispatcher` (`app/Dispatchers/ReportDispatcher.php`)

- Called every 5 minutes from `routes/console.php`
- Finds active schedules where `next_run_at <= now()`
- Calculates period dates from schedule config
- Dispatches `GenerateReport` job
- Runs `withoutOverlapping()->onOneServer()`

---

## Email Delivery

### `ReportGeneratedMail` (`app/Mail/ReportGeneratedMail.php`)

- Custom subject from schedule config or default format
- Report details table (site, period, template, file size)
- Download link via `temporarySignedRoute` (7-day expiry)
- PDF file attached inline
- View: `resources/views/mail/report-generated.blade.php`

---

## PDF Template Structure

### Main layout: `resources/views/reports/maintenance-report.blade.php`
Includes styles, cover page, conditionally renders each section, header/footer on pages 2+.

### Styles: `resources/views/reports/styles.blade.php`
DomPDF-compatible CSS (~681 lines) — A4 layout, running headers/footers, typography, cards, tables, score circles, charts, branding colors.

### Partials (12 files in `resources/views/reports/partials/`):

| File | Content |
|---|---|
| `cover.blade.php` | Title page with company branding |
| `intro.blade.php` | Custom introduction text |
| `overview.blade.php` | Site status summary |
| `updates.blade.php` | WordPress/plugin/theme updates |
| `uptime.blade.php` | Availability % and incidents |
| `backups.blade.php` | Backup history |
| `analytics-1.blade.php` | Google Analytics data (page 1) |
| `analytics-2.blade.php` | Google Analytics data (page 2) |
| `search-console-1.blade.php` | Search Console metrics (page 1) |
| `search-console-2.blade.php` | Search Console metrics (page 2) |
| `performance.blade.php` | PageSpeed Insights scores |
| `footer.blade.php` | Closing page |

---

## Livewire Components

### `SiteReports` (`app/Livewire/Sites/Detail/SiteReports.php`)
**Route:** `/sites/{site}/reports`

Per-site report management. Features:
- **Generate modal:** Select template, period (7-day / 30-day / last month / custom), optional recipients
- **Schedule modal:** Configure frequency, day, time, timezone, period, recipients, admin copy, custom email subject/body, client name
- **Send modal:** Re-send existing report to additional email addresses
- **Report history table:** Date, period, template, status, size, actions (download, send, delete)
- **Schedule status card:** Shows active schedule details

**Key methods:** `generateReport()`, `saveSchedule()`, `deleteSchedule()`, `sendReport()`, `deleteReport()`, `calculatePeriod()`

Pagination: 15 reports per page.

### `ReportTemplatesSettings` (`app/Livewire/Settings/ReportTemplatesSettings.php`)
**Route:** `/settings/report-templates`

Global template management. Features:
- Create / Edit / Duplicate / Delete templates
- Set default template
- Configure sections (checkboxes)
- Branding (company name, website, primary color)
- Content (intro/closing text)
- Prevents deletion if template is used by active schedules

### `ReportsOverview` (`app/Livewire/Reports/ReportsOverview.php`)
**Route:** `/reports`

Placeholder dashboard — currently shows "Reports dashboard coming soon".

### Report Card (`resources/views/livewire/sites/detail/overview/_reports-card.blade.php`)
Quick-view card on the site dashboard showing active schedules and last sent date.

---

## Routes

```
# Signed download (unauthenticated, 7-day expiry)
GET /reports/{report}/download/signed  →  ReportDownloadController  [reports.download.signed]

# Authenticated download
GET /reports/{report}/download          →  ReportDownloadController  [reports.download]

# Per-site reports management
GET /sites/{site}/reports               →  SiteReports              [sites.reports]

# Reports dashboard (placeholder)
GET /reports                            →  ReportsOverview           [reports.index]

# Template settings
GET /settings/report-templates          →  ReportTemplatesSettings   [settings.report-templates]
```

---

## Download Controller (`app/Http/Controllers/ReportDownloadController.php`)

- Handles both signed (unauthenticated) and authenticated downloads
- `?preview=1` query param for inline PDF display
- Default: direct file download
- Authorization checks for authenticated routes

---

## Queue Configuration

From `config/horizon.php`:
- Reports queue rate limit: 120 jobs/second
- Supervisor monitors: `performance`, `reports`, `default` queues

---

## Localization

| File | Language |
|---|---|
| `lang/en/report.php` | English |
| `lang/ro/report.php` | Romanian |

Keys cover: title, period, uptime %, backups, updates, performance scores, analytics, search console, security, cloudflare, custom notes.

---

## Activity Logging

Via `app/Services/ActivityLogger.php`:
- `reportGenerated(Site, title)` — logged when PDF is generated
- `reportSent(Site, title, recipients)` — logged when report is emailed

---

## Database Migrations

| Migration | Table |
|---|---|
| `2026_02_03_200001` | `report_templates` |
| `2026_02_03_200002` | `report_schedules` — indexes on `(site_id)`, `(is_active, next_run_at)` |
| `2026_02_03_200003` | `reports` — index on `(site_id, created_at)` |
| `2026_02_13_000010` | `site_report_configs` — unique on `site_id` |

---

## File Inventory

| Category | Count | Files |
|---|---|---|
| Models | 4 | Report, ReportTemplate, ReportSchedule, SiteReportConfig |
| Jobs | 1 | GenerateReport |
| Dispatchers | 1 | ReportDispatcher |
| Services | 1 | ReportGeneratorService |
| Mail | 1 | ReportGeneratedMail |
| Controllers | 1 | ReportDownloadController |
| Livewire | 3 | SiteReports, ReportTemplatesSettings, ReportsOverview |
| Blade views | 19 | 1 main + 1 styles + 12 partials + 3 Livewire + 1 mail + 1 card |
| Migrations | 4 | Templates, schedules, reports, site configs |
| Localization | 2 | English, Romanian |

**Estimated total:** ~3,500+ lines of report-specific code.

---

## Flow Diagram

```
User clicks "Generate"              ReportDispatcher (every 5 min)
        │                                    │
        │                          Finds due schedules
        │                                    │
        ▼                                    ▼
  ┌─────────────────────────────────────────────┐
  │           GenerateReport Job                │
  │  (queue: reports, unique per site+template) │
  └─────────────┬───────────────────────────────┘
                │
                ▼
  ┌─────────────────────────────────┐
  │    ReportGeneratorService       │
  │  1. Gather data per section     │
  │  2. Render Blade → DomPDF      │
  │  3. Store PDF to disk          │
  └─────────────┬───────────────────┘
                │
                ▼
  ┌─────────────────────────────────┐
  │  Update Report record           │
  │  (status, file_path, snapshot)  │
  └─────────────┬───────────────────┘
                │
        ┌───────┴────────┐
        ▼                ▼
  Send email       Log activity
  (if recipients)  (ActivityLogger)
        │
        ▼
  ReportGeneratedMail
  (PDF attached + signed URL)
```
