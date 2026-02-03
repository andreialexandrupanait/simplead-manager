# SimpleAd Manager — PDF Report Critical Fixes

This prompt fixes the critical issues in the current PDF report generation.

---

## ISSUE 1: Margins Not Applied

**Problem:** Content is flush against page edges, no margins visible.

**Cause:** DomPDF ignores `@page` margins in certain situations or CSS is not applied correctly.

**Fix:** Use padding on container instead of margin on @page:

```css
/* Instead of @page margins, use wrapper with padding */
@page {
    size: A4 portrait;
    margin: 0; /* Reset */
}

.page {
    width: 210mm;
    min-height: 297mm;
    padding: 20mm 18mm 25mm 18mm; /* TOP RIGHT BOTTOM LEFT */
    box-sizing: border-box;
    page-break-after: always;
    position: relative;
}

.page:last-child {
    page-break-after: auto;
}

/* Cover page without padding (full bleed) */
.page.cover {
    padding: 0;
}

.page.cover .cover-content {
    padding: 40mm 30mm;
}
```

**Also verify DomPDF options:**
```php
$pdf->setPaper('A4', 'portrait');
$pdf->setOptions([
    'isHtml5ParserEnabled' => true,
    'isRemoteEnabled' => true,
    'defaultFont' => 'DejaVu Sans',
    'isFontSubsettingEnabled' => true,
]);
```

---

## ISSUE 2: Romanian Diacritics Not Working

**Problem:** Characters like ă, â, î, ș, ț appear as question marks: "SEC?IUNI", "ACTUALIZ?RI", "FUNC?IONARE"

**Cause:** The font being used doesn't support Romanian characters (ă, â, î, ș, ț, Ă, Â, Î, Ș, Ț).

### Solution 1: Use DejaVu Sans (included in DomPDF)

DejaVu Sans supports Romanian diacritics and comes pre-installed with DomPDF.

```css
body {
    font-family: 'DejaVu Sans', sans-serif;
}
```

### Solution 2: Force UTF-8 Encoding (Essential)

Make sure the HTML document has proper UTF-8 encoding:

```blade
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        * {
            font-family: 'DejaVu Sans', sans-serif;
        }
    </style>
</head>
```

### Solution 3: Install Custom Font (Nunito, Inter, or Open Sans)

If you want a nicer font than DejaVu Sans:

1. Download the font with full Unicode support (TTF format)
2. Place in `storage/fonts/`
3. Register in DomPDF:

```php
// config/dompdf.php
'font_dir' => storage_path('fonts/'),
'font_cache' => storage_path('fonts/'),
```

4. Generate font metrics:
```bash
php artisan dompdf:font "Nunito" storage/fonts/Nunito-Regular.ttf
```

5. Use in CSS:
```css
@font-face {
    font-family: 'Nunito';
    src: url('{{ storage_path("fonts/Nunito-Regular.ttf") }}') format('truetype');
    font-weight: normal;
    font-style: normal;
}

body {
    font-family: 'Nunito', 'DejaVu Sans', sans-serif;
}
```

**IMPORTANT:** Ensure the Blade template file is saved as **UTF-8 without BOM**:
- In VS Code: Click on encoding in status bar → "Save with Encoding" → "UTF-8"

---

## ISSUE 3: Multi-Language Support

**Problem:** Report text is hardcoded, no option to choose language per client.

### Database Changes

Add language field to `report_schedules` migration:
```php
$table->string('language')->default('ro'); // ro, en
```

Add default language to `report_templates` migration:
```php
$table->string('default_language')->default('ro');
```

### Translation Files

Create translation files for reports:

