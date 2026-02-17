# PDF Report — Fix List (Round 2)

## Context

The report redesign made good progress: sections now flow naturally instead of one-per-page, which brought the report from 13 to 9 pages. But there are still significant problems with untranslated strings, data formatting, layout bugs, and content decisions. This prompt fixes every remaining issue.

**Attached:** The current PDF output (`report-32-2026-02-17-141955.pdf`). Study every page before making changes.

---

## Step 0: Read and Audit

Before changing anything:

1. Read all Blade files in `resources/views/reports/` (partials, components, styles)
2. Read `lang/ro/report.php` and `lang/en/report.php`
3. Read the `generate()` method and all `gatherXxxData()` methods in `ReportGeneratorService.php`
4. Read `app/Models/SiteMonthlySnapshot.php`

Then search the codebase for every untranslated key:

```bash
grep -rn "report\." resources/views/reports/ | grep -v "__(" | grep -v "{{--"
```

And check which translation keys are missing:

```bash
php artisan tinker --execute="print_r(trans('report'));"
```

---

## Issues by Page

### PAGE 1 (Cover)

**Issue 1.1 — The split cover layout was not implemented.**
The cover should have two panels: left ~45% white with the client logo, right ~55% filled with the primary color containing the title and URL. Currently it's a plain centered layout on a white/gray background. Implement the split layout as described in the previous prompt. If the left/right absolute positioning doesn't work with the current renderer, use a two-column table instead: one `<td>` with white background at 45% width, one `<td>` with `primary_color` background at 55% width, both at full page height (297mm).

**Issue 1.2 — No client logo on cover.**
The cover should show the client's logo (from `report_schedule.client_logo_path` or the site's client model). If no client logo is available, show the site name in large text as a fallback.

**Issue 1.3 — The section checklist on the cover is a good addition but needs polish.**
The checkmark list (✓ Privire de ansamblu, ✓ Actualizări WordPress, etc.) is a good idea — keep it. But it should be on the right panel (on the colored background, in white text) below the intro paragraph, not as a plain list on white.

### PAGE 2 (Blank Page)

**Issue 2.1 — Completely blank page.**
There is an entirely empty page 2. This is a bug. Find the cause — it's likely an empty `.page` div, a stray `page-break-after`, or the cover page CSS creating an extra page break. Remove whatever causes this blank page.

### PAGE 3 (Overview + Updates Start)

**Issue 3.1 — Untranslated keys visible in the output.**
The following raw translation keys are printed in the PDF instead of translated text:
- `REPORT.OVERVIEW_GROUP_MONITORING`
- `REPORT.OVERVIEW_GROUP_PERFORMANCE`
- `REPORT.OVERVIEW_GROUP_TRAFFIC`

These keys are either missing from the language files or being called with the wrong syntax. Find where these are referenced in the Blade templates, check the exact key format being used (case sensitivity matters — `report.overview_group_monitoring` vs `REPORT.OVERVIEW_GROUP_MONITORING`), and add the missing translations:
- RO: "MONITORIZARE", "PERFORMANȚĂ", "TRAFIC & SEO"
- EN: "MONITORING", "PERFORMANCE", "TRAFFIC & SEO"

**Issue 3.2 — All trend indicators show `—` (dash).**
Every metric card shows `—` instead of a percentage trend. This means either:
- The previous month's snapshot is not being loaded
- The trend calculation is returning null for everything
- The Blade template defaults to `—` when trend data is missing

Debug this: check if `getPreviousSnapshot()` is returning a snapshot, check if `calculateTrend()` is being called and what it returns. If there's genuinely no previous snapshot (first month of monitoring), display nothing or "first period" instead of a confusing dash on every single metric.

**Issue 3.3 — Analytics and Search Console show "N/A" on the overview.**
If these modules don't have data for the period, the overview cards should say something more informative, like "Not connected" or "No data" in the report language, not just "N/A". Check if the data is actually missing or if it's a display issue.

**Issue 3.4 — The overview section heading says "Privire de ansamblu globală" which is correct, but the group labels are in ALL CAPS translation keys.** After fixing the translations, make sure the group labels render as small, muted, uppercase section dividers — not loud broken text.

### PAGE 4 (Updates Continued + Uptime Start)

**Issue 4.1 — `report.uptime_description` printed raw.**
Another untranslated key. Add the translation:
- RO: "Monitorizarea asigură că website-ul dvs. este accesibil și funcționează în parametri optimi."
- EN: "Uptime monitoring ensures your website is accessible and performing optimally."

Or if you prefer shorter text, just a one-line description. The key point is: the raw key name must NOT appear in the output.

