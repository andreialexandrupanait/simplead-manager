# PDF Report — Complete Layout & Design Rewrite

## Context

We generate maintenance reports as A4 PDF using Barryvdh/Laravel-DomPDF in a Laravel 11 application. The current report has persistent layout problems: broken margins, content touching edges, inconsistent spacing, elements overlapping or misaligned. Multiple attempts to fix this with standard CSS have failed because DomPDF has severe CSS limitations.

This prompt requires a **complete rewrite** of the report HTML/CSS, not patches on top of the existing broken code.

## Reference Materials

Two reference PDFs are attached:

1. **raport-manuela-sirbu-manuela-sirbu.pdf** — This is our CURRENT report output. Study it carefully to understand the data structure, sections, and content that must be preserved. Note all the visual problems: layout issues, spacing problems, elements that look wrong.

2. **report_Raport-Matematica-Interactiva_2026-01-01-b5a7.pdf** — This is a report from another platform (ModularDS). This is the DESIGN TARGET. Study the visual quality: clean spacing, readable tables, proper margins, the split cover page layout, the comparison arrows (↑↓ percentages), the update log format. Our report should look this professional.

Also read `REPORTS.md` — it documents the complete existing architecture: models, services, blade partials, data gathering methods, sections. The backend logic stays unchanged, only the Blade templates and CSS are being rewritten.

---

## The Core Problem

DomPDF is NOT a browser. It does not support:
- Flexbox (`display: flex`) — will be completely ignored
- CSS Grid (`display: grid`) — will be completely ignored
- `calc()` — not supported
- `box-shadow` — not supported
- CSS variables (`var()`) — not supported
- Complex `border-radius` on large elements — inconsistent
- `transform` / `transition` — not supported
- `overflow: hidden` on positioned elements — inconsistent
- `@import` for fonts — unreliable
- JavaScript-based charts — not executed

Every layout must be built using ONLY:
- `<table>` for all multi-column layouts (this is the only reliable way)
- `float: left/right` with explicit `width` in percentages (use sparingly)
- `position: absolute/relative` for overlays (cover page only)
- `display: table` / `display: table-cell` as alternative to HTML tables
- Inline SVG for charts and score circles (simple shapes only: circle, rect, line, polyline, polygon, text, path)
- `page-break-before: always` / `page-break-after: always` / `page-break-inside: avoid`

**Before writing ANY CSS, ask yourself: "Does DomPDF support this?" If unsure, use a `<table>` instead.**

---

## Step 1: Read the Current Code

Before changing anything, read and output the FULL content of these files:

1. `resources/views/reports/maintenance-report.blade.php` (main wrapper)
2. `resources/views/reports/styles.blade.php` (all CSS)
3. Every file in `resources/views/reports/partials/`
4. `app/Services/ReportGeneratorService.php` (the generate method and all gather methods)
5. `config/dompdf.php`

Understand what data is passed to each partial, what variables are available, and how sections are conditionally rendered.

---

## Step 2: DomPDF Configuration

Verify `config/dompdf.php` has these critical settings:
- `dpi` must be `96` (the standard for DomPDF — at 96 DPI, the math for mm-to-px conversion works correctly: 1mm = 3.78px)
- `default_font` must be `dejavu sans` (supports Romanian diacritics: ă, â, î, ș, ț)
- `enable_html5_parser` must be `true`
- `enable_remote` must be `true` (for loading logo images)
- `default_paper_size` must be `a4`

In `ReportGeneratorService` where the PDF is generated, make sure `setPaper('a4', 'portrait')` is called. Do NOT override options at generation time — let the config file handle everything.

---

## Step 3: Complete CSS Rewrite

Delete ALL existing CSS in `styles.blade.php` and rewrite from scratch. The new CSS must follow these rules:

### Page Setup

- A4 is 210mm × 297mm
- Set `@page` with `size: A4 portrait` and zero margins (we control margins via padding on containers)
- Every section page is a `<div>` with class `.page` that gets:
  - Explicit `width: 210mm`
  - Explicit `min-height: 297mm`
  - Padding: `20mm` top, `20mm` left, `25mm` bottom, `20mm` right (these are our margins — roughly 2-2.5cm as requested)
  - `box-sizing: border-box` so padding is included in the width
  - `page-break-after: always` to force each section onto its own page
  - `position: relative` (needed for header/footer positioning)