**Romanian: `resources/lang/ro/report.php`**
```php
<?php

return [
    // Cover
    'report_title' => 'Raport :name',
    
    // Introduction
    'intro_title' => 'Raport lunar de Mentenanță și Performanță website',
    'intro_text' => 'Acest raport prezintă activitățile desfășurate pentru menținerea și optimizarea website-ului dvs. Veți regăsi informații detaliate despre actualizări, securitate, performanță și recomandări, pentru a asigura funcționarea optimă a platformei online.',
    
    // Sections
    'sections_included' => 'Secțiuni incluse',
    'overview' => 'Privire de ansamblu',
    'updates' => 'Actualizări WordPress',
    'uptime' => 'Monitorizare timp de funcționare',
    'backups' => 'Copii de rezervă',
    'analytics' => 'Google Analytics',
    'search_console' => 'Google Console de Căutare',
    'performance' => 'Performanță (PageSpeed)',
    'links' => 'Link-uri verificate',
    
    // Overview page
    'global_overview' => 'Privire de ansamblu globală',
    'plugins' => 'Plugin-uri',
    'themes' => 'Teme',
    'status' => 'Stare',
    'availability' => 'Disponibilitate',
    'incidents' => 'Incidente',
    'completed' => 'Efectuate',
    'pageviews' => 'Vizualizări pagini',
    'users' => 'Utilizatori',
    'search_clicks' => 'Clicuri căutare',
    'impressions' => 'Impresii',
    'mobile' => 'Mobil',
    'desktop' => 'Desktop',
    'traffic' => 'Trafic',
    'backup' => 'Backup',
    'active' => 'Activ',
    'inactive' => 'Inactiv',
    'unstable' => 'Instabil',
    
    // Updates page
    'updates_title' => 'Actualizări',
    'plugin_updates' => 'Actualizări Plugin-uri',
    'theme_updates' => 'Actualizări Teme',
    'name' => 'Nume',
    'date' => 'Dată',
    'version' => 'Versiune',
    'state' => 'Stare',
    
    // Uptime page
    'uptime_title' => 'Monitorizare timp de funcționare',
    'average_uptime' => 'Timp de funcționare mediu',
    'avg_response_time' => 'Timp mediu de răspuns',
    'total_downtime' => 'Timp nefuncțional',
    'activity_changes' => 'Modificări activitate',
    'from' => 'De la',
    'to' => 'Până la',
    'duration' => 'Durată',
    'up' => 'Funcționează',
    'down' => 'Nefuncțional',
    'ongoing' => 'În desfășurare',
    
    // Backups page
    'backups_title' => 'Copii de rezervă',
    'backup_history' => 'Istoric backup-uri',
    'enabled' => 'Activat',
    'yes' => 'Da',
    'no' => 'Nu',
    'frequency' => 'Periodicitate',
    'daily' => 'Zilnic',
    'weekly' => 'Săptămânal',
    'monthly' => 'Lunar',
    'type' => 'Tip',
    'size' => 'Dimensiune',
    'trigger' => 'Declanșator',
    'scheduled' => 'Programat',
    'manual' => 'Manual',
    'total_size' => 'Dimensiune totală',
    'full' => 'Complet',
    'database' => 'Bază de date',
    'files' => 'Fișiere',
    
    // Analytics page
    'analytics_title' => 'Analitică',
    'analytics_continued' => 'Analitică (continuare)',
    'pages_viewed' => 'Pagini vizualizate',
    'bounce_rate' => 'Rată respingere',
    'session_duration' => 'Durată sesiune',
    'new_users' => 'Utilizatori noi',
    'returning_users' => 'Utilizatori revenind',
    'device_distribution' => 'Distribuție dispozitiv',
    'traffic_sources' => 'Surse de trafic',
    'source_medium' => 'Sursă / Mediu',
    'sessions' => 'Sesiuni',
    'percentage' => 'Procent',
    'top_pages' => 'Top pagini',
    'page' => 'Pagină',
    'views' => 'Vizualizări',
    'top_countries' => 'Top țări',
    'country' => 'Țară',
    'top_cities' => 'Top orașe',
    'city' => 'Oraș',
    
    // Search Console page
    'search_console_title' => 'Google Console de Căutare',
    'total_clicks' => 'Total clicuri',
    'avg_ctr' => 'CTR mediu',
    'avg_position' => 'Poziție medie',
    'top_queries' => 'Top căutări',
    'query' => 'Căutare',
    'clicks' => 'Clicuri',
    'ctr' => 'CTR',
    'position' => 'Poziție',
    'top_pages_search' => 'Top pagini',
    'devices_used' => 'Dispozitive utilizate',
    'device' => 'Dispozitiv',
    'phone' => 'Telefon mobil',
    'computer' => 'Calculator',
    'tablet' => 'Tabletă',
    
    // Performance page
    'performance_title' => 'Performanță',
    'updated' => 'Actualizat',
    'fcp' => 'First Contentful Paint',
    'speed_index' => 'Speed Index',
    'lcp' => 'Largest Contentful Paint',
    'tbt' => 'Total Blocking Time',
    'cls' => 'Cumulative Layout Shift',
    'tti' => 'Time to Interactive',
    'legend_poor' => '0–49',
    'legend_moderate' => '50–89',
    'legend_good' => '90–100',
    
    // Links page
    'links_title' => 'Link-uri verificate',
    'links_checked' => 'Link-uri verificate',
    'broken_links' => 'Link-uri rupte',
    'redirects' => 'Redirecționări',
    'pages_scanned' => 'Pagini scanate',
    'last_scan' => 'Ultima scanare',
    'no_broken_links' => 'Niciun link rupt găsit.',
    
    // Thank you page
    'thank_you_title' => 'Mulțumim pentru colaborare!',
    'thank_you_text' => 'Acest raport marchează activitățile esențiale efectuate pentru a menține website-ul dvs. în siguranță și la cele mai bune standarde. Apreciem parteneriatul nostru și ne bucurăm să construim împreună o prezență online solidă. Dacă aveți nelămuriri sau idei de îmbunătățire, echipa noastră este mereu aici pentru a vă sprijini.',
    
    // Footer
    'generated_on' => 'Raport generat pe :date',
    'powered_by' => 'Powered by SimpleAd Manager',
];
```