**Issue 4.2 — Some plugin updates appear duplicated.**
The updates table shows entries that look like duplicates (e.g., "JetEngine 15/02/2026 2.11.3 → 3.8.4.1" appears twice, "Elementor Pro" appears 3 times, "Rank Math SEO" appears 3 times). Check if the data source actually contains duplicates or if they represent separate update events. If they're separate incremental updates (e.g., 1.0.263 → 1.0.264.1 and then 1.0.264.1 → 1.0.264.1), they should show different version transitions. If they are true duplicates, deduplicate them in the gathering method.

### PAGE 5 (Uptime + Backups Start)

**Issue 5.1 — Downtime displays as `36.333333333333m`.**
Raw floating point number leaked into the output. This must be formatted properly. Round to the nearest minute: "36 min" or "36m". If the value is less than 1 minute, show "< 1 min". If it's over 60 minutes, show hours and minutes: "1h 12m". Create a formatting helper for duration values.

**Issue 5.2 — Response time chart has overlapping labels.**
The Y-axis labels (817ms, 409ms, 0ms) and the average value (308ms) overlap or crowd each other. Fix the chart SVG to have proper spacing — move the labels further apart, or reduce the number of Y-axis labels to just 2 (max and 0).

**Issue 5.3 — `report.backups_description` printed raw.**
Same type of issue as uptime — add the translation:
- RO: "Copiile de rezervă protejează datele website-ului dvs. și permit restaurarea rapidă în caz de probleme."
- EN: "Backups protect your website data and allow quick restoration in case of issues."

**Issue 5.4 — Backup stats show "10 reușite" AND "10 eșuate".**
The overview card says "10 / 10" and the backup section says "REUȘITE 10" and "EȘUATE 10". Looking at the actual backup list (page 6), there are clearly both successful (✓) and failed (✗) entries. But the counts are wrong — there appear to be about 10 successful and 9 failed, not "10 eșuate". Check the counting logic in `gatherBackupsData()`. The successful count should only count backups with status = completed/success, and failed count should only count status = failed.

### PAGE 6 (Backups Continued + Analytics Start)

**Issue 6.1 — Too many backup entries shown.**
The backup history table shows 19 entries, including many failed attempts. This makes the table dominate the page. Limit the backup history table to the **last 10 entries** (or fewer). For failed backups with no size, show "—" which is fine, but consider only showing successful backups in the report to keep it clean, or show max 5 recent successful + a note "X failed attempts".

**Issue 6.2 — `report.analytics_description` printed raw.**
Add the translation:
- RO: "Date de trafic din Google Analytics pentru perioada raportată."
- EN: "Traffic data from Google Analytics for the reporting period."

**Issue 6.3 — Analytics chart appears broken or tiny.**
The user traffic chart shows very low numbers (0-2 users) and the X-axis labels are cut off. If data is sparse, the chart should still render cleanly with proper axis labels. If there are only a few data points, consider showing them as a simple table instead of a chart, or at least make sure the chart has enough height and the labels don't overlap.

### PAGE 7 (Analytics Continued + Search Console Start)

**Issue 7.1 — Cities and Countries tables appear empty.**
The "Orașe de origine" and "Țări de origine" headers are visible but the actual data tables appear to be missing or empty. If there's no geographic data, display "No data available" in the appropriate language instead of empty space.

**Issue 7.2 — Analytics layout is cramped.**
The metrics (12 pageviews, 4 users, 33.3% bounce rate, etc.), the channel table, the top pages table, and the new/returning user counts are all squeezed together without clear visual separation. Add spacing between these groups. The top pages table shows URLs that are too long and get wrapped awkwardly — truncate long URLs to a reasonable length (e.g., max 50 characters with "..." at the end).

**Issue 7.3 — Top pages shows `/wp-login.php`.**
The WordPress login page shouldn't normally be in the "popular content" list for a client report. Consider filtering out known WordPress system URLs (`/wp-login.php`, `/wp-admin/*`, `/wp-cron.php`, `/xmlrpc.php`) from the top pages list in the analytics gathering method.

### PAGE 8 (Search Console Continued + Performance)

**Issue 8.1 — Search Console tables are truncated.**
The "Top 5 pagini" table shows URLs that are cut off. The "Top 5 țări" table and "Dispozitive" table appear to be empty or missing data. Same as analytics — if no data, show a "No data" message cleanly. For long URLs, truncate them or show only the path portion (e.g., `/strategie-de-brand/` instead of the full URL with UTM parameters).

**Issue 8.2 — Performance mobile section shows all dashes.**
Mobile performance shows "—" for the score and all metrics. If there was no mobile test during the period, display a clean "No mobile test available" message instead of a broken-looking card full of dashes. The desktop side looks better but the score circle appears to be missing — only the number "76" is shown without the circular indicator.

**Issue 8.3 — Performance score circle not rendering.**
The desktop score shows "76" as plain text. It should be rendered as a colored circle (orange for 50-89 range). Check if the SVG score circle is being generated. If SVG circles don't render with the current PDF engine, fall back to a colored box or badge with the number inside.

