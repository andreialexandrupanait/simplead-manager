# PDF Report — Complete Redesign

## Context

We have a Laravel 11 application that generates maintenance reports as A4 PDF for WordPress client sites. The report system is fully functional (models, jobs, schedules, email delivery all work) but the visual output has serious problems: wasted pages, poor spacing, no trend comparisons, and missing sections.

**Attached files you MUST study before making any changes:**

1. **REPORTS.md** — Complete architecture documentation (models, services, blade files, data flow)
2. **raport-manuela-sirbu-manuela-sirbu.pdf** — Current report output. THIS IS BROKEN. Study every page to see the problems.
3. **report_Raport-Matematica-Interactiva_2026-01-01-b5a7.pdf** — Report from ModularDS platform. THIS IS THE DESIGN TARGET. Match this quality level.

---

## Step 0: Investigate Before Changing

Before writing ANY code, do the following:

### Determine the PDF renderer

```bash
grep -rn "Browsershot\|browsershot\|puppeteer\|Puppeteer\|dompdf\|DomPDF\|Dompdf\|loadHTML\|setPaper\|setOptions\|chrome\|chromium" app/Services/ReportGeneratorService.php app/Jobs/GenerateReport.php
cat composer.json | grep -i "browser\|dompdf\|snappy\|wkhtml\|puppeteer\|chrome"
```

The PDF metadata says `Creator: Chromium` which suggests Browsershot/Puppeteer. **This matters because:**
- If Browsershot/Chromium: you can use flexbox, grid, modern CSS, Google Fonts — everything works
- If DomPDF: you must use `<table>` for all multi-column layouts, no flexbox, no grid, limited CSS

**Adapt your CSS approach based on what you find.** If it's Chromium, use flexbox/grid freely. If it's DomPDF, use only `<table>` layouts.

### Read all template files

```bash
find resources/views/reports -name "*.blade.php" | sort
```

Then read and understand every single file:
- `maintenance-report.blade.php` — the main wrapper
- `styles.blade.php` — all CSS
- Every file in `partials/` and `components/`
- The `generate()` method and all `gatherXxxData()` methods in `ReportGeneratorService.php`

### Read localization files

```bash
cat lang/ro/report.php
cat lang/en/report.php
```

### Read monthly snapshot model

```bash
cat app/Models/SiteMonthlySnapshot.php
find database/migrations -name "*snapshot*" -exec cat {} \;
```

You need to understand the snapshot schema because trend comparisons pull data from it.

---

## The 7 Problems to Fix

### Problem 1: Every section is forced onto its own page, causing massive empty pages

The current `maintenance-report.blade.php` wraps every section in its own `.page` div, and `.page` has `page-break-after: always`. This means even a section with 3 lines of content (like backups with 0 entries) gets an entire A4 page.

**Current state (13 pages):**
- Page 1: Cover — OK
- Page 2: Intro — only uses top-left 40%, rest empty
- Page 3: Overview — OK, full page
- Page 4: Updates — only 1 plugin updated, mostly empty
- Page 5: Uptime — chart + table, reasonably full
- Page 6: Backups — 3 lines of text, 95% empty
- Page 7: Analytics 1 — OK
- Page 8: Analytics 2 — OK (breakdown tables)
- Page 9: Search Console 1 — OK
- Page 10: Search Console 2 — OK (breakdown tables)
- Page 11: Performance — OK
- Page 12: Closing text — short paragraph, 70% empty
- Page 13: Just the Simplead logo — entire page wasted

**What to do:**

Restructure `maintenance-report.blade.php` to stop wrapping every section in its own `.page` div with forced page breaks. Instead:

- **Cover page:** Keep as its own full-bleed page (no margins, no header/footer). This stays separate.
- **Intro text:** Move it INTO the cover page itself (right panel, below the title and URL). Eliminate the standalone intro page entirely.
- **All content sections (overview, updates, uptime, backups, analytics, search console, performance, security, database):** Render them as a continuous flow inside `.page` divs WITHOUT `page-break-after: always` on every div. Instead, use `page-break-before: always` ONLY on sections that need a fresh page start (overview should start on a new page since it's the first content page). Let the browser/renderer naturally break pages when content overflows.
- **Small sections like backups or updates with minimal data:** Should NOT force a new page. They flow naturally. If backups has just "Enabled / Weekly / 0 backups", it takes a few lines and the next section continues below.
- **Use `page-break-inside: avoid` on individual cards, tables, and grouped elements** so they don't get split across pages awkwardly.
- **Closing page:** Combine closing text + company branding into one section at the end. Remove the standalone Simplead logo page (page 13).
- **The page header and footer** should still appear, but manage this with CSS (fixed positioning if Chromium) or by placing them in a wrapper structure.

The goal: bring the report from 13 pages down to approximately 7-9 pages depending on data volume, with no empty-looking pages.

### Problem 2: No trend comparisons (↑↓ percentages)

The ModularDS reference shows arrows next to every metric: `↓29%`, `↑2%`, `↓0%`. Our report shows flat numbers with no context.

**What to do:**

In `ReportGeneratorService`, for every report generation:

1. Load the `site_monthly_snapshot` for the current reporting period (month/year)
2. Load the `site_monthly_snapshot` for the PREVIOUS period (month-1)
3. For each metric that has both a current and previous value, calculate the percentage change
4. Pass the trend data alongside the metric values to the Blade templates

Create a helper method on the service (or a dedicated helper class) that takes a current value and a previous value, and returns:
- Direction: `up`, `down`, or `neutral`
- Percentage change (rounded to 1 decimal)
- Display string: e.g., `↑ 25.3%` or `↓ 8.1%` or `— 0%`
- Color: green for positive, red for negative, gray for neutral

Create a SECOND helper for metrics where lower is better (bounce rate, average position, response time, CLS): same logic but green/red colors are reversed (going down is good).

Create a small Blade component that renders the trend as a colored span. Every section partial should use this component next to metric values.

**Metrics that need trends:**
- Updates: total count vs previous period
- Uptime: percentage, avg response time (inverse), downtime minutes (inverse), incidents (inverse)
- Backups: successful count
- Analytics: pageviews, users, bounce rate (inverse), session duration
- Search Console: clicks, impressions, CTR, avg position (inverse)
- Performance: mobile score, desktop score
- Security: security score
- Database: does not need trends (just shows what was cleaned)

### Problem 3: Cover page doesn't match the reference design

Current: Centered layout on gray background. Functional but generic.
Target: ModularDS split layout — white left, colored right.

**What to do:**

Rewrite the cover page partial:
- Left panel (~45% width): white background. Client logo centered vertically (or site name in large text if no logo). Company logo (Simplead) small at the bottom-left.
- Right panel (~55% width): filled with `primary_color` from the template. Contains: generation date (small white text), report title in the report language — Romanian: "Raportul lunar pentru" / English: "Monthly report for" — in large white bold text, then the site URL below in slightly smaller white text.
- The intro text that's currently on page 2 should be added below the URL on the right panel (smaller white text, partially transparent). This eliminates the standalone intro page.
- No header, no footer on the cover page.

### Problem 4: Missing new sections (Security, Database)

**Security section:**

Add a `gatherSecurityData()` method to the service. It should:
- Check if the security module is active for the site (via `site_module_configs`)
- If not active, return null (section won't render)
- If active, get the latest security scan from the reporting period
- Get the previous period's scan for trend comparison
- Return: score, previous score, score change, scan date, issue counts by severity, check results list, recommendations list

The Blade partial should show:
- Security score as a large colored number or circle (green 90-100, orange 50-89, red 0-49) with trend
- Summary: "X critical, Y warnings, Z informational"
- Results table: check name, pass/fail status, details
- Top 5 recommendations as a simple list

**Database section:**

Add a `gatherDatabaseData()` method. It should:
- Query database cleanup logs for the reporting period
- If no cleanups happened, return null (section won't render)
- Return: DB size, table count, was optimized, total space saved, cleanup categories with counts and space saved

The Blade partial should show:
- Summary cards: DB size, tables, space saved
- Cleanup details table: category name, items deleted, space saved per category
- Total saved and last cleanup date

**For both new sections:**
- Add `security` and `database` to the available sections array in the `ReportTemplate` model
- Add the section checkboxes to the `ReportTemplatesSettings` Livewire component
- Update the default template seeder to include the new sections
- Add all localization keys to both `lang/ro/report.php` and `lang/en/report.php`

### Problem 5: Analytics and Search Console each take 2 forced pages

Currently analytics-1 and analytics-2 are combined in one `.page` div, same for search console. But the content may not actually need 2 full pages.

**What to do:**

Don't pre-split these into "page 1" and "page 2". Instead, render all analytics content as one continuous block (summary cards, chart, then breakdown tables). Same for search console. Let the natural page flow handle overflow. The breakdown tables (channels, top pages, cities, countries) can use a 2×2 grid that's compact enough to potentially fit on one page with the summary.

Consider merging `analytics-1.blade.php` and `analytics-2.blade.php` into a single `analytics.blade.php`. Same for search console.

### Problem 6: No visual grouping on the overview page

The overview page shows metrics from different modules but they all look the same — no visual separation between update metrics, uptime metrics, analytics metrics, and search console metrics.

**What to do:**

Group related metrics with subtle visual cues:
- Add a small group label above each row of cards (e.g., "MONITORIZARE" above uptime/backup cards, "TRAFIC & SEO" above analytics/search console cards)
- Or add a thin horizontal divider line between groups
- Make the most critical metrics larger (uptime %, performance scores) than secondary ones
- The overview should be a visual summary that tells a story at a glance

### Problem 7: The report has no breathing room

The CSS uses `padding: 15mm` on all sides. For A4 that's about 1.5cm — too tight for a professional report.

**What to do:**

Increase page padding to `20mm` left/right and `20mm` top, `25mm` bottom (to leave room for footer). This gives approximately 2-2.5cm margins as requested. Also verify the content area width does not exceed what fits: with 20mm left + 20mm right padding on 210mm A4, the content area is 170mm wide. Make sure tables and cards respect this width.

---

## Section Order in the Report

After the redesign, sections should flow in this order:

1. **Cover page** (with intro text integrated) — full bleed, own page
2. **Executive Overview** — starts on new page, always present
3. **Updates** — flows naturally (new page only if previous content filled the page)
4. **Uptime** — flows naturally
5. **Backups** — flows naturally (may share page with uptime if both are small)
6. **Analytics** — flows naturally (one combined section, not split into 2)
7. **Search Console** — flows naturally (one combined section)
8. **Performance** — flows naturally
9. **Security** — flows naturally (only if module active and has data)
10. **Database** — flows naturally (only if cleanup ran in period)
11. **Closing** — flows naturally (thank you text + company branding, no forced page break)

Use `page-break-before: always` ONLY on the overview section (so it starts on a fresh page after the cover). Everything else flows naturally with `page-break-inside: avoid` on cards and tables.

---

## Localization

Add all missing translation keys for the new sections and trend labels to both language files. Every visible string in the PDF must go through `__('report.key', [], $language)`. No hardcoded Romanian or English strings in the Blade templates.

Key groups to add:
- Security section: score, issues, checks, recommendations, status labels
- Database section: size, tables, optimized, saved, categories, cleanup labels
- Trend labels: "vs last month", "vs last week", "no previous data"
- Cover: "Monthly report for" / "Raportul lunar pentru"
- Any missing labels found while rewriting partials

---

## Number Formatting

Romanian locale: comma for decimals, dot for thousands (e.g., `1.600` and `45,08%`).
English locale: dot for decimals, comma for thousands (e.g., `1,600` and `45.08%`).

Create or update a formatting helper that respects the report language. Use it everywhere numbers appear.

---

## What Stays Unchanged

- All models: Report, ReportTemplate, ReportSchedule, SiteReportConfig
- The GenerateReport job
- The ReportDispatcher
- The ReportGeneratedMail and email delivery
- The ReportDownloadController and routes
- The Livewire components (SiteReports, ReportTemplatesSettings, ReportsOverview)
- The scheduling and trigger logic

**Only the visual output changes:** CSS, Blade templates, data gathering enrichment (trends), and new gathering methods (security, database).

---

## Testing Checklist

After implementation, generate test reports and verify:

- [ ] Page margins are approximately 2-2.5cm on all sides (measure in PDF viewer)
- [ ] No content touches page edges
- [ ] Cover page has no margins (full bleed) with split layout working
- [ ] Intro text is on the cover page, no standalone intro page
- [ ] Romanian diacritics (ă, â, î, ș, ț, Ă, Â, Î, Ș, Ț) render correctly
- [ ] Small sections (backups with 0 entries, updates with 1 item) don't waste a full page
- [ ] No completely empty pages
- [ ] The report is approximately 7-9 pages (not 13)
- [ ] Trend arrows appear next to metrics with correct colors (green up, red down)
- [ ] Trend arrows on inverse metrics use reversed colors (green down for bounce rate)
- [ ] Security section appears only when module is active
- [ ] Database section appears only when cleanup ran during the period
- [ ] Performance score circles display with correct color coding
- [ ] Data tables have alternating row backgrounds and don't overflow page width
- [ ] Header (logo + report title) appears on every page except cover
- [ ] Footer (company logo/name) appears on every page except cover
- [ ] Both RO and EN reports generate correctly with proper translations
- [ ] A report with minimal data (new site, most modules empty) still looks professional
- [ ] Charts render (uptime response time, analytics traffic, search console metrics)
- [ ] The overall visual quality is comparable to the ModularDS reference PDF