- The cover page uses `padding: 0` instead (full bleed)
- The last `.page` div does NOT get `page-break-after` (or set it to `auto`)

### Font

- Use `DejaVu Sans` everywhere — it's the only font bundled with DomPDF that fully supports Romanian characters
- Set it on the `body` and also with `* { font-family: 'DejaVu Sans', sans-serif; }` to ensure nothing inherits a different font
- Make sure the HTML has `<meta charset="UTF-8">` AND `<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>` in the `<head>`

### Typography Scale

Use `pt` units for font sizes (not `px` — `pt` is more reliable in DomPDF for print):
- Section headings: `16pt` bold
- Sub-headings: `12pt` bold  
- Card metric values: `18pt` bold
- Card labels: `8pt` uppercase, gray
- Body text: `9pt`
- Table headers: `8pt` uppercase, bold, gray
- Table cells: `9pt`
- Small text (trends, captions): `7pt`
- Footer text: `7pt`

### Color Palette

Use the template's `primary_color` (default `#7C3AED`) for accent elements. Everything else:
- Headings: `#111827`
- Body text: `#374151`
- Muted/labels: `#6b7280`
- Light text: `#9ca3af`
- Page background: `#f8f9fc`
- Card/section background: `#ffffff`
- Borders: `#e5e7eb`
- Table alt rows: `#f9fafb`
- Good/green: `#10b981`
- Warning/orange: `#f59e0b`
- Bad/red: `#ef4444`

### Layout Approach

**ALL multi-column layouts must use `<table>` elements.** This is the single most important rule. Examples:

- The overview page with 3 metric cards in a row → `<table>` with one `<tr>` and 3 `<td>` each at `width: 33%`
- The analytics breakdown page with 2×2 grid → `<table>` with 2 `<tr>` and 2 `<td>` each at `width: 50%`
- The performance page with mobile/desktop side by side → `<table>` with 1 `<tr>` and 2 `<td>` at `width: 50%`
- The header with logo left and text right → `<table>` with 1 `<tr>` and 2 `<td>`

Add `border-spacing` on the table (e.g., `border-spacing: 8px`) to create gaps between cards. Do NOT use margin on `<td>` (DomPDF ignores it).

### Metric Cards

Each metric card is a `<td>` inside a layout table with:
- White background
- `1px solid #e5e7eb` border
- `border-radius: 6px` (works on small elements in DomPDF)
- Padding `10px 12px`
- Contains: label (small uppercase gray), value (large bold), optional sublabel, optional trend indicator

### Data Tables

For the update log, uptime incidents, backup history, search console queries, etc:
- Full-width `<table>` with `border-collapse: collapse`
- Header row: light gray background, uppercase small text, 2px bottom border
- Data rows: 1px bottom border in very light gray
- Alternating row background via `:nth-child(even)` (DomPDF supports this)
- Cell padding: `8px 10px`

### Score Circles (Performance Page)

Use inline SVG — a background circle in gray, an arc (partial circle) in the score color, and text in the center. Keep the SVG simple: just `<circle>` elements and `<text>`. Use `stroke-dasharray` and `stroke-dashoffset` on the colored circle to create the arc. These SVG features work in DomPDF.

### Charts (Uptime Response Time, Analytics Traffic, Search Console)

Use inline SVG with:
- `<polyline>` for the data line
- `<polygon>` for the filled area under the line
- `<line>` for grid lines
- `<text>` for axis labels

Generate the SVG points server-side in the `ReportGeneratorService` — calculate x,y coordinates from the data array and pass them to the Blade template as a string of points.

Keep charts simple: no tooltips, no hover effects, no gradients. Just a clean line with a subtle fill area underneath.

---

## Step 4: Main Wrapper Rewrite

Rewrite `maintenance-report.blade.php` as a clean HTML5 document:

The structure should be:
1. `<!DOCTYPE html>` with `<html lang="{{ $language }}">`
2. `<head>` with charset meta tags and a single `<style>` block containing ALL CSS (no external stylesheets — DomPDF needs inline styles)
3. `<body>` with the page background color
4. Inside body: one `.page` div per section, each containing:
   - The header (repeated on every page except cover) — logo left, report title + period right, with a colored bottom border line
   - The section content (included via `@include`)
   - The footer (repeated on every page except cover) — centered small company logo and name
5. Each `.page` div has `page-break-after: always`
6. Sections are conditionally rendered based on the `$sections` array from the template

The header and footer are NOT CSS `position: fixed` or `position: running()` — those are unreliable in DomPDF. Instead, literally include the header HTML and footer HTML inside every `.page` div. Yes, this means the header/footer markup is repeated in every section partial. This is the only reliable approach in DomPDF.

---

## Step 5: Cover Page Rewrite

The cover page should use the ModularDS split layout:
- Left ~45% of the page: white background, client logo centered vertically, company logo at the bottom
- Right ~55% of the page: filled with `primary_color`, contains the generation date (small white text), report title (large white bold text: "Raportul lunar pentru" or "Monthly report for" depending on language), and the site URL below it

Implement this using `position: absolute` for the two panels inside a `position: relative` container that spans the full page (210mm × 297mm). The cover page div has `padding: 0` (no margins — full bleed).

If client logo is not available, show the site name as large text instead.

---

## Step 6: Section Pages Rewrite

Rewrite each section partial following the layout approach described above. For each section:

### Overview Page
- Section heading with a short accent line (a small `<div>` with the primary color, 50px wide, 3px tall, below the heading text)
- 3 metric cards per row using a `<table>`, with rows for: Updates/Uptime/Backups, Performance/Security/Analytics (or however many are relevant)
- Each card shows: icon or emoji, label, big value, sublabel, and trend indicator (↑/↓ with percentage and color)
- The trend compares to the previous period using the snapshot data

### Updates Page
- Section heading with accent line
- WordPress core status line (checkmark if up to date)
- Update log table with columns: Update name, Date, Version (showing old → new)
- Summary at bottom: 3 small cards for plugin count, theme count, core count — each with trend

### Uptime Page
- Section heading with accent line
- Big uptime percentage in a card with trend
- SVG line chart showing daily average response times
- A simple horizontal bar showing uptime status (green = up, red = down, gray = unknown)
- Three small metric cards: avg response time, total downtime, incident count
- Incident table below (status, from, to, duration)

### Backups Page
- Section heading with accent line
- Four metric cards: status (on/off), frequency, successful count, failed count
- Backup history table: date, size, destination, status checkmark
- Total stored size at the bottom

### Analytics Pages (2 pages)
- Page 1: Four metric cards (pageviews, users, bounce rate, session duration — each with trend), daily users SVG chart, and two half-width boxes for new/returning users split and device distribution
- Page 2: Four half-width tables in 2×2 grid: traffic sources, top pages, top cities, top countries

### Search Console Pages (2 pages)
- Page 1: Four metric cards (clicks, impressions, avg CTR, avg position — each with trend), multi-line SVG chart, top 10 queries table
- Page 2: Four half-width tables in 2×2 grid: top pages, top countries, device breakdown, top dates

### Performance Page
- Section heading with accent line
- Two side-by-side panels (table with 2 cells) for Mobile and Desktop
- Each panel: device label, date updated, SVG score circle (colored by score range), trend vs previous, then a list of Core Web Vitals metrics (FCP, SI, LCP, TTI, TBT, CLS) each with a colored status indicator (green dot / orange square / red triangle based on value)
- Legend at the bottom explaining the color coding

### Security Page (if module active)
- Section heading with accent line
- Security score circle with trend
- Issue summary (X critical, Y warnings)
- Check results table: check name, pass/fail status, details
- Recommendations list (bullet points)

### Database Page (if cleanup ran in period)
- Section heading with accent line
- Four metric cards: DB size, table count, optimized yes/no, space saved
- Cleanup categories table: category name, items deleted, space saved
- Total saved and last cleanup date at bottom

### Closing Page
- Large centered thank-you heading
- Closing text from template (or default localized text)
- Large company logo centered below

---

## Step 7: Trend Indicators