**English: `resources/lang/en/report.php`**
```php
<?php

return [
    // Cover
    'report_title' => 'Report :name',
    
    // Introduction
    'intro_title' => 'Monthly Website Maintenance & Performance Report',
    'intro_text' => 'This report provides a comprehensive overview of your website\'s health, performance, and maintenance activities for the reporting period. All metrics are gathered automatically from your site\'s monitoring systems, providing an accurate picture of how your website is performing.',
    
    // Sections
    'sections_included' => 'Sections Included',
    'overview' => 'Executive Overview',
    'updates' => 'WordPress Updates',
    'uptime' => 'Uptime Monitoring',
    'backups' => 'Backup Status',
    'analytics' => 'Google Analytics',
    'search_console' => 'Google Search Console',
    'performance' => 'Performance (PageSpeed)',
    'links' => 'Broken Links',
    
    // Overview page
    'global_overview' => 'Executive Overview',
    'plugins' => 'Plugins',
    'themes' => 'Themes',
    'status' => 'Status',
    'availability' => 'Availability',
    'incidents' => 'Incidents',
    'completed' => 'Completed',
    'pageviews' => 'Pageviews',
    'users' => 'Users',
    'search_clicks' => 'Search Clicks',
    'impressions' => 'Impressions',
    'mobile' => 'Mobile',
    'desktop' => 'Desktop',
    'traffic' => 'Traffic',
    'backup' => 'Backup',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'unstable' => 'Unstable',
    
    // Updates page
    'updates_title' => 'Updates',
    'plugin_updates' => 'Plugin Updates',
    'theme_updates' => 'Theme Updates',
    'name' => 'Name',
    'date' => 'Date',
    'version' => 'Version',
    'state' => 'Status',
    
    // Uptime page
    'uptime_title' => 'Uptime Monitoring',
    'average_uptime' => 'Average Uptime',
    'avg_response_time' => 'Avg Response Time',
    'total_downtime' => 'Total Downtime',
    'activity_changes' => 'Activity Changes',
    'from' => 'From',
    'to' => 'To',
    'duration' => 'Duration',
    'up' => 'Up',
    'down' => 'Down',
    'ongoing' => 'Ongoing',
    
    // Backups page
    'backups_title' => 'Backups',
    'backup_history' => 'Backup History',
    'enabled' => 'Enabled',
    'yes' => 'Yes',
    'no' => 'No',
    'frequency' => 'Frequency',
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'type' => 'Type',
    'size' => 'Size',
    'trigger' => 'Trigger',
    'scheduled' => 'Scheduled',
    'manual' => 'Manual',
    'total_size' => 'Total Size',
    'full' => 'Full',
    'database' => 'Database',
    'files' => 'Files',
    
    // Analytics page
    'analytics_title' => 'Analytics',
    'analytics_continued' => 'Analytics (continued)',
    'pages_viewed' => 'Pages Viewed',
    'bounce_rate' => 'Bounce Rate',
    'session_duration' => 'Session Duration',
    'new_users' => 'New Users',
    'returning_users' => 'Returning Users',
    'device_distribution' => 'Device Distribution',
    'traffic_sources' => 'Traffic Sources',
    'source_medium' => 'Source / Medium',
    'sessions' => 'Sessions',
    'percentage' => 'Share',
    'top_pages' => 'Top Pages',
    'page' => 'Page',
    'views' => 'Views',
    'top_countries' => 'Top Countries',
    'country' => 'Country',
    'top_cities' => 'Top Cities',
    'city' => 'City',
    
    // Search Console page
    'search_console_title' => 'Google Search Console',
    'total_clicks' => 'Total Clicks',
    'avg_ctr' => 'Avg CTR',
    'avg_position' => 'Avg Position',
    'top_queries' => 'Top Queries',
    'query' => 'Query',
    'clicks' => 'Clicks',
    'ctr' => 'CTR',
    'position' => 'Position',
    'top_pages_search' => 'Top Pages',
    'devices_used' => 'Devices Used',
    'device' => 'Device',
    'phone' => 'Mobile',
    'computer' => 'Desktop',
    'tablet' => 'Tablet',
    
    // Performance page
    'performance_title' => 'Performance',
    'updated' => 'Updated',
    'fcp' => 'First Contentful Paint',
    'speed_index' => 'Speed Index',
    'lcp' => 'Largest Contentful Paint',
    'tbt' => 'Total Blocking Time',
    'cls' => 'Cumulative Layout Shift',
    'tti' => 'Time to Interactive',
    'legend_poor' => '0–49',
    'legend_moderate' => '50–89',
    'legend_good' => '90–100',
    
    // Links page
    'links_title' => 'Broken Links',
    'links_checked' => 'Links Checked',
    'broken_links' => 'Broken Links',
    'redirects' => 'Redirects',
    'pages_scanned' => 'Pages Scanned',
    'last_scan' => 'Last Scan',
    'no_broken_links' => 'No broken links found.',
    
    // Thank you page
    'thank_you_title' => 'Thank You!',
    'thank_you_text' => 'Thank you for your continued trust in our services. If you have any questions about this report or would like to discuss optimization opportunities, please don\'t hesitate to reach out. We remain committed to keeping your website secure, fast, and up-to-date.',
    
    // Footer
    'generated_on' => 'Report generated on :date',
    'powered_by' => 'Powered by SimpleAd Manager',
];
```