**Issue 8.4 — Performance page should show both panels side by side.**
Mobile and desktop should be in two columns. Currently it looks like they might be stacked vertically. Use a two-column table layout.

### PAGE 9 (Closing)

**Issue 9.1 — "SimpleAd" appears twice at the bottom.**
The closing page shows "SimpleAd" on two separate lines. This is likely the company logo alt text plus the company name text both rendering. Fix so only one instance appears, or show the logo image (if available) with the name below it once.

---

## Global Issues (Apply to All Pages)

### Untranslated Keys — Complete Audit

Search every Blade file for translation function calls and verify every key exists in both language files. The following keys were found untranslated in the current output:
- `report.overview_group_monitoring` (or REPORT.OVERVIEW_GROUP_MONITORING)
- `report.overview_group_performance`
- `report.overview_group_traffic`
- `report.uptime_description`
- `report.backups_description`
- `report.analytics_description`
- `report.uptime_response_description`

Run a script to find ALL missing keys:

```bash
# Extract all translation keys used in report blade files
grep -rohP "__\('report\.\K[^']*" resources/views/reports/ | sort -u > /tmp/used_keys.txt

# Extract all keys defined in the language file
php artisan tinker --execute="echo implode(PHP_EOL, array_keys(trans('report')));" > /tmp/defined_keys.txt

# Find missing keys
comm -23 /tmp/used_keys.txt /tmp/defined_keys.txt
```

Add every missing key to both `lang/ro/report.php` and `lang/en/report.php`.

### Trend Data Not Working

All metrics show `—` for trends. This needs debugging:

1. Check if `getPreviousSnapshot()` is actually being called in the generate flow
2. Check if a previous month snapshot exists for the test site
3. If no previous snapshot exists (legitimate case), display NOTHING instead of `—` on every card. An empty trend is better than 15 dashes that make the report look broken.
4. If the trend helper returns null (no comparison available), the Blade component should render nothing, not a dash.

### Number/Duration Formatting

Several places show raw numbers:
- `36.333333333333m` for downtime — must be rounded and formatted as "36 min"
- Backup sizes are properly formatted (220.06 MB) — good
- Percentages should use locale-appropriate decimals (comma for RO, dot for EN)

Review all places where numbers are displayed and ensure the formatting helper is being used consistently.

### URL Truncation in Tables

Long URLs in the top pages tables (both Analytics and Search Console) break the layout. Implement a truncation approach:
- Show only the path portion of the URL (strip the domain) — e.g., `/strategie-de-brand/` instead of `https://simplead.ro/strategie-de-brand/?utm_source=...`
- If the path is still longer than ~50 characters, truncate with `...`
- For the Search Console pages table, the full URL is useful for context, so show the path but cap it

### Filter System URLs from Reports

WordPress system pages should not appear in client-facing analytics reports. Filter these out in `gatherAnalyticsData()`:
- `/wp-login.php`
- `/wp-admin/` and anything under it
- `/wp-cron.php`
- `/xmlrpc.php`
- `/wp-json/` API endpoints

### Empty State Handling

When a data table has no rows, or a metric has no value, the current behavior is inconsistent — sometimes it shows "N/A", sometimes nothing, sometimes broken layout. Standardize:
- **Metric card with no data:** Show "—" as the value (single em-dash) and no trend indicator. Gray text color.
- **Table with no data:** Show a single row spanning all columns with "No data for this period" in the report language, centered, in muted gray text.
- **Chart with no/insufficient data:** Don't render the chart at all. Show a text note: "Insufficient data for chart" in muted text.
- **Section where the module is not connected:** Show a brief note: "Google Analytics is not connected" / "Google Analytics nu este conectat" — and nothing else for that section.

---

## Priority Order

Fix these in order of impact:

1. **Remove blank page 2** — most visible bug
2. **Fix all untranslated keys** — makes the report look broken/unprofessional
3. **Fix the downtime formatting** (`36.333333333333m`)
4. **Fix backup count logic** (10 successful AND 10 failed can't be right)
5. **Limit backup table** to max 10 recent entries
6. **Fix trend display** — either show real data or show nothing (not dashes everywhere)
7. **Fix performance score circles** — render as colored badge/circle
8. **Performance side-by-side layout** — mobile left, desktop right
9. **Implement split cover layout** — visual quality improvement
10. **Fix empty tables** (cities, countries) with proper empty states
11. **Truncate long URLs** in top pages tables
12. **Filter wp-login.php** and other system URLs from analytics
13. **Fix duplicate "SimpleAd"** on closing page
14. **Fix chart rendering** for sparse data
15. **Polish spacing and visual hierarchy** throughout

---

## What NOT to Change

- Backend logic: models, jobs, dispatcher, schedules, email — all unchanged
- The section flow approach (continuous flow without forced page breaks) — this is working well, keep it
- The localization architecture — just add missing keys
- Route structure, controllers, Livewire components
