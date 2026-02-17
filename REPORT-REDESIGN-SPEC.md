# PDF Report Redesign — Full Specification

**Document type:** Implementation specification for Claude Code
**Purpose:** Complete visual redesign + new sections for the PDF maintenance report
**Target:** DomPDF (Barryvdh/Laravel-DomPDF), A4 portrait, Blade templates
**Current state:** Working report system with 12 partials — see `REPORTS.md` for existing architecture

---

## Table of Contents

1. [Design Philosophy](#1-design-philosophy)
2. [Critical CSS Fixes](#2-critical-css-fixes)
3. [Color System & Typography](#3-color-system--typography)
4. [Page-by-Page Specification](#4-page-by-page-specification)
5. [New Sections](#5-new-sections)
6. [Period Comparison System](#6-period-comparison-system)
7. [Data Sources & Gathering](#7-data-sources--gathering)
8. [Template Sections Configuration](#8-template-sections-configuration)
9. [Localization Updates](#9-localization-updates)
10. [File Structure](#10-file-structure)
11. [Migration Changes](#11-migration-changes)
12. [Implementation Order](#12-implementation-order)
13. [DomPDF Constraints & Workarounds](#13-dompdf-constraints--workarounds)

---

## 1. Design Philosophy

### Reference Style
The redesign follows the **ModularDS report style** with these key principles:

- **Period-over-period comparison** — every metric shows ↑↓ arrows with percentage change vs previous period
- **Clean data tables** with alternating rows and clear column headers
- **Split-layout cover page** — branding left, color block right
- **Metric cards in grids** — 3-4 per row, each with label, value, and trend indicator
- **Consistent header/footer** on every page (except cover)
- **Section color coding** — each section gets a subtle accent via its header icon color
- **White background content areas** with light gray (#f8f9fc) page background
- **Professional but approachable** — not sterile, not flashy

### Design Constraints (DomPDF)
DomPDF has limited CSS support. The following are **NOT available**:
- `flexbox` / `grid` — use `display: table`, `float`, or `position: absolute` instead
- `border-radius` on large elements — works on small elements only
- `box-shadow` — not supported at all
- `calc()` — not supported
- `CSS variables` — not supported
- `transform` / `transition` — not supported
- `overflow: hidden` on positioned elements — inconsistent
- Complex SVG — keep SVG simple (circles, rects, lines, paths)
- `@import` for fonts — embed fonts or use system fonts
- `background-image: url()` — works but paths must be absolute or base64

**What DOES work well:**
- `float: left/right` with `width: %`
- `display: table` / `table-cell` / `table-row`
- Basic SVG (circles, rects, text, simple paths)
- `position: absolute/relative` for overlays
- `@page` rules for margins
- `page-break-before` / `page-break-after` / `page-break-inside: avoid`
- Inline `<style>` blocks
- `border`, `padding`, `margin`, `background-color`
- `border-radius` on small elements (< 100px)

---

## 2. Critical CSS Fixes

### 2.1 Page Margins (MUST FIX — current report has no margins)

```css
@page {
    margin: 15mm 15mm 20mm 15mm; /* top right bottom left */
    size: A4 portrait; /* 210mm x 297mm */
}

/* Cover page — no margins (full bleed) */
@page cover-page {
    margin: 0;
}

.cover-page {
    page: cover-page;
}
```

### 2.2 Running Header (pages 2+)

```html
<div class="page-header">
    <table style="width: 100%; border-bottom: 2px solid {primary_color};">
        <tr>
            <td style="width: 50%; text-align: left; vertical-align: middle;">
                <img src="{company_logo}" style="height: 28px;" />
            </td>
            <td style="width: 50%; text-align: right; vertical-align: middle; font-size: 9pt; color: #6b7280;">
                {report_title} # {period_short}
            </td>
        </tr>
    </table>
</div>
```

### 2.3 Running Footer (pages 2+)

```html
<div class="page-footer" style="text-align: center; padding-top: 8px; border-top: 1px solid #e5e7eb;">
    <img src="{company_logo_small}" style="height: 20px;" />
    <div style="font-size: 7pt; color: #9ca3af;">{company_name}</div>
</div>
```

### 2.4 Page Breaks

```css
.section-page {
    page-break-before: always;
}

.no-break {
    page-break-inside: avoid;
}
```

---

## 3. Color System & Typography

### 3.1 Color Palette

The `primary_color` comes from the ReportTemplate model (default `#7C3AED`). All other colors are fixed:

| Token | Hex | Usage |
|-------|-----|-------|
| `primary` | `{from template}` | Section headers, accent lines, cover block |
| `primary-light` | `{primary}15` opacity | Card backgrounds, subtle fills |
| `text-dark` | `#111827` | Headings, primary text |
| `text-body` | `#374151` | Body text, descriptions |
| `text-muted` | `#6b7280` | Labels, secondary info |
| `text-light` | `#9ca3af` | Captions, timestamps |
| `bg-page` | `#f8f9fc` | Page background |
| `bg-card` | `#ffffff` | Card/section backgrounds |
| `border-light` | `#e5e7eb` | Table borders, dividers |
| `border-subtle` | `#f3f4f6` | Alternating row backgrounds |
| `score-good` | `#10b981` | 90-100 scores, uptime good |
| `score-medium` | `#f59e0b` | 50-89 scores, warnings |
| `score-bad` | `#ef4444` | 0-49 scores, errors |
| `trend-up` | `#10b981` | Positive trend arrows |
| `trend-down` | `#ef4444` | Negative trend arrows |
| `trend-neutral` | `#6b7280` | No change |

### 3.2 Typography

DomPDF default fonts. Do NOT import Google Fonts (unreliable in DomPDF).

| Element | Font | Size | Weight | Color |
|---------|------|------|--------|-------|
| Cover title | Helvetica | 28pt | Bold | `#111827` |
| Section heading (h2) | Helvetica | 18pt | Bold | `#111827` |
| Sub-heading (h3) | Helvetica | 13pt | Bold | `#374151` |
| Card metric value | Helvetica | 22pt | Bold | `#111827` |
| Card label | Helvetica | 9pt | Normal | `#6b7280` |
| Table header | Helvetica | 8pt | Bold | `#6b7280` (uppercase) |
| Table cell | Helvetica | 9pt | Normal | `#374151` |
| Body text | Helvetica | 10pt | Normal | `#374151` |
| Trend indicator | Helvetica | 8pt | Bold | `{trend color}` |
| Footer text | Helvetica | 7pt | Normal | `#9ca3af` |
| Header right text | Helvetica | 9pt | Normal | `#6b7280` |

### 3.3 Spacing System

| Token | Value | Usage |
|-------|-------|-------|
| `space-xs` | 4px | Inline padding, icon gaps |
| `space-sm` | 8px | Card inner padding, table cell padding |
| `space-md` | 16px | Section gaps, card margins |
| `space-lg` | 24px | Between sections |
| `space-xl` | 32px | Page top margin after header |

---

## 4. Page-by-Page Specification

### PAGE 1: Cover Page (full bleed, no margins)

**Layout:** Split — left 45% white, right 55% primary_color block

```
┌──────────────────────┬──────────────────────────────┐
│                      │                              │
│                      │                              │
│                      │                              │
│   ┌──────────┐       │                              │
│   │ CLIENT   │       │    {generated_date}          │
│   │ LOGO     │       │                              │
│   └──────────┘       │    Raportul lunar pentru     │
│                      │    {site_url}                │
│                      │                              │
│                      │                              │
│                      │                              │
│                      │                              │
│                      │                              │
│   ┌──────────┐       │                              │
│   │ COMPANY  │       │                              │
│   │ LOGO     │       │                              │
│   └──────────┘       │                              │
│                      │                              │
└──────────────────────┴──────────────────────────────┘
```

**Elements:**
- Left panel: white background (`#ffffff`)
  - Client logo (from `report_schedule.client_logo_path` or `site.client.logo`): centered vertically at ~40% height, `max-width: 200px`, `max-height: 100px`
  - Company logo (from `report_template.company_logo_path`): bottom-left area, smaller, `max-height: 50px`
- Right panel: `primary_color` background
  - Date: small text, white, top-right area: `{dd/mm/yyyy} at {HH:MM}`
  - Title: white, 24pt bold: localized "Monthly report for" (RO: "Raportul lunar pentru")
  - Site URL: white, 16pt: `{site.url}`
- No header/footer on this page

**Blade structure:**
```blade
<div class="cover-page">
    <div class="cover-left">
        @if($clientLogo)
            <img src="{{ $clientLogo }}" class="cover-client-logo" />
        @else
            <div class="cover-site-name">{{ $site->name }}</div>
        @endif
        @if($companyLogo)
            <img src="{{ $companyLogo }}" class="cover-company-logo" />
        @endif
    </div>
    <div class="cover-right" style="background-color: {{ $primaryColor }};">
        <div class="cover-date">{{ $generatedDate }}</div>
        <div class="cover-title">{{ __('report.cover_title') }}</div>
        <div class="cover-url">{{ $site->url }}</div>
    </div>
</div>
```

**CSS for cover (DomPDF compatible — uses absolute positioning):**
```css
.cover-page {
    position: relative;
    width: 210mm;
    height: 297mm;
    overflow: hidden;
}
.cover-left {
    position: absolute;
    top: 0;
    left: 0;
    width: 45%;
    height: 100%;
    background: #ffffff;
    text-align: center;
}
.cover-right {
    position: absolute;
    top: 0;
    right: 0;
    width: 55%;
    height: 100%;
    color: #ffffff;
    padding: 40mm 20mm 20mm 25mm;
}
.cover-client-logo {
    position: absolute;
    top: 38%;
    left: 50%;
    margin-left: -100px; /* half of max-width for centering */
    max-width: 200px;
    max-height: 100px;
}
.cover-company-logo {
    position: absolute;
    bottom: 30mm;
    left: 50%;
    margin-left: -60px;
    max-height: 50px;
}
.cover-date {
    font-size: 10pt;
    margin-bottom: 16px;
    opacity: 0.85;
}
.cover-title {
    font-size: 24pt;
    font-weight: bold;
    line-height: 1.2;
    margin-bottom: 12px;
}
.cover-url {
    font-size: 16pt;
    opacity: 0.9;
}
```

---

### PAGE 2: Introduction

**Layout:** Single column, centered content with intro text.

```
┌─────────────────────────────────────────────────────┐
│ [Header: Logo left | Report title # period right]   │
│─────────────────────────────────────────────────────│
│                                                     │
│                                                     │
│   Raport lunar de                                   │
│   Mentenanță și                                     │
│   Performanță website                               │
│                                                     │
│   {intro_text from template}                        │
│                                                     │
│                                                     │
│                                                     │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer: Company logo]                              │
└─────────────────────────────────────────────────────┘
```

**Content:**
- Title: 24pt bold, `#111827`
- Intro text: from `report_template.intro_text`, 11pt, `#374151`, max-width 65% of page
- Default intro text (RO): "Acest raport prezintă activitățile desfășurate pentru menținerea și optimizarea website-ului dvs. Veți regăsi informații detaliate despre actualizări, securitate, performanță și recomandări, pentru a asigura funcționarea optimă a platformei online."
- Default intro text (EN): "This report presents the activities carried out to maintain and optimize your website. You will find detailed information about updates, security, performance, and recommendations to ensure the optimal operation of your online platform."

---

### PAGE 3: Executive Overview (Redesigned)

This is the most important page — the client sees this first after the intro. Must be immediately understandable.

**Layout:** 4-row grid of metric cards with trend indicators

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   Privire de ansamblu globală                       │
│   ─────────────────────── (accent line)             │
│                                                     │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐            │
│  │ACTUALIZĂRI│ │  UPTIME  │ │ BACKUPS  │            │
│  │           │ │          │ │          │             │
│  │  4 total  │ │ 100,00%  │ │ 4 / 4 ✓ │            │
│  │  ↑25%     │ │ ─ 0%     │ │ ↑33%    │            │
│  └──────────┘ └──────────┘ └──────────┘            │
│                                                     │
│  ┌────────────────────┐ ┌────────────────────┐      │
│  │   PERFORMANȚĂ      │ │   SECURITATE       │      │
│  │  📱 63  💻 94      │ │   Score: 85/100    │      │
│  │  ↑5   ↑2           │ │   ↑10              │      │
│  └────────────────────┘ └────────────────────┘      │
│                                                     │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐            │
│  │ ANALYTICS│ │  SEARCH  │ │ DATABASE │            │
│  │          │ │ CONSOLE  │ │          │             │
│  │ 229 pgs  │ │ 70 clicks│ │ Cleaned  │            │
│  │ 127 users│ │ 1.6K imp │ │ -5.2 MB  │            │
│  │ ↓15%     │ │ ↑8%      │ │          │            │
│  └──────────┘ └──────────┘ └──────────┘            │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Card structure (DomPDF compatible — uses table layout):**

```html
<table class="overview-grid">
    <tr>
        <td class="overview-card">
            <div class="card-icon">{svg_icon}</div>
            <div class="card-label">ACTUALIZĂRI</div>
            <div class="card-value">4</div>
            <div class="card-sublabel">plugin-uri actualizate</div>
            <div class="card-trend trend-up">↑ 25% vs luna trecută</div>
        </td>
        <!-- ... more cards -->
    </tr>
</table>
```

**Card CSS:**
```css
.overview-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px 14px;
    vertical-align: top;
    width: 33%;
}
.card-label {
    font-size: 8pt;
    font-weight: bold;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.card-value {
    font-size: 22pt;
    font-weight: bold;
    color: #111827;
    margin-bottom: 2px;
}
.card-sublabel {
    font-size: 8pt;
    color: #9ca3af;
    margin-bottom: 6px;
}
.card-trend {
    font-size: 8pt;
    font-weight: bold;
}
.trend-up { color: #10b981; }
.trend-down { color: #ef4444; }
.trend-neutral { color: #6b7280; }
```

**Overview card data mapping:**

| Card | Primary Value | Sub-value | Trend Source |
|------|-------------|-----------|-------------|
| Updates | `{updates_count}` total | `{plugins} plugins, {themes} themes, {core} core` | Compare vs previous period |
| Uptime | `{uptime_percentage}%` | `{incidents_count} incidents` | Compare vs previous period |
| Backups | `{backup_successful}/{backup_total}` | `{backup_frequency}` | Compare vs previous period |
| Performance | Mobile: `{score_mobile}` Desktop: `{score_desktop}` | Score circles (SVG) | Compare vs previous test |
| Security | `{security_score}/100` | `{issues_count} issues found` | Compare vs previous scan |
| Analytics | `{pageviews}` pageviews, `{users}` users | `{bounce_rate}%` bounce rate | Compare vs previous period |
| Search Console | `{clicks}` clicks, `{impressions}` impressions | `CTR {ctr}%`, Position `{avg_position}` | Compare vs previous period |
| Database | `{tables_optimized}` tables optimized | `{size_saved}` saved | N/A (no trend) |

---

### PAGE 4: Updates Section

**Layout:** Summary bar + detailed update log table

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   ⟳ Actualizări                                     │
│   ─────────────── (accent line)                     │
│                                                     │
│   WordPress Core                                    │
│   ✅ WordPress este la ultima versiune.             │
│   ─────────────────────────────────────             │
│                                                     │
│   Jurnal actualizări                                │
│   Acestea sunt actualizările de plugin-uri          │
│   și teme efectuate în perioada raportată.          │
│                                                     │
│   ┌─────────────────────────────────────────┐       │
│   │ ACTUALIZARE    │ DATĂ       │ VERSIUNE  │       │
│   ├─────────────────────────────────────────┤       │
│   │ SAD Maintenance│ 31/01/2026 │ 2.5→2.7  │       │
│   │ Plugin X       │ 28/01/2026 │ 1.2→1.3  │       │
│   │ Theme Y        │ 15/01/2026 │ 3.0→3.1  │       │
│   │ WP Core        │ 03/01/2026 │ 6.8→6.9  │       │
│   └─────────────────────────────────────────┘       │
│                                                     │
│   ┌────────┐ ┌────────┐ ┌────────┐                 │
│   │Plugins │ │ Themes │ │  Core  │                 │
│   │   4    │ │   0    │ │   0    │                 │
│   │ ↑25%   │ │ ─ 0%   │ │ ─ 0%  │                 │
│   └────────┘ └────────┘ └────────┘                 │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Key improvement from reference:** The update log shows each individual update with date, version transition (old → new), and update type icon. This is the ModularDS style.

**Summary bar:** 3 mini-cards at bottom showing plugin/theme/core counts with trends vs previous period.

**Table CSS:**
```css
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9pt;
}
.data-table th {
    text-align: left;
    font-size: 8pt;
    font-weight: bold;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 8px 10px;
    border-bottom: 2px solid #e5e7eb;
}
.data-table td {
    padding: 8px 10px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
}
.data-table tr:nth-child(even) td {
    background-color: #f9fafb;
}
.version-arrow {
    color: #9ca3af;
}
.version-new {
    font-weight: bold;
    color: #111827;
}
```

---

### PAGE 5: Uptime Monitoring

**Layout:** Big uptime percentage + response time chart + incident table

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   ⬆ Monitorizare timp de funcționare                │
│   ──────────────────────────────── (accent line)    │
│                                                     │
│   ┌──────────────────────────────────────────┐      │
│   │  Timp de funcționare mediu               │      │
│   │                                          │      │
│   │         100,00 %          ─ 0% vs prev   │      │
│   │                                          │      │
│   │  [===== response time chart (SVG) =====] │      │
│   │                                          │      │
│   │  [==== uptime bar (green/red/gray) ====] │      │
│   └──────────────────────────────────────────┘      │
│                                                     │
│   ┌────────┐ ┌────────┐ ┌────────┐                 │
│   │Avg Resp│ │Downtime│ │Incidents│                │
│   │  82ms  │ │  0 min │ │   0    │                 │
│   │ ↓12%   │ │ ─      │ │ ─     │                 │
│   └────────┘ └────────┘ └────────┘                 │
│                                                     │
│   Modificări activitate                             │
│   ┌─────────────────────────────────────────┐       │
│   │ STARE    │ DE LA      │PÂNĂ LA │ DURATĂ │       │
│   ├─────────────────────────────────────────┤       │
│   │✅ FUNCȚ. │ 01/01 00:00│31/01   │30d 23h │       │
│   └─────────────────────────────────────────┘       │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Response time chart:** SVG-based line chart showing daily average response time. DomPDF renders simple SVG well.

**Chart SVG approach:**
```blade
<svg width="100%" height="120" viewBox="0 0 500 120">
    <!-- Y-axis labels -->
    <text x="0" y="15" font-size="7" fill="#9ca3af">200ms</text>
    <text x="0" y="55" font-size="7" fill="#9ca3af">100ms</text>
    <text x="0" y="95" font-size="7" fill="#9ca3af">0ms</text>
    
    <!-- Grid lines -->
    <line x1="30" y1="10" x2="490" y2="10" stroke="#f3f4f6" stroke-width="0.5"/>
    <line x1="30" y1="50" x2="490" y2="50" stroke="#f3f4f6" stroke-width="0.5"/>
    <line x1="30" y1="90" x2="490" y2="90" stroke="#f3f4f6" stroke-width="0.5"/>
    
    <!-- Data line (generated from daily averages) -->
    <polyline
        points="{{ $chartPoints }}"
        fill="none"
        stroke="{{ $primaryColor }}"
        stroke-width="1.5"
    />
    
    <!-- Area fill -->
    <polygon
        points="{{ $areaPoints }}"
        fill="{{ $primaryColor }}"
        fill-opacity="0.1"
    />
</svg>
```

**Uptime bar (simple horizontal bar):**
```blade
<div style="width: 100%; height: 10px; background: #e5e7eb; border-radius: 5px; overflow: hidden;">
    @foreach($uptimeSegments as $segment)
        <div style="float: left; width: {{ $segment['width'] }}%; height: 10px; background: {{ $segment['color'] }};"></div>
    @endforeach
</div>
```

Segment colors:
- `#10b981` (green): uptime 80-100%
- `#f59e0b` (orange): uptime 50-79%
- `#ef4444` (red): uptime 0-49%
- `#9ca3af` (gray): unknown/no data

---

### PAGE 6: Backups

**Layout:** Status summary + backup history list

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   💾 Copii de rezervă                               │
│   ──────────────────── (accent line)                │
│                                                     │
│   ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐      │
│   │Status  │ │Frecvență│ │Reușite │ │Eșuate  │     │
│   │ ✅ On  │ │Săpt.   │ │   4    │ │   0    │     │
│   │        │ │        │ │ ↑33%   │ │ ─      │     │
│   └────────┘ └────────┘ └────────┘ └────────┘      │
│                                                     │
│   Istoric copii de rezervă                          │
│   ┌─────────────────────────────────────────┐       │
│   │ DATĂ       │ DIMENSIUNE │ DESTINAȚIE │ST│       │
│   ├─────────────────────────────────────────┤       │
│   │ 28/01/2026 │ 245 MB     │ S3         │✅│       │
│   │ 21/01/2026 │ 243 MB     │ S3         │✅│       │
│   │ 14/01/2026 │ 241 MB     │ S3         │✅│       │
│   │ 07/01/2026 │ 240 MB     │ S3         │✅│       │
│   └─────────────────────────────────────────┘       │
│                                                     │
│   Dimensiune totală stocată: 969 MB                 │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**New vs current:** The current backup section only shows "Activat / Săptămânal / 0 backups" — very sparse. The redesign shows actual backup history with dates, sizes, destinations, and status indicators.

---

### PAGE 7-8: Analytics (2 pages)

**Page 7: Traffic overview + chart + summary cards**

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   📊 Analitică                                      │
│   ────────────── (accent line)                      │
│                                                     │
│   ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐      │
│   │ Pagini │ │Utiliz. │ │Bounce  │ │Durată  │      │
│   │  229   │ │  127   │ │45,08%  │ │ 1m31s  │      │
│   │ ↓15%   │ │ ↓22%   │ │ ↑3%    │ │ ↑8%    │      │
│   └────────┘ └────────┘ └────────┘ └────────┘      │
│                                                     │
│   [========= Daily users chart (SVG) =========]     │
│                                                     │
│   ┌──────────────────┐ ┌──────────────────┐         │
│   │  Utilizatori     │ │ Distribuție disp. │        │
│   │  Noi: 111 86.7%  │ │ Mobile: 49 43.4% │        │
│   │  Rec.: 10  7.8%  │ │ Desktop: 64 56.6%│        │
│   │  [=== bar ===]   │ │ [=== bar ===]    │        │
│   └──────────────────┘ └──────────────────┘         │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Page 8: Breakdown tables (channels, top pages, cities, countries)**

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   ┌──────────────────┐ ┌──────────────────┐         │
│   │Canal de origine  │ │Conținut popular  │         │
│   │                  │ │                  │         │
│   │Organic  60 53.1% │ │ /          62 40%│         │
│   │Direct   50 44.3% │ │ /malpraxis 28 18%│         │
│   │Unassign  2  1.8% │ │ /incetarea 26 17%│         │
│   │Referral  1  0.9% │ │ /servicii  22 14%│         │
│   │                  │ │ /contact   16 10%│         │
│   └──────────────────┘ └──────────────────┘         │
│                                                     │
│   ┌──────────────────┐ ┌──────────────────┐         │
│   │Orașe de origine  │ │Țări de origine   │         │
│   │                  │ │                  │         │
│   │Bucharest 45 52%  │ │Romania    64 60% │         │
│   │(not set) 17 20%  │ │Germany    26 24% │         │
│   │Butzbach  12 14%  │ │USA         9  8% │         │
│   │Boardman   7  8%  │ │China       6  6% │         │
│   │Cluj-Nap.  5  6%  │ │France      2  2% │         │
│   └──────────────────┘ └──────────────────┘         │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Layout for 2x2 table grid:**
```css
.quad-grid {
    display: table;
    width: 100%;
    border-spacing: 10px;
}
.quad-grid-row {
    display: table-row;
}
.quad-grid-cell {
    display: table-cell;
    width: 50%;
    vertical-align: top;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 14px;
}
```

---

### PAGE 9-10: Search Console (2 pages)

**Page 9: KPI cards + chart + top queries table**

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   🔍 Google Console de Căutare                      │
│   ──────────────────────────── (accent line)        │
│                                                     │
│   ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐      │
│   │Clicuri │ │Impresii│ │CTR med.│ │Poz.med.│      │
│   │  70    │ │  1,6K  │ │ 4,30%  │ │  9,30  │      │
│   │ ↑12%   │ │ ↑8%    │ │ ↑0.5%  │ │ ↓1.2   │      │
│   └────────┘ └────────┘ └────────┘ └────────┘      │
│                                                     │
│   [== clicks/impressions/CTR/position chart (SVG)]  │
│                                                     │
│   Top 10 căutări                                    │
│   ┌─────────────────────────────────────────┐       │
│   │ CĂUTARE          │CLIC│IMPR│CTR  │POZ  │       │
│   ├─────────────────────────────────────────┤       │
│   │ manuela sirbu    │  8 │ 55 │14.5%│ 7.3 │       │
│   │ avocat malpraxis │  4 │145 │ 2.8%│ 5.9 │       │
│   │ ...              │    │    │     │     │       │
│   └─────────────────────────────────────────┘       │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Page 10: Top pages, countries, devices, top dates**

Same 2x2 grid layout as Analytics page 2, but with Search Console breakdown tables.

---

### PAGE 11: Performance (PageSpeed)

**Layout:** Two side-by-side performance panels (mobile + desktop)

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   ⚡ Performanță                                    │
│   ──────────────── (accent line)                    │
│                                                     │
│   ┌─────────────────┐  ┌─────────────────┐          │
│   │  📱 Mobile      │  │  💻 Desktop     │          │
│   │  Actualizat:    │  │  Actualizat:    │          │
│   │  01 feb 2026    │  │  01 feb 2026    │          │
│   │                 │  │                 │          │
│   │     [63]        │  │     [94]        │          │
│   │   (SVG circle)  │  │   (SVG circle)  │          │
│   │    ↑5 vs prev   │  │    ↑2 vs prev   │          │
│   │                 │  │                 │          │
│   │ ▲ FCP    3.8s   │  │ ● FCP    0.8s   │          │
│   │ ■ SI     5.7s   │  │ ● SI     0.8s   │          │
│   │ ▲ LCP    8.5s   │  │ ■ LCP    1.5s   │          │
│   │ ▲ TTI    8.5s   │  │ ● TTI    1.5s   │          │
│   │ ● TBT    110ms  │  │ ● TBT    70ms   │          │
│   │ ● CLS    0      │  │ ● CLS    0.019  │          │
│   └─────────────────┘  └─────────────────┘          │
│                                                     │
│   Legendă:  ▲ 0-49  ■ 50-89  ● 90-100              │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Score circle SVG (DomPDF-compatible):**
```blade
@php
    $score = $data['score'];
    $radius = 35;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference - ($score / 100) * $circumference;
    $color = $score >= 90 ? '#10b981' : ($score >= 50 ? '#f59e0b' : '#ef4444');
@endphp
<svg width="90" height="90" viewBox="0 0 90 90">
    <!-- Background circle -->
    <circle cx="45" cy="45" r="{{ $radius }}" fill="none" stroke="#e5e7eb" stroke-width="5"/>
    <!-- Score arc -->
    <circle cx="45" cy="45" r="{{ $radius }}" fill="none"
        stroke="{{ $color }}" stroke-width="5"
        stroke-dasharray="{{ $circumference }}"
        stroke-dashoffset="{{ $offset }}"
        transform="rotate(-90 45 45)"
        stroke-linecap="round"/>
    <!-- Score text -->
    <text x="45" y="50" text-anchor="middle" font-size="20" font-weight="bold" fill="{{ $color }}">{{ $score }}</text>
</svg>
```

**Metric row with status indicator:**
```blade
@php
    $indicatorColor = $value <= $goodThreshold ? '#10b981' : ($value <= $mediumThreshold ? '#f59e0b' : '#ef4444');
    $indicatorShape = $value <= $goodThreshold ? '●' : ($value <= $mediumThreshold ? '■' : '▲');
@endphp
<div class="metric-row">
    <span style="color: {{ $indicatorColor }}; font-weight: bold;">{{ $indicatorShape }}</span>
    <span class="metric-label">{{ $label }}</span>
    <span class="metric-value" style="color: {{ $indicatorColor }};">{{ $formattedValue }}</span>
</div>
```

---

### PAGE 12: Security (NEW SECTION)

**Layout:** Security score + findings summary + recommendations

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   🛡 Securitate                                     │
│   ──────────────── (accent line)                    │
│                                                     │
│   ┌──────────────────────────────────────────┐      │
│   │  Scor securitate                         │      │
│   │                                          │      │
│   │      [85/100]  (SVG circle)              │      │
│   │      ↑10 vs luna trecută                 │      │
│   │                                          │      │
│   │  Probleme: 2 critice, 3 avertismente     │      │
│   └──────────────────────────────────────────┘      │
│                                                     │
│   Rezultate verificare                              │
│   ┌─────────────────────────────────────────┐       │
│   │ VERIFICARE           │ STATUS │ DETALII │       │
│   ├─────────────────────────────────────────┤       │
│   │ SSL valid            │  ✅    │ exp 2027│       │
│   │ WordPress actualizat │  ✅    │ v6.9    │       │
│   │ Pluginuri vulnerab.  │  ⚠️    │ 1 found │       │
│   │ Firewall activ       │  ✅    │ CF WAF  │       │
│   │ Backup activ         │  ✅    │ weekly  │       │
│   │ Login brute force    │  ⚠️    │ 12 att. │       │
│   └─────────────────────────────────────────┘       │
│                                                     │
│   Recomandări:                                      │
│   • Actualizați pluginul X la versiunea Y           │
│   • Activați 2FA pentru conturile admin             │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Data source:** `security_scans` table (latest scan for the period) + `security_issues` + `security_recommendations`.

**Conditional display:** Only shown if Security module is active for the site (checked via `site_module_configs`).

---

### PAGE 13: Database Health (NEW SECTION)

**Layout:** Optimization summary + table details

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│   🗄 Baza de date                                   │
│   ──────────────── (accent line)                    │
│                                                     │
│   ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐      │
│   │DB Size │ │Tables  │ │Optimiz.│ │Saved   │      │
│   │ 85 MB  │ │  42    │ │ Da     │ │ 5.2 MB │      │
│   └────────┘ └────────┘ └────────┘ └────────┘      │
│                                                     │
│   Curățare efectuată                                │
│   ┌─────────────────────────────────────────┐       │
│   │ CATEGORIE           │ ȘTERS  │ SALVAT   │       │
│   ├─────────────────────────────────────────┤       │
│   │ Post revisions      │  234   │  1.8 MB  │       │
│   │ Auto-drafts         │   12   │  0.3 MB  │       │
│   │ Trashed posts       │    5   │  0.1 MB  │       │
│   │ Spam comments       │   89   │  0.5 MB  │       │
│   │ Transient options   │  156   │  2.1 MB  │       │
│   │ Orphaned meta       │   43   │  0.4 MB  │       │
│   └─────────────────────────────────────────┘       │
│                                                     │
│   Total eliberat: 5.2 MB                            │
│   Ultima optimizare: 15/01/2026                     │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

**Data source:** `database_cleanups` table (if it exists) or the Database Cleanup module logs.

**Conditional display:** Only shown if Database Cleanup module ran during the reporting period. If no cleanup was performed, show "Nicio optimizare în această perioadă" / "No optimization during this period."

---

### PAGE 14: Closing Page

**Layout:** Thank you message + company branding

```
┌─────────────────────────────────────────────────────┐
│ [Header]                                            │
│─────────────────────────────────────────────────────│
│                                                     │
│                                                     │
│                                                     │
│                                                     │
│                                                     │
│   Mulțumim pentru                                   │
│   colaborare!                                       │
│                                                     │
│   {closing_text from template}                      │
│                                                     │
│                                                     │
│                                                     │
│                                                     │
│                                                     │
│                                                     │
│         ┌───────────────────┐                       │
│         │  COMPANY LOGO     │                       │
│         │  (large)          │                       │
│         └───────────────────┘                       │
│                                                     │
│─────────────────────────────────────────────────────│
│ [Footer]                                            │
└─────────────────────────────────────────────────────┘
```

---

## 5. New Sections Detail

### 5.1 Security Section

**Section key:** `security`

**Available when:** `site_module_configs.modules.security.enabled = true`

**Data gathering method: `gatherSecurityData()`**

```php
public function gatherSecurityData(Site $site, Carbon $periodStart, Carbon $periodEnd): ?array
{
    // Check if security module is active
    $moduleConfig = $site->moduleConfig;
    if (!$moduleConfig || !($moduleConfig->modules['security']['enabled'] ?? false)) {
        return null;
    }

    // Get latest scan in period
    $latestScan = $site->securityScans()
        ->whereBetween('created_at', [$periodStart, $periodEnd])
        ->latest()
        ->first();

    if (!$latestScan) {
        return ['no_data' => true];
    }

    // Get previous period's latest scan for comparison
    $prevPeriodStart = $periodStart->copy()->subMonth();
    $prevScan = $site->securityScans()
        ->whereBetween('created_at', [$prevPeriodStart, $periodStart])
        ->latest()
        ->first();

    // Get issues from latest scan
    $issues = $site->securityIssues()
        ->where('security_scan_id', $latestScan->id)
        ->get();

    $recommendations = $site->securityRecommendations()
        ->where('security_scan_id', $latestScan->id)
        ->where('status', '!=', 'resolved')
        ->get();

    return [
        'score' => $latestScan->score,
        'prev_score' => $prevScan?->score,
        'score_change' => $prevScan ? $latestScan->score - $prevScan->score : null,
        'scanned_at' => $latestScan->created_at,
        'critical_count' => $issues->where('severity', 'critical')->count(),
        'warning_count' => $issues->where('severity', 'warning')->count(),
        'info_count' => $issues->where('severity', 'info')->count(),
        'checks' => $this->formatSecurityChecks($latestScan),
        'recommendations' => $recommendations->take(5)->map(fn($r) => [
            'text' => $r->recommendation,
            'priority' => $r->priority,
        ])->toArray(),
    ];
}
```

### 5.2 Database Health Section

**Section key:** `database`

**Available when:** Database Cleanup module ran during the period. Unlike Security (which depends on module being "active"), Database shows if ANY cleanup happened.

**Data gathering method: `gatherDatabaseData()`**

```php
public function gatherDatabaseData(Site $site, Carbon $periodStart, Carbon $periodEnd): ?array
{
    // Query database cleanup logs for the period
    $cleanups = $site->databaseCleanups()
        ->whereBetween('created_at', [$periodStart, $periodEnd])
        ->get();

    if ($cleanups->isEmpty()) {
        return null; // Section won't appear
    }

    $latestCleanup = $cleanups->last();

    return [
        'db_size_mb' => $latestCleanup->db_size_after_mb ?? null,
        'table_count' => $latestCleanup->table_count ?? null,
        'was_optimized' => true,
        'total_saved_mb' => $cleanups->sum('space_saved_mb'),
        'last_cleanup_date' => $latestCleanup->created_at,
        'categories' => [
            ['name' => 'Post revisions', 'name_ro' => 'Revizuiri articole', 'deleted' => $cleanups->sum('revisions_deleted'), 'saved_mb' => $cleanups->sum('revisions_saved_mb')],
            ['name' => 'Auto-drafts', 'name_ro' => 'Ciorne automate', 'deleted' => $cleanups->sum('auto_drafts_deleted'), 'saved_mb' => $cleanups->sum('auto_drafts_saved_mb')],
            ['name' => 'Trashed posts', 'name_ro' => 'Articole la gunoi', 'deleted' => $cleanups->sum('trashed_deleted'), 'saved_mb' => $cleanups->sum('trashed_saved_mb')],
            ['name' => 'Spam comments', 'name_ro' => 'Comentarii spam', 'deleted' => $cleanups->sum('spam_deleted'), 'saved_mb' => $cleanups->sum('spam_saved_mb')],
            ['name' => 'Transient options', 'name_ro' => 'Opțiuni temporare', 'deleted' => $cleanups->sum('transients_deleted'), 'saved_mb' => $cleanups->sum('transients_saved_mb')],
            ['name' => 'Orphaned meta', 'name_ro' => 'Meta orfane', 'deleted' => $cleanups->sum('orphaned_deleted'), 'saved_mb' => $cleanups->sum('orphaned_saved_mb')],
        ],
    ];
}
```

**Note:** If the `database_cleanups` table does not exist yet, this section should be implemented alongside the Database Cleanup module. The gathering method above assumes a specific table structure — adapt column names to match the actual schema when implemented.

---

## 6. Period Comparison System

This is the **key differentiator** from the current report. Every metric shows how it changed vs the previous period.

### 6.1 Comparison Logic

For **monthly reports** (the default):
- Current period: the month being reported (e.g., January 2026)
- Previous period: the month before (e.g., December 2025)

For **weekly reports**:
- Current period: the reported week
- Previous period: the week before

For **custom periods**:
- Current period: the custom date range
- Previous period: same duration before the start date

### 6.2 Comparison Data Sources

| Metric | Current Source | Previous Source |
|--------|--------------|----------------|
| Uptime % | `site_monthly_snapshots` current month | `site_monthly_snapshots` previous month |
| Avg response time | snapshot | snapshot |
| Backup count | snapshot | snapshot |
| Updates count | snapshot | snapshot |
| Performance scores | snapshot | snapshot |
| Analytics (users, sessions, pageviews) | snapshot | snapshot |
| Search Console (clicks, impressions, CTR, position) | snapshot | snapshot |
| Security score | latest scan current period | latest scan previous period |

**For weekly/custom periods** where snapshots don't exist, use raw table queries.

### 6.3 Trend Calculation Helper

Add to `ReportGeneratorService`:

```php
protected function calculateTrend($current, $previous): array
{
    if ($previous === null || $previous === 0) {
        return [
            'direction' => 'neutral',
            'value' => null,
            'display' => '─',
            'color' => '#6b7280',
        ];
    }

    $change = $current - $previous;
    $percentChange = ($change / abs($previous)) * 100;

    if (abs($percentChange) < 0.5) {
        return [
            'direction' => 'neutral',
            'value' => 0,
            'display' => '─ 0%',
            'color' => '#6b7280',
        ];
    }

    $isPositive = $change > 0;

    return [
        'direction' => $isPositive ? 'up' : 'down',
        'value' => round($percentChange, 1),
        'display' => ($isPositive ? '↑' : '↓') . ' ' . abs(round($percentChange, 1)) . '%',
        'color' => $isPositive ? '#10b981' : '#ef4444',
    ];
}

// For metrics where lower is better (response time, bounce rate, avg position)
protected function calculateTrendInverse($current, $previous): array
{
    $trend = $this->calculateTrend($current, $previous);
    
    if ($trend['direction'] === 'up') {
        $trend['color'] = '#ef4444'; // Going up is bad
    } elseif ($trend['direction'] === 'down') {
        $trend['color'] = '#10b981'; // Going down is good
    }
    
    return $trend;
}
```

### 6.4 Blade Component for Trend Display

```blade
{{-- resources/views/reports/components/trend.blade.php --}}
@if($trend['value'] !== null)
    <span style="font-size: 8pt; font-weight: bold; color: {{ $trend['color'] }};">
        {{ $trend['display'] }}
        @if($label ?? false)
            <span style="font-weight: normal; color: #9ca3af;"> vs {{ $vsLabel ?? __('report.vs_previous') }}</span>
        @endif
    </span>
@else
    <span style="font-size: 8pt; color: #9ca3af;">─</span>
@endif
```

---

## 7. Data Sources & Gathering

### 7.1 Updated `ReportGeneratorService::generate()` Flow

```php
public function generate(Report $report): string
{
    $site = $report->site;
    $template = $report->reportTemplate;
    $schedule = $report->reportSchedule;
    $config = $site->reportConfig; // SiteReportConfig
    
    $periodStart = $report->period_start;
    $periodEnd = $report->period_end;
    $language = $config?->language ?? 'ro';
    
    // Load previous period snapshot for comparisons
    $currentSnapshot = $this->getSnapshot($site, $periodStart, $periodEnd);
    $previousSnapshot = $this->getPreviousSnapshot($site, $periodStart, $periodEnd);
    
    // Gather data per section (only for enabled sections)
    $sections = $template->sections ?? [];
    $data = [];
    
    if (in_array('overview', $sections)) {
        $data['overview'] = $this->gatherOverviewData($site, $periodStart, $periodEnd, $currentSnapshot, $previousSnapshot);
    }
    if (in_array('updates', $sections)) {
        $data['updates'] = $this->gatherUpdatesData($site, $periodStart, $periodEnd, $previousSnapshot);
    }
    if (in_array('uptime', $sections)) {
        $data['uptime'] = $this->gatherUptimeData($site, $periodStart, $periodEnd, $currentSnapshot, $previousSnapshot);
    }
    if (in_array('backups', $sections)) {
        $data['backups'] = $this->gatherBackupsData($site, $periodStart, $periodEnd, $currentSnapshot, $previousSnapshot);
    }
    if (in_array('analytics', $sections)) {
        $data['analytics'] = $this->gatherAnalyticsData($site, $periodStart, $periodEnd, $currentSnapshot, $previousSnapshot);
    }
    if (in_array('search_console', $sections)) {
        $data['search_console'] = $this->gatherSearchConsoleData($site, $periodStart, $periodEnd, $currentSnapshot, $previousSnapshot);
    }
    if (in_array('performance', $sections)) {
        $data['performance'] = $this->gatherPerformanceData($site, $periodStart, $periodEnd, $previousSnapshot);
    }
    if (in_array('security', $sections)) {
        $data['security'] = $this->gatherSecurityData($site, $periodStart, $periodEnd);
    }
    if (in_array('database', $sections)) {
        $data['database'] = $this->gatherDatabaseData($site, $periodStart, $periodEnd);
    }
    
    // Branding
    $branding = [
        'company_name' => $template->company_name,
        'company_logo' => $template->company_logo_path ? $this->resolveLogoPath($template->company_logo_path) : null,
        'company_website' => $template->company_website,
        'primary_color' => $template->primary_color ?? '#7C3AED',
        'client_name' => $schedule?->client_name ?? $site->client?->name ?? $site->name,
        'client_logo' => $this->resolveClientLogo($schedule, $site),
    ];
    
    // Render PDF
    $html = view('reports.maintenance-report', [
        'site' => $site,
        'data' => $data,
        'sections' => $sections,
        'branding' => $branding,
        'language' => $language,
        'report' => $report,
        'periodStart' => $periodStart,
        'periodEnd' => $periodEnd,
        'introText' => $template->intro_text ?? __('report.default_intro', [], $language),
        'closingText' => $template->closing_text ?? __('report.default_closing', [], $language),
    ])->render();
    
    // Generate PDF via DomPDF
    $pdf = app('dompdf.wrapper');
    $pdf->loadHTML($html);
    $pdf->setPaper('a4', 'portrait');
    $pdf->setOptions([
        'isHtml5ParserEnabled' => true,
        'isRemoteEnabled' => true,
        'defaultFont' => 'Helvetica',
        'dpi' => 150,
    ]);
    
    $output = $pdf->output();
    
    // Store
    $filename = "report-{$site->id}-" . now()->format('YmdHis') . '.pdf';
    $path = "reports/{$site->id}/{$filename}";
    Storage::disk('local')->put($path, $output);
    
    return $path;
}
```

### 7.2 Snapshot Helpers

```php
protected function getSnapshot(Site $site, Carbon $periodStart, Carbon $periodEnd): ?SiteMonthlySnapshot
{
    return SiteMonthlySnapshot::where('site_id', $site->id)
        ->where('year', $periodStart->year)
        ->where('month', $periodStart->month)
        ->first();
}

protected function getPreviousSnapshot(Site $site, Carbon $periodStart, Carbon $periodEnd): ?SiteMonthlySnapshot
{
    $prevMonth = $periodStart->copy()->subMonth();
    return SiteMonthlySnapshot::where('site_id', $site->id)
        ->where('year', $prevMonth->year)
        ->where('month', $prevMonth->month)
        ->first();
}
```

### 7.3 Updated Gathering Methods (with comparison)

Each gathering method now accepts `$previousSnapshot` and returns trend data alongside current values.

**Example — `gatherOverviewData()`:**

```php
public function gatherOverviewData(Site $site, Carbon $start, Carbon $end, ?SiteMonthlySnapshot $current, ?SiteMonthlySnapshot $previous): array
{
    return [
        'updates' => [
            'count' => $current?->updates_applied ?? 0,
            'trend' => $this->calculateTrend($current?->updates_applied, $previous?->updates_applied),
        ],
        'uptime' => [
            'percentage' => $current?->uptime_percentage,
            'trend' => $this->calculateTrend($current?->uptime_percentage, $previous?->uptime_percentage),
            'incidents' => $current?->incidents_count ?? 0,
        ],
        'backups' => [
            'successful' => $current?->backup_successful ?? 0,
            'total' => $current?->backup_total ?? 0,
            'trend' => $this->calculateTrend($current?->backup_successful, $previous?->backup_successful),
        ],
        'performance' => [
            'mobile' => $current?->performance_score_mobile,
            'desktop' => $current?->performance_score_desktop,
            'mobile_trend' => $this->calculateTrend($current?->performance_score_mobile, $previous?->performance_score_mobile),
            'desktop_trend' => $this->calculateTrend($current?->performance_score_desktop, $previous?->performance_score_desktop),
        ],
        'security' => [
            'score' => $current?->security_score_avg,
            'trend' => $this->calculateTrend($current?->security_score_avg, $previous?->security_score_avg),
        ],
        'analytics' => [
            'pageviews' => $current?->analytics_pageviews,
            'users' => $current?->analytics_users,
            'pageviews_trend' => $this->calculateTrend($current?->analytics_pageviews, $previous?->analytics_pageviews),
            'users_trend' => $this->calculateTrend($current?->analytics_users, $previous?->analytics_users),
        ],
        'search_console' => [
            'clicks' => $current?->search_clicks,
            'impressions' => $current?->search_impressions,
            'clicks_trend' => $this->calculateTrend($current?->search_clicks, $previous?->search_clicks),
            'impressions_trend' => $this->calculateTrend($current?->search_impressions, $previous?->search_impressions),
        ],
        'database' => [
            'was_cleaned' => $this->wasDatabaseCleanedInPeriod($site, $start, $end),
        ],
    ];
}
```

---

## 8. Template Sections Configuration

### 8.1 Updated Available Sections

Add `security` and `database` to the `ReportTemplate.sections` JSON array.

**Full sections list:**

```php
// In ReportTemplate model or config
const AVAILABLE_SECTIONS = [
    'overview',        // Executive summary (always recommended)
    'updates',         // Plugin/theme/core updates
    'uptime',          // Uptime monitoring
    'backups',         // Backup status
    'analytics',       // Google Analytics
    'search_console',  // Google Search Console
    'performance',     // PageSpeed scores + CWV
    'security',        // Security scan results (optional)
    'database',        // Database health/cleanup (optional)
];
```

### 8.2 Section Labels for UI

```php
// For ReportTemplatesSettings Livewire component
const SECTION_LABELS = [
    'overview'       => ['en' => 'Executive Overview', 'ro' => 'Privire de ansamblu'],
    'updates'        => ['en' => 'Updates', 'ro' => 'Actualizări'],
    'uptime'         => ['en' => 'Uptime Monitoring', 'ro' => 'Monitorizare uptime'],
    'backups'        => ['en' => 'Backups', 'ro' => 'Copii de rezervă'],
    'analytics'      => ['en' => 'Google Analytics', 'ro' => 'Analitică'],
    'search_console' => ['en' => 'Search Console', 'ro' => 'Google Console de Căutare'],
    'performance'    => ['en' => 'Performance', 'ro' => 'Performanță'],
    'security'       => ['en' => 'Security', 'ro' => 'Securitate'],
    'database'       => ['en' => 'Database Health', 'ro' => 'Baza de date'],
];
```

### 8.3 Section Dependencies

Some sections only render if the corresponding module is active AND has data:

| Section | Always shows? | Fallback if no data |
|---------|-------------|---------------------|
| overview | Yes | Shows available data, "N/A" for missing |
| updates | Yes | "No updates during this period" |
| uptime | Yes | "Monitoring not configured" |
| backups | Yes | "Backups not configured" |
| analytics | Yes | "Google Analytics not connected" |
| search_console | Yes | "Search Console not connected" |
| performance | Yes | "No performance tests in this period" |
| security | Only if module active | Section omitted entirely |
| database | Only if cleanup ran | Section omitted entirely |

---

## 9. Localization Updates

### 9.1 New Keys for `lang/ro/report.php`

Add these to the existing file:

```php
return [
    // Existing keys (keep all current ones) ...
    
    // Cover page
    'cover_title' => 'Raportul lunar pentru',
    'cover_generated_at' => ':date la :time',
    
    // Section headings
    'section_overview' => 'Privire de ansamblu globală',
    'section_updates' => 'Actualizări',
    'section_uptime' => 'Monitorizare timp de funcționare',
    'section_backups' => 'Copii de rezervă',
    'section_analytics' => 'Analitică',
    'section_search_console' => 'Google Console de Căutare',
    'section_performance' => 'Performanță',
    'section_security' => 'Securitate',
    'section_database' => 'Baza de date',
    
    // Trend labels
    'vs_previous' => 'vs luna trecută',
    'vs_previous_week' => 'vs săpt. trecută',
    'trend_no_data' => 'fără date anterioare',
    
    // Updates section
    'updates_wordpress_core' => 'WordPress Core',
    'updates_wp_latest' => 'WordPress este la ultima versiune.',
    'updates_wp_outdated' => 'WordPress necesită actualizare.',
    'updates_log_title' => 'Jurnal actualizări',
    'updates_log_description' => 'Acestea sunt actualizările de plugin-uri și teme efectuate în perioada raportată.',
    'updates_no_updates' => 'Nicio actualizare în această perioadă.',
    'updates_plugins' => 'Plugin-uri',
    'updates_themes' => 'Teme',
    'updates_core' => 'WordPress',
    'updates_updated_times' => 'Actualizat de :count ori',
    
    // Uptime section
    'uptime_average' => 'Timp de funcționare mediu',
    'uptime_response_time' => 'Timp de răspuns mediu',
    'uptime_downtime' => 'Timp nefuncțional',
    'uptime_incidents' => 'Incidente',
    'uptime_activity_changes' => 'Modificări activitate',
    'uptime_status_up' => 'FUNCȚIONEAZĂ',
    'uptime_status_down' => 'NEFUNCȚIONAL',
    'uptime_from' => 'De la',
    'uptime_to' => 'Până la',
    'uptime_duration' => 'Durată',
    'uptime_no_monitoring' => 'Monitorizarea nu este configurată.',
    
    // Backups section
    'backups_enabled' => 'Activat',
    'backups_disabled' => 'Dezactivat',
    'backups_frequency' => 'Periodicitate',
    'backups_successful' => 'Reușite',
    'backups_failed' => 'Eșuate',
    'backups_history' => 'Istoric copii de rezervă',
    'backups_date' => 'Dată',
    'backups_size' => 'Dimensiune',
    'backups_destination' => 'Destinație',
    'backups_status' => 'Status',
    'backups_total_stored' => 'Dimensiune totală stocată',
    'backups_not_configured' => 'Copiile de rezervă nu sunt configurate.',
    'backups_no_backups' => 'Nicio copie de rezervă în această perioadă.',
    
    // Analytics section
    'analytics_pageviews' => 'Pagini vizualizate',
    'analytics_users' => 'Utilizatori',
    'analytics_bounce_rate' => 'Rata de respingere',
    'analytics_session_duration' => 'Durata sesiunii',
    'analytics_new_users' => 'Utilizatori noi',
    'analytics_returning_users' => 'Recurent',
    'analytics_device_distribution' => 'Distribuție pe dispozitiv',
    'analytics_mobile' => 'Smartphone',
    'analytics_desktop' => 'Calculator',
    'analytics_tablet' => 'Tabletă',
    'analytics_traffic_sources' => 'Canal de origine',
    'analytics_top_pages' => 'Conținut popular',
    'analytics_top_cities' => 'Orașe de origine',
    'analytics_top_countries' => 'Țări de origine',
    'analytics_channel' => 'Canal',
    'analytics_page' => 'Pagină',
    'analytics_city' => 'Oraș',
    'analytics_country' => 'Țară',
    'analytics_user_count' => 'Nr. utilizatori',
    'analytics_visit_count' => 'Nr. vizite',
    'analytics_not_connected' => 'Google Analytics nu este conectat.',
    
    // Search Console section
    'search_total_clicks' => 'Total clicuri',
    'search_impressions' => 'Impresii',
    'search_avg_ctr' => 'CTR mediu',
    'search_avg_position' => 'Poziție medie',
    'search_top_queries' => 'Top 10 căutări',
    'search_top_pages' => 'Top 5 pagini',
    'search_top_countries' => 'Top 5 țări',
    'search_top_devices' => 'Dispozitive utilizate cel mai des',
    'search_top_dates' => 'Top 5 date',
    'search_query' => 'Căutare',
    'search_clicks' => 'Clicuri',
    'search_ctr' => 'CTR',
    'search_position' => 'Poziție',
    'search_device' => 'Dispozitiv',
    'search_date' => 'Dată',
    'search_device_mobile' => 'Telefon mobil',
    'search_device_desktop' => 'Calculator',
    'search_device_tablet' => 'Tabletă',
    'search_not_connected' => 'Google Console de Căutare nu este conectat.',
    
    // Performance section
    'performance_mobile' => 'Mobil',
    'performance_desktop' => 'Desktop',
    'performance_updated' => 'Actualizat',
    'performance_fcp' => 'First Contentful Paint',
    'performance_si' => 'Speed Index',
    'performance_lcp' => 'Largest Contentful Paint',
    'performance_tti' => 'Time to Interactive',
    'performance_tbt' => 'Total Blocking Time',
    'performance_cls' => 'Cumulative Layout Shift',
    'performance_legend' => 'Legendă',
    'performance_no_data' => 'Niciun test de performanță în această perioadă.',
    
    // Security section (NEW)
    'security_score' => 'Scor securitate',
    'security_issues_critical' => ':count critice',
    'security_issues_warning' => ':count avertismente',
    'security_issues_info' => ':count informaționale',
    'security_checks_title' => 'Rezultate verificare',
    'security_check' => 'Verificare',
    'security_check_status' => 'Status',
    'security_check_details' => 'Detalii',
    'security_recommendations' => 'Recomandări',
    'security_check_ssl' => 'Certificat SSL valid',
    'security_check_wp_updated' => 'WordPress actualizat',
    'security_check_plugins_vuln' => 'Pluginuri vulnerabile',
    'security_check_firewall' => 'Firewall activ',
    'security_check_backup' => 'Backup activ',
    'security_check_brute_force' => 'Protecție brute force',
    'security_not_active' => 'Modulul de securitate nu este activ.',
    
    // Database section (NEW)
    'database_size' => 'Dimensiune BD',
    'database_tables' => 'Tabele',
    'database_optimized' => 'Optimizat',
    'database_saved' => 'Eliberat',
    'database_cleanup_title' => 'Curățare efectuată',
    'database_category' => 'Categorie',
    'database_deleted' => 'Șterse',
    'database_space_saved' => 'Spațiu eliberat',
    'database_total_saved' => 'Total eliberat',
    'database_last_cleanup' => 'Ultima optimizare',
    'database_no_cleanup' => 'Nicio optimizare în această perioadă.',
    'database_yes' => 'Da',
    'database_no' => 'Nu',
    'database_revisions' => 'Revizuiri articole',
    'database_auto_drafts' => 'Ciorne automate',
    'database_trashed' => 'Articole la gunoi',
    'database_spam' => 'Comentarii spam',
    'database_transients' => 'Opțiuni temporare',
    'database_orphaned' => 'Meta orfane',
    
    // Closing page
    'closing_title' => 'Mulțumim pentru colaborare!',
    'default_intro' => 'Acest raport prezintă activitățile desfășurate pentru menținerea și optimizarea website-ului dvs. Veți regăsi informații detaliate despre actualizări, securitate, performanță și recomandări, pentru a asigura funcționarea optimă a platformei online.',
    'default_closing' => 'Acest raport marchează activitățile esențiale efectuate pentru a menține website-ul dvs. în siguranță și la cele mai bune standarde. Apreciem parteneriatul nostru și ne bucurăm să construim împreună o prezență online solidă. Dacă aveți nelămuriri sau idei de îmbunătățire, echipa noastră este mereu aici pentru a vă sprijini.',
    
    // Common
    'not_available' => 'N/A',
    'not_configured' => 'Neconfigurat',
    'page' => 'Pagină',
    'of' => 'din',
];
```

### 9.2 English Translations (`lang/en/report.php`)

Mirror all keys above with English values. Example key mappings:

```php
return [
    'cover_title' => 'Monthly report for',
    'section_overview' => 'Executive Overview',
    'section_updates' => 'Updates',
    'section_uptime' => 'Uptime Monitoring',
    'section_backups' => 'Backups',
    'section_analytics' => 'Analytics',
    'section_search_console' => 'Google Search Console',
    'section_performance' => 'Performance',
    'section_security' => 'Security',
    'section_database' => 'Database Health',
    'vs_previous' => 'vs last month',
    'vs_previous_week' => 'vs last week',
    // ... (translate all RO keys to EN)
];
```

---

## 10. File Structure

### 10.1 Updated Blade Files

```
resources/views/reports/
├── maintenance-report.blade.php          # Main wrapper (REWRITE)
├── styles.blade.php                      # All CSS (REWRITE)
├── components/
│   ├── trend.blade.php                   # NEW — trend indicator component
│   ├── metric-card.blade.php             # NEW — reusable metric card
│   ├── score-circle.blade.php            # NEW — SVG score circle
│   ├── data-table.blade.php              # NEW — styled table wrapper
│   ├── section-header.blade.php          # NEW — section title + accent line
│   └── chart-line.blade.php              # NEW — SVG line chart component
├── partials/
│   ├── cover.blade.php                   # REWRITE — split layout
│   ├── intro.blade.php                   # REWRITE — cleaner layout
│   ├── overview.blade.php                # REWRITE — card grid with trends
│   ├── updates.blade.php                 # REWRITE — log table + summary
│   ├── uptime.blade.php                  # REWRITE — chart + trends
│   ├── backups.blade.php                 # REWRITE — history table
│   ├── analytics-1.blade.php             # REWRITE — metrics + chart
│   ├── analytics-2.blade.php             # REWRITE — breakdown tables
│   ├── search-console-1.blade.php        # REWRITE — metrics + chart
│   ├── search-console-2.blade.php        # REWRITE — breakdown tables
│   ├── performance.blade.php             # REWRITE — dual panels
│   ├── security.blade.php               # NEW — security section
│   ├── database.blade.php               # NEW — database section
│   └── closing.blade.php                 # REWRITE — updated closing page
```

### 10.2 Updated Service Files

```
app/Services/
├── ReportGeneratorService.php            # MODIFY — add comparison logic,
│                                         #          new gathering methods,
│                                         #          chart data generators
```

### 10.3 New Helper: SVG Chart Data Generator

Add to `ReportGeneratorService` or create `app/Services/ReportChartService.php`:

```php
class ReportChartService
{
    /**
     * Generate SVG polyline points for a line chart
     * 
     * @param array $values Array of numeric values
     * @param int $width Chart width in SVG units
     * @param int $height Chart height in SVG units
     * @param int $paddingLeft Left padding for Y-axis labels
     * @param int $paddingBottom Bottom padding for X-axis labels
     * @return array ['line_points' => string, 'area_points' => string, 'y_max' => int]
     */
    public function generateLineChartPoints(
        array $values,
        int $width = 500,
        int $height = 120,
        int $paddingLeft = 35,
        int $paddingBottom = 15
    ): array {
        if (empty($values)) {
            return ['line_points' => '', 'area_points' => '', 'y_max' => 0];
        }

        $chartWidth = $width - $paddingLeft - 10;
        $chartHeight = $height - $paddingBottom - 10;
        $yMax = max($values) ?: 1;
        $count = count($values);
        $xStep = $count > 1 ? $chartWidth / ($count - 1) : $chartWidth;

        $points = [];
        foreach ($values as $i => $value) {
            $x = $paddingLeft + ($i * $xStep);
            $y = 5 + $chartHeight - (($value / $yMax) * $chartHeight);
            $points[] = round($x, 1) . ',' . round($y, 1);
        }

        $linePoints = implode(' ', $points);

        // Area: add bottom corners for polygon fill
        $firstX = $paddingLeft;
        $lastX = $paddingLeft + (($count - 1) * $xStep);
        $bottomY = 5 + $chartHeight;
        $areaPoints = $linePoints . " {$lastX},{$bottomY} {$firstX},{$bottomY}";

        return [
            'line_points' => $linePoints,
            'area_points' => $areaPoints,
            'y_max' => $yMax,
        ];
    }
}
```

---

## 11. Migration Changes

### 11.1 Update `report_templates` — Add new sections to default

No schema change needed. The `sections` JSON column already supports any array of strings. Just ensure the seeder/default includes the new sections:

```php
// Update default template seeder
ReportTemplate::updateOrCreate(
    ['is_default' => true],
    [
        'sections' => ['overview', 'updates', 'uptime', 'backups', 'analytics', 'search_console', 'performance', 'security', 'database'],
        // ... other defaults
    ]
);
```

### 11.2 Update `site_report_configs` — Add new toggles

```php
// New migration: add database section toggle
Schema::table('site_report_configs', function (Blueprint $table) {
    $table->boolean('show_database')->default(true)->after('show_cloudflare');
});
```

### 11.3 Ensure `site_monthly_snapshots` has all needed fields

Check that the existing migration includes:
- `security_score_avg` (decimal, nullable) — ✅ already in blueprint
- Cloudflare fields — ✅ already in blueprint

No new snapshot fields needed for database (it queries raw data, not snapshot).

---

## 12. Implementation Order

### Phase 1: CSS & Layout Foundation (Do first)

1. **Rewrite `styles.blade.php`** — new color system, typography, card styles, table styles, page margins, header/footer CSS. Delete all old styles, start fresh.
2. **Create component partials** — `trend.blade.php`, `metric-card.blade.php`, `score-circle.blade.php`, `data-table.blade.php`, `section-header.blade.php`, `chart-line.blade.php`
3. **Rewrite `maintenance-report.blade.php`** — new main wrapper with proper `@page` rules, conditional section rendering, header/footer injection

### Phase 2: Section Rewrites (One at a time)

4. **Cover page** — split layout with absolute positioning
5. **Intro page** — simple rewrite
6. **Overview page** — card grid with trends (requires comparison logic first)
7. **Updates page** — log table + summary cards with trends
8. **Uptime page** — big metric + SVG chart + incident table
9. **Backups page** — status cards + history table
10. **Analytics pages (1 & 2)** — metric cards + chart + breakdown tables
11. **Search Console pages (1 & 2)** — metric cards + chart + breakdown tables
12. **Performance page** — dual panel with score circles + metrics
13. **Closing page** — minor rewrite

### Phase 3: New Sections

14. **Security section** — new partial + gathering method
15. **Database section** — new partial + gathering method
16. **Migration** for `show_database` on `site_report_configs`

### Phase 4: Comparison Engine

17. **Add `calculateTrend()` and `calculateTrendInverse()` to service**
18. **Add `getPreviousSnapshot()` helper**
19. **Update all gathering methods** to accept and use `$previousSnapshot`
20. **Add `ReportChartService`** for SVG chart point generation
21. **Wire trend data into all section partials**

### Phase 5: Integration

22. **Update `ReportTemplate` model** — add `security` and `database` to `AVAILABLE_SECTIONS`
23. **Update `ReportTemplatesSettings`** Livewire component — add checkboxes for new sections
24. **Update default template seeder** — include new sections
25. **Update localization files** — add all new keys (RO + EN)
26. **Test PDF generation** — verify all sections render correctly with DomPDF

---

## 13. DomPDF Constraints & Workarounds

### 13.1 Layout Rules

| Pattern | DomPDF Approach |
|---------|----------------|
| 3-column card grid | `<table>` with 3 `<td>` each `width: 33%` |
| 2-column split | `<table>` with 2 `<td>` each `width: 50%` |
| 4-column card grid | `<table>` with 4 `<td>` each `width: 25%` |
| Full-width section | Normal `<div>` block |
| Side-by-side panels | `<table>` with 2 `<td>` |
| Overlay text on colored bg | `position: absolute` inside `position: relative` container |

### 13.2 Image Handling

Logos must be resolved to absolute file paths (not URLs):

```php
protected function resolveLogoPath(?string $path): ?string
{
    if (!$path) return null;
    
    // If it's a storage path
    if (str_starts_with($path, 'logos/') || str_starts_with($path, 'uploads/')) {
        $fullPath = storage_path('app/' . $path);
        if (file_exists($fullPath)) {
            return $fullPath;
        }
    }
    
    // If it's already an absolute path
    if (file_exists($path)) {
        return $path;
    }
    
    // Try public path
    $publicPath = public_path($path);
    if (file_exists($publicPath)) {
        return $publicPath;
    }
    
    return null;
}
```

In Blade, use the file path directly:
```blade
<img src="{{ $branding['company_logo'] }}" style="height: 28px;" />
```

### 13.3 SVG in DomPDF

DomPDF renders inline SVG. Keep SVGs simple:
- Use `<svg>`, `<circle>`, `<rect>`, `<line>`, `<polyline>`, `<polygon>`, `<text>`, `<path>`
- Avoid `<clipPath>`, `<filter>`, `<gradient>` (inconsistent support)
- Always set explicit `width`, `height`, and `viewBox`
- Use `fill`, `stroke`, `stroke-width` attributes directly on elements
- For text in SVG, use `font-size`, `font-weight`, `text-anchor`, `fill`

### 13.4 Page Break Rules

```css
/* Force new page before each section */
.section-page {
    page-break-before: always;
}

/* Prevent splitting cards or table rows across pages */
.no-break {
    page-break-inside: avoid;
}

/* Keep table header with at least first row */
thead {
    display: table-header-group;
}

/* If a section is too long, allow natural page breaks within */
.allow-break {
    page-break-inside: auto;
}
```

### 13.5 Font Sizes for Print

DomPDF at 150 DPI renders point sizes correctly. Use `pt` not `px` for consistent sizing:
- `pt` = absolute size, 1pt = 1/72 inch
- At 150 DPI: 12pt text renders at ~25px on screen

### 13.6 Debugging DomPDF Output

When testing, use the `?preview=1` route parameter which renders the PDF inline in the browser. For quick HTML debugging during development, temporarily render the Blade view as HTML:

```php
// Temporary debug route (remove in production)
Route::get('/reports/{report}/debug-html', function (Report $report) {
    $service = app(ReportGeneratorService::class);
    $html = $service->renderHtml($report); // Extract HTML rendering into separate method
    return response($html);
});
```

---

## Appendix A: Complete Metric Card Component

```blade
{{-- resources/views/reports/components/metric-card.blade.php --}}
{{-- 
    Usage: @include('reports.components.metric-card', [
        'label' => 'Utilizatori',
        'value' => '127',
        'sublabel' => 'total users',
        'trend' => ['direction' => 'down', 'display' => '↓ 22%', 'color' => '#ef4444'],
        'icon' => '👤', // optional emoji or SVG
    ])
--}}

<td class="overview-card" style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 14px; vertical-align: top;">
    @if(isset($icon))
        <div style="font-size: 14pt; margin-bottom: 4px;">{{ $icon }}</div>
    @endif
    <div style="font-size: 8pt; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
        {{ $label }}
    </div>
    <div style="font-size: 22pt; font-weight: bold; color: #111827; margin-bottom: 2px;">
        {{ $value }}
    </div>
    @if(isset($sublabel))
        <div style="font-size: 8pt; color: #9ca3af; margin-bottom: 6px;">
            {{ $sublabel }}
        </div>
    @endif
    @if(isset($trend))
        @include('reports.components.trend', ['trend' => $trend])
    @endif
</td>
```

---

## Appendix B: Complete Section Header Component

```blade
{{-- resources/views/reports/components/section-header.blade.php --}}
{{--
    Usage: @include('reports.components.section-header', [
        'title' => __('report.section_updates'),
        'icon' => '⟳',
        'primaryColor' => $branding['primary_color'],
    ])
--}}

<div style="margin-bottom: 20px;">
    <div style="font-size: 18pt; font-weight: bold; color: #111827; margin-bottom: 8px;">
        @if(isset($icon))
            <span style="margin-right: 6px;">{{ $icon }}</span>
        @endif
        {{ $title }}
    </div>
    <div style="width: 50px; height: 3px; background-color: {{ $primaryColor ?? '#7C3AED' }}; border-radius: 2px;"></div>
</div>
```

---

## Appendix C: Section Icons

| Section | Emoji Icon | Used in header + overview card |
|---------|-----------|-------------------------------|
| Overview | 📋 | Section header |
| Updates | ⟳ | Section header + overview card |
| Uptime | ⬆ | Section header + overview card |
| Backups | 💾 | Section header + overview card |
| Analytics | 📊 | Section header + overview card |
| Search Console | 🔍 | Section header + overview card |
| Performance | ⚡ | Section header + overview card |
| Security | 🛡 | Section header + overview card |
| Database | 🗄 | Section header + overview card |

**Note:** DomPDF emoji support is limited. If emojis don't render, replace with simple SVG icons or text symbols. Test early.

**Fallback symbols if emojis fail:**
| Section | Fallback |
|---------|----------|
| Updates | ↻ |
| Uptime | ↑ |
| Backups | ◆ |
| Analytics | ▣ |
| Search Console | ◎ |
| Performance | ★ |
| Security | ● |
| Database | ▦ |

---

## Appendix D: Number Formatting

Romanian locale uses comma for decimal and dot for thousands:
- `100.00 %` → `100,00 %`
- `1,600` → `1.600`
- `45.08%` → `45,08%`

English locale uses dot for decimal and comma for thousands (standard).

Helper:
```php
protected function formatNumber($value, int $decimals = 0, string $locale = 'ro'): string
{
    if ($value === null) return __('report.not_available');
    
    $decimalSep = $locale === 'ro' ? ',' : '.';
    $thousandsSep = $locale === 'ro' ? '.' : ',';
    
    return number_format($value, $decimals, $decimalSep, $thousandsSep);
}

protected function formatBytes($bytes, string $locale = 'ro'): string
{
    if ($bytes === null) return __('report.not_available');
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return $this->formatNumber($bytes, $i > 0 ? 1 : 0, $locale) . ' ' . $units[$i];
}
```

---

**END OF SPECIFICATION**

This spec is designed as a complete reference for Claude Code to implement the redesign. Work through the Implementation Order (Section 12) sequentially. Each section includes exact CSS, HTML structure, data mappings, and DomPDF-compatible approaches.

For questions about the existing codebase structure, refer to `REPORTS.md`.