Every metric card should show how the value changed compared to the previous period. The data for comparison comes from `site_monthly_snapshots` — load the current month's snapshot and the previous month's snapshot.

Display format:
- Value went up: green `↑` followed by the percentage, e.g., `↑ 25%`
- Value went down: red `↓` followed by the percentage, e.g., `↓ 15%`
- No change: gray `─ 0%`
- No previous data available: gray `─`

For metrics where LOWER is better (response time, bounce rate, average position): reverse the colors — going down is green, going up is red.

Add a `calculateTrend($current, $previous)` helper method to `ReportGeneratorService` that returns the direction, percentage, display string, and color. Add a `calculateTrendInverse()` variant for the reversed metrics.

Create a small Blade partial for rendering the trend (a `<span>` with the appropriate color and text) that all section partials can include.

---

## Step 8: Number Formatting

Romanian locale uses comma for decimals and dot for thousands (e.g., `1.600` and `45,08%`). English uses dot for decimals and comma for thousands. Add a helper that formats numbers based on the report language. Use it everywhere numbers are displayed.

---

## Step 9: Localization

Read the existing `lang/ro/report.php` and `lang/en/report.php` files. Add any missing keys needed for the new sections (security, database) and the trend labels. All visible text in the PDF must go through `__('report.key_name', [], $language)` — no hardcoded strings.

---

## Step 10: Testing & Validation

After the rewrite:

1. Generate a test report and check that:
   - Page margins are approximately 2-2.5cm on all sides (measure in a PDF viewer)
   - No content touches the page edges
   - Romanian diacritics (ă, â, î, ș, ț) render correctly
   - Each section starts on a new page
   - Tables don't overflow the page width
   - SVG charts render (lines visible, labels readable)
   - Score circles render with correct colors
   - The cover page has no margins (full bleed) with the split layout
   - Header and footer appear on every page except the cover
   - All trend arrows display with correct colors

2. Generate a report with minimal data (new site, most modules not connected) and verify that fallback states work: "Not configured", "No data", etc.

3. Generate a report in both Romanian and English to verify localization.

---

## Summary of What Changes

| Component | Action |
|-----------|--------|
| `config/dompdf.php` | Verify/update DPI, font, parser settings |
| `resources/views/reports/styles.blade.php` | **DELETE and rewrite from scratch** |
| `resources/views/reports/maintenance-report.blade.php` | **Rewrite** — clean HTML5 wrapper, inline styles, conditional sections |
| `resources/views/reports/partials/cover.blade.php` | **Rewrite** — split layout |
| `resources/views/reports/partials/intro.blade.php` | **Rewrite** — clean spacing |
| `resources/views/reports/partials/overview.blade.php` | **Rewrite** — card grid with trends |
| `resources/views/reports/partials/updates.blade.php` | **Rewrite** — log table + trends |
| `resources/views/reports/partials/uptime.blade.php` | **Rewrite** — chart + metric cards + trends |
| `resources/views/reports/partials/backups.blade.php` | **Rewrite** — history table + metric cards |
| `resources/views/reports/partials/analytics-1.blade.php` | **Rewrite** — metrics + chart + distributions |
| `resources/views/reports/partials/analytics-2.blade.php` | **Rewrite** — 2×2 breakdown tables |
| `resources/views/reports/partials/search-console-1.blade.php` | **Rewrite** — metrics + chart + queries |
| `resources/views/reports/partials/search-console-2.blade.php` | **Rewrite** — 2×2 breakdown tables |
| `resources/views/reports/partials/performance.blade.php` | **Rewrite** — dual panels with SVG circles |
| `resources/views/reports/partials/security.blade.php` | **NEW** — security score + checks + recommendations |
| `resources/views/reports/partials/database.blade.php` | **NEW** — cleanup summary + categories table |
| `resources/views/reports/partials/footer.blade.php` | **Rewrite** — closing page |
| `app/Services/ReportGeneratorService.php` | **Modify** — add trend helpers, chart point generators, new gather methods |
| `lang/ro/report.php` | **Update** — add new keys |
| `lang/en/report.php` | **Update** — add new keys |

**Backend logic (models, jobs, dispatchers, schedules, email) stays completely unchanged.** Only the visual output and the data gathering enrichment (trends, chart data) change.