### Using Translations in Blade Templates

```blade
{{-- At the beginning of the template, set the language --}}
@php
    app()->setLocale($language ?? 'ro');
@endphp

{{-- Then use __() for all text --}}
<h1>{{ __('report.intro_title') }}</h1>
<p>{{ __('report.intro_text') }}</p>

<h2>{{ __('report.sections_included') }}</h2>
<ul>
    <li>✓ {{ __('report.overview') }}</li>
    <li>✓ {{ __('report.updates') }}</li>
    <li>✓ {{ __('report.uptime') }}</li>
    <li>✓ {{ __('report.backups') }}</li>
    <li>✓ {{ __('report.analytics') }}</li>
    <li>✓ {{ __('report.search_console') }}</li>
    <li>✓ {{ __('report.performance') }}</li>
    <li>✓ {{ __('report.links') }}</li>
</ul>

{{-- For dynamic values --}}
<p>{{ __('report.generated_on', ['date' => $generatedAt->format('M d, Y')]) }}</p>
```

### In ReportGeneratorService

```php
public function generate(): string
{
    // Set language for this report
    $language = $this->schedule?->language ?? $this->template->default_language ?? 'ro';
    app()->setLocale($language);
    
    // Continue with PDF generation...
    $this->gatherData();
    
    $pdf = Pdf::loadView('reports.maintenance-report', [
        'site' => $this->site,
        'template' => $this->template,
        'period_start' => $this->periodStart,
        'period_end' => $this->periodEnd,
        'data' => $this->data,
        'language' => $language, // Pass to view
    ]);
    
    // ...
}
```

### UI for Language Selection

In the Report Schedule configuration form, add a language selector:

```blade
<div class="form-group">
    <label for="language">Report Language</label>
    <select wire:model="language" id="language" class="form-select">
        <option value="ro">Română</option>
        <option value="en">English</option>
    </select>
</div>
```

---

## IMPLEMENTATION CHECKLIST

### Immediate Fixes (Priority 1)

- [ ] Add padding to `.page` container instead of `@page` margins:
  ```css
  .page {
      padding: 20mm 18mm 25mm 18mm;
      box-sizing: border-box;
  }
  ```

- [ ] Add UTF-8 meta tags to the Blade template head:
  ```html
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  ```

- [ ] Set DejaVu Sans as the font family:
  ```css
  * {
      font-family: 'DejaVu Sans', sans-serif;
  }
  ```

- [ ] Ensure Blade template files are saved as UTF-8 without BOM

### Language Support (Priority 2)

- [ ] Add `language` column to `report_schedules` table migration
- [ ] Add `default_language` column to `report_templates` table migration
- [ ] Create `resources/lang/ro/report.php` translation file
- [ ] Create `resources/lang/en/report.php` translation file
- [ ] Replace all hardcoded text in Blade templates with `{{ __('report.key') }}`
- [ ] Add `app()->setLocale($language)` at the start of PDF generation
- [ ] Add language selector dropdown to Report Schedule form UI

### Testing

- [ ] Test PDF generation with Romanian text: "Șțăîâ ȘȚĂÎÂ funcționează"
- [ ] Verify margins are correct (not touching edges)
- [ ] Test language switching between RO and EN
- [ ] Verify all special characters render correctly

---

## QUICK TEST

After implementing the fixes, generate a test PDF with this content to verify diacritics work:

```
CARACTERE ROMÂNEȘTI: Ă Â Î Ș Ț ă â î ș ț
Funcționează corect dacă vezi toate caracterele.
```

If you see question marks or boxes instead of the characters, the font or encoding is still not configured correctly.
