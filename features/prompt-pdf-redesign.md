# SimpleAd Manager — PDF Report Redesign

This prompt redesigns the PDF report generation to match the professional quality of WPMUDEV reports. Reference the attached sample PDFs for visual guidance.

---

## CRITICAL ISSUES TO FIX

### 1. Page Margins
**Current problem:** Content is flush against page edges.

**Fix:** Add proper margins to all pages:
```css
@page {
    margin: 20mm 18mm 25mm 18mm; /* top, right, bottom, left */
    size: A4 portrait;
}

body {
    margin: 0;
    padding: 0;
}

.page-content {
    padding: 0; /* margins handled by @page */
}
```

### 2. Header & Footer on Every Page
**Current problem:** No consistent header/footer.

**Fix:** Add running header and footer:
```css
@page {
    @top-center {
        content: element(page-header);
    }
    @bottom-center {
        content: element(page-footer);
    }
}

.page-header {
    position: running(page-header);
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 20px;
}

.page-footer {
    position: running(page-footer);
    text-align: center;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
    margin-top: 20px;
}
```

**Header content (left):** Small logo
**Header content (right):** "Raport {Site Name} # {period}"
**Footer content:** Simplead logo (small) + "Simplead" text

---

## PAGE-BY-PAGE REDESIGN

### PAGE 1: Cover Page

**Layout:** Full page, centered content, light background (#f8f9fc)

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                    ┌─────────────────────┐                      │
│                    │                     │                      │
│                    │    CLIENT LOGO      │                      │
│                    │    (or site name)   │                      │
│                    │                     │                      │
│                    └─────────────────────┘                      │
│                                                                 │
│                                                                 │
│                 ─────────────────────────────                   │
│                                                                 │
│                    Raport {Client Name}                         │
│                                                                 │
│                        {site_url}                               │
│                    {start_date} - {end_date}                    │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                    ┌───────────────────┐                        │
│                    │  Simplead Logo    │                        │
│                    └───────────────────┘                        │
└─────────────────────────────────────────────────────────────────┘
```

**Styling:**
- Background: `#f8f9fc` (light blue-gray)
- Client logo: max-width 250px, centered
- Report title: 32pt, bold, `#1e1b4b` (dark indigo)
- Site URL: 14pt, `#6b7280`
- Date range: 14pt, `#6b7280`
- Simplead logo at bottom: small, subtle

**CSS:**
```css
.cover-page {
    background: #f8f9fc;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 60px 40px;
}

.cover-client-logo {
    max-width: 250px;
    max-height: 120px;
    margin-bottom: 60px;
}

.cover-title {
    font-size: 32pt;
    font-weight: 700;
    color: #1e1b4b;
    margin-bottom: 16px;
}

.cover-url {
    font-size: 14pt;
    color: #6b7280;
    margin-bottom: 8px;
}

.cover-period {
    font-size: 14pt;
    color: #6b7280;
}

.cover-footer {
    position: absolute;
    bottom: 40px;
}

.cover-footer img {
    height: 40px;
}
```

---

### PAGE 2: Introduction

**Layout:** Left-aligned content, clean typography

```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│                                                                 │
│                                                                 │
│   Raport lunar de                                               │
│   Mentenanță și                                                 │
│   Performanță website                                           │
│                                                                 │
│   Acest raport prezintă activitățile desfășurate                │
│   pentru menținerea și optimizarea website-ului                 │
│   dvs. Veți regăsi informații detaliate despre                  │
│   actualizări, securitate, performanță și                       │
│   recomandări, pentru a asigura funcționarea                    │
│   optimă a platformei online.                                   │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Styling:**
- Title: 28pt, bold, `#1e1b4b`, line-height 1.2
- Body text: 12pt, `#4b5563`, max-width 450px, line-height 1.6

---

### PAGE 3: Executive Overview (Privire de ansamblu)

**Layout:** Grid of metric cards + mini charts

```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Privire de ansamblu globală                                   │
│                                                                 │
│   ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐   │
│   │ ⏱ ACTUALIZĂRI   │ │ ⚡ UPTIME       │ │ 💾 BACKUP       │   │
│   │                 │ │                 │ │                 │   │
│   │ Plugin-uri  4   │ │ Status  ● Up    │ │ Status    ✓     │   │
│   │ Teme        0   │ │ Uptime  99.98%  │ │ Frecvență Daily │   │
│   │ WordPress   0   │ │                 │ │ Efectuate 10    │   │
│   └─────────────────┘ └─────────────────┘ └─────────────────┘   │
│                                                                 │
│   ┌─────────────────────────────────────┐ ┌─────────────────┐   │
│   │ TRAFIC                              │ │ PERFORMANȚĂ     │   │
│   │                                     │ │                 │   │
│   │ Pagini     Utilizatori   Bounce     │ │  📱    💻       │   │
│   │  229         127        45.08%      │ │ (63)  (94)      │   │
│   │                                     │ │                 │   │
│   │ [=========== mini chart ===========]│ │                 │   │
│   └─────────────────────────────────────┘ └─────────────────┘   │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ SEARCH CONSOLE                                          │   │
│   │                                                         │   │
│   │ ■ Clicuri    ■ Impresii    ■ CTR       ■ Poziție       │   │
│   │    70          1.6K        4.30%         9.30          │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Card Styling:**
```css
.overview-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.overview-card-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
}

.overview-card-icon {
    width: 20px;
    height: 20px;
    color: #6366f1; /* indigo */
}

.overview-card-title {
    font-size: 11pt;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.overview-metric-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid #f3f4f6;
}

.overview-metric-label {
    font-size: 10pt;
    color: #6b7280;
}

.overview-metric-value {
    font-size: 10pt;
    font-weight: 600;
    color: #1f2937;
}
```

**Performance Score Circles:**
```html
<div class="score-circle score-orange">
    <span class="score-value">63</span>
</div>
```

```css
.score-circle {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18pt;
    font-weight: 700;
}

.score-green { 
    background: #dcfce7; 
    color: #16a34a;
    border: 3px solid #22c55e;
}

.score-orange { 
    background: #fef3c7; 
    color: #d97706;
    border: 3px solid #f59e0b;
}

.score-red { 
    background: #fee2e2; 
    color: #dc2626;
    border: 3px solid #ef4444;
}
```

**Search Console Metric Boxes:**
```css
.metric-box {
    background: #f8fafc;
    border-left: 4px solid;
    padding: 12px 16px;
    margin-bottom: 8px;
}

.metric-box.blue { border-left-color: #3b82f6; }
.metric-box.red { border-left-color: #ef4444; }
.metric-box.green { border-left-color: #22c55e; }
.metric-box.orange { border-left-color: #f59e0b; }

.metric-box-label {
    font-size: 8pt;
    color: #6b7280;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.metric-box-value {
    font-size: 18pt;
    font-weight: 700;
    color: #1f2937;
}
```

---

### PAGE 4: Updates (Actualizări)

**Layout:** Summary badges + table

```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Actualizări                                                   │
│                                                                 │
│   Actualizat:                                                   │
│   ┌──────────┐  ┌──────────┐  ┌──────────┐                     │
│   │Plugin-uri│  │  Teme    │  │WordPress │                     │
│   │    4     │  │    0     │  │    1     │                     │
│   └──────────┘  └──────────┘  └──────────┘                     │
│                                                                 │
│   ─────────────────────────────────────────────────────────     │
│                                                                 │
│   PLUGIN-URI                                                    │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ NUME                    │ DATĂ           │ VERSIUNE     │   │
│   ├─────────────────────────────────────────────────────────┤   │
│   │ FluentSMTP              │ 02/02/2026 ✓   │ 2.2.83→2.2.95│   │
│   │ Admin Enhancements      │ 02/02/2026 ✓   │ 7.6.4→8.3.1  │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│   TEME                                                          │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ NUME                    │ DATĂ           │ VERSIUNE     │   │
│   ├─────────────────────────────────────────────────────────┤   │
│   │ Twenty Twenty-Five      │ 02/02/2026 ✓   │ 1.0→1.4      │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Summary Badges:**
```css
.update-badge {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    padding: 12px 24px;
    background: #f8fafc;
    border-radius: 8px;
    margin-right: 12px;
}

.update-badge-label {
    font-size: 9pt;
    color: #6366f1;
    font-weight: 600;
}

.update-badge-value {
    font-size: 20pt;
    font-weight: 700;
    color: #1f2937;
}
```

**Table Styling:**
```css
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}

.data-table th {
    background: #f8fafc;
    padding: 12px 16px;
    text-align: left;
    font-size: 9pt;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 14px 16px;
    font-size: 10pt;
    color: #1f2937;
    border-bottom: 1px solid #f3f4f6;
}

.data-table tr:hover {
    background: #fafafa;
}

.version-arrow {
    color: #22c55e;
    font-weight: 600;
}

.status-check {
    color: #22c55e;
}
```

---

### PAGE 5: Uptime Monitoring

**Layout:** Big percentage + chart + incidents table

```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Monitorizare timp de funcționare                              │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │                                                         │   │
│   │   Timp de funcționare mediu                             │   │
│   │                                                         │   │
│   │   99.98 %                                               │   │
│   │                                                         │   │
│   │   [============== Response Time Chart ================] │   │
│   │                                                         │   │
│   │   [████████████████████████████████████████████] 100%   │   │
│   │   ■ Răspuns  ■ Necunoscut  ■ 0-49%  ■ 50-79%  ■ 80-100% │   │
│   │                                                         │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│   Modificări activitate                                         │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ STARE          │ DE LA          │ PÂNĂ LA    │ DURATĂ   │   │
│   ├─────────────────────────────────────────────────────────┤   │
│   │ ↗ FUNCȚIONEAZĂ │ 01/01 00:00    │ 28/01 18:26│ 27d 18h  │   │
│   │ ↘ NEFUNCȚIONAL │ 28/01 18:26    │ 28/01 18:31│ 5m       │   │
│   │ ↗ FUNCȚIONEAZĂ │ 28/01 18:31    │ 31/01 23:59│ 3d 5h    │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Big Percentage:**
```css
.uptime-percentage {
    font-size: 48pt;
    font-weight: 700;
    color: #1f2937;
    margin: 20px 0;
}

.uptime-percentage.good { color: #16a34a; }
.uptime-percentage.warning { color: #d97706; }
.uptime-percentage.bad { color: #dc2626; }
```

**Uptime Bar:**
```css
.uptime-bar {
    height: 24px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
    display: flex;
}

.uptime-bar-segment {
    height: 100%;
}

.uptime-bar-segment.up { background: #22c55e; }
.uptime-bar-segment.degraded { background: #f59e0b; }
.uptime-bar-segment.down { background: #ef4444; }
```

**Status Indicators in Table:**
```css
.status-up {
    color: #16a34a;
    font-weight: 600;
}

.status-up::before {
    content: "↗ ";
}

.status-down {
    color: #dc2626;
    font-weight: 600;
}

.status-down::before {
    content: "↘ ";
}
```

---

### PAGE 6: Backups

**Layout:** Status summary + backup list

```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Copii de rezervă                                              │
│                                                                 │
│   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│   │   Activat    │  │ Periodicitate│  │  Efectuate   │         │
│   │     ✓ ON     │  │    Daily     │  │     10       │         │
│   └──────────────┘  └──────────────┘  └──────────────┘         │
│                                                                 │
│   ─────────────────────────────────────────────────────────     │
│                                                                 │
│   Istoric backup-uri                                            │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ DATĂ              │ TIP      │ DIMENSIUNE │ DECLANȘATOR │   │
│   ├─────────────────────────────────────────────────────────┤   │
│   │ Feb 03, 00:00     │ Full     │ 135.46 MB  │ Programat   │   │
│   │ Feb 02, 14:14     │ Database │ 976 KB     │ Manual      │   │
│   │ Feb 02, 13:54     │ Full     │ 135.46 MB  │ Manual      │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│   Total: 278.55 MB                                              │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Toggle Switch Visual:**
```css
.toggle-on {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #16a34a;
    font-weight: 600;
}

.toggle-on::before {
    content: "";
    width: 36px;
    height: 20px;
    background: #22c55e;
    border-radius: 10px;
    position: relative;
}

.toggle-on::after {
    content: "";
    width: 16px;
    height: 16px;
    background: white;
    border-radius: 50%;
    position: absolute;
    right: 2px;
}
```

---

### PAGES 7-8: Analytics

**Page 7 Layout:**
```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Analitică                                                     │
│                                                                 │
│   ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐   │
│   │■ Pagini    │ │ Utilizatori│ │Rata respng.│ │Durata ses. │   │
│   │   229      │ │    127     │ │  45.08%    │ │  1min 31s  │   │
│   └────────────┘ └────────────┘ └────────────┘ └────────────┘   │
│                                                                 │
│   [================== Users Chart =========================]    │
│                                                                 │
│   ┌─────────────────────────┐  ┌─────────────────────────┐      │
│   │ Utilizatori             │  │ Distribuție dispozitiv  │      │
│   │                         │  │                         │      │
│   │ ● Utilizatori noi       │  │ 📱 Smartphone           │      │
│   │   111  86.72%           │  │    49  43.36%           │      │
│   │                         │  │                         │      │
│   │ ● Recurent              │  │ 💻 Calculator           │      │
│   │   10   7.81%            │  │    64  56.64%           │      │
│   │                         │  │                         │      │
│   │ [======█████====]       │  │ [████████████====]      │      │
│   └─────────────────────────┘  └─────────────────────────┘      │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Metric Cards with Color Bar:**
```css
.analytics-metric {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    position: relative;
    overflow: hidden;
}

.analytics-metric::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: #6366f1;
}

.analytics-metric.purple::before { background: #8b5cf6; }
.analytics-metric.blue::before { background: #3b82f6; }
.analytics-metric.green::before { background: #22c55e; }
```

**Progress Bars:**
```css
.progress-bar {
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 12px;
}

.progress-bar-fill {
    height: 100%;
    border-radius: 4px;
}

.progress-bar-fill.primary { background: #6366f1; }
.progress-bar-fill.secondary { background: #a5b4fc; }
```

**Page 8:** Traffic Sources, Top Pages, Countries, Cities (tables with same styling)

---

### PAGES 9-10: Search Console

**Page 9 Layout:**
```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Google Console de Căutare                                     │
│                                                                 │
│   ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌────────┐ │
│   │■ Total clicuri│ │■ Impresii   │ │■ CTR mediu  │ │■Poziție│ │
│   │     70       │ │    1.6K     │ │   4.30%     │ │  9.30  │ │
│   └──────────────┘ └──────────────┘ └──────────────┘ └────────┘ │
│                                                                 │
│   [================== Performance Chart ===================]    │
│   (4 lines: clicks, impressions, CTR, position)                │
│                                                                 │
│   Top 10 căutări                                                │
│                                                                 │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ CĂUTARE                    │CLICURI│IMPRESII│CTR │POZIȚIE│  │
│   ├─────────────────────────────────────────────────────────┤   │
│   │ manuela sirbu              │   8   │   55   │14.5%│ 7.3  │   │
│   │ avocat malpraxis           │   4   │  145   │2.8% │ 5.9  │   │
│   │ avocat malpraxis bucuresti │   3   │   56   │5.4% │ 3.3  │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Colored Metric Boxes (like WPMUDEV):**
```css
.gsc-metric {
    border-left: 4px solid;
    padding: 12px 16px;
    background: white;
}

.gsc-metric.clicks { border-left-color: #3b82f6; }
.gsc-metric.impressions { border-left-color: #ef4444; }
.gsc-metric.ctr { border-left-color: #22c55e; }
.gsc-metric.position { border-left-color: #f59e0b; }

.gsc-metric-label {
    font-size: 8pt;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 6px;
}

.gsc-metric-label::before {
    content: "■";
    font-size: 10pt;
}

.gsc-metric.clicks .gsc-metric-label::before { color: #3b82f6; }
.gsc-metric.impressions .gsc-metric-label::before { color: #ef4444; }
.gsc-metric.ctr .gsc-metric-label::before { color: #22c55e; }
.gsc-metric.position .gsc-metric-label::before { color: #f59e0b; }

.gsc-metric-value {
    font-size: 24pt;
    font-weight: 700;
    color: #1f2937;
}
```

---

### PAGE 11: Performance

**Layout:** Two columns (Mobile + Desktop)

```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   Performanță                                                   │
│                                                                 │
│   ┌────────────────────────┐    ┌────────────────────────┐      │
│   │                        │    │                        │      │
│   │   Performanță          │    │   Performanță          │      │
│   │   Actualizat feb. 01   │    │   Actualizat feb. 01   │      │
│   │                        │    │                        │      │
│   │      📱   (63)         │    │      💻   (94)         │      │
│   │          orange        │    │          green         │      │
│   │                        │    │                        │      │
│   │ ▲ First Contentful     │    │ ● First Contentful     │      │
│   │   3.8 s                │    │   0.8 s                │      │
│   │                        │    │                        │      │
│   │ ■ Speed Index          │    │ ● Speed Index          │      │
│   │   5.7 s                │    │   0.8 s                │      │
│   │                        │    │                        │      │
│   │ ▲ Largest Contentful   │    │ ■ Largest Contentful   │      │
│   │   8.5 s                │    │   1.5 s                │      │
│   │                        │    │                        │      │
│   │ ▲ Time to Interactive  │    │ ● Time to Interactive  │      │
│   │   8.5 s                │    │   1.5 s                │      │
│   │                        │    │                        │      │
│   │ ● Total Blocking Time  │    │ ● Total Blocking Time  │      │
│   │   110 ms               │    │   70 ms                │      │
│   │                        │    │                        │      │
│   │ ● Cumulative Layout    │    │ ● Cumulative Layout    │      │
│   │   0                    │    │   0.019                │      │
│   │                        │    │                        │      │
│   └────────────────────────┘    └────────────────────────┘      │
│                                                                 │
│          ▲ 0-49       ■ 50-89       ● 90-100                    │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

**Performance Card:**
```css
.perf-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px;
    width: 48%;
}

.perf-score-circle {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 20px auto;
    font-size: 28pt;
    font-weight: 700;
}

.perf-metric {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}

.perf-metric-indicator {
    width: 12px;
    height: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 8px;
}

.perf-metric-indicator.good { color: #22c55e; }
.perf-metric-indicator.moderate { color: #f59e0b; }
.perf-metric-indicator.poor { color: #ef4444; }

.perf-metric-indicator.good::before { content: "●"; }
.perf-metric-indicator.moderate::before { content: "■"; }
.perf-metric-indicator.poor::before { content: "▲"; }
```

---

### PAGE 12: Thank You

**Layout:** Centered message

```
┌─────────────────────────────────────────────────────────────────┐
│ [Logo]                     Raport {Name} # {period}             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│          Mulțumim pentru                                        │
│          colaborare!                                            │
│                                                                 │
│          Acest raport marchează activitățile esențiale          │
│          efectuate pentru a menține website-ul dvs. în          │
│          siguranță și la cele mai bune standarde.               │
│          Apreciem parteneriatul nostru și ne bucurăm să         │
│          construim împreună o prezență online solidă.           │
│          Dacă aveți nelămuriri sau idei de îmbunătățire,        │
│          echipa noastră este mereu aici pentru a vă             │
│          sprijini.                                              │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

---

### PAGE 13: Final Page (Company Branding)

**Layout:** Full page with large Simplead logo, centered

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                    ┌─────────────────────┐                      │
│                    │                     │                      │
│                    │    SIMPLEAD LOGO    │                      │
│                    │       (large)       │                      │
│                    │                     │                      │
│                    │  GRAFICĂ & MARKETING│                      │
│                    │      DIGITAL        │                      │
│                    │                     │                      │
│                    └─────────────────────┘                      │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│                         [Simplead]                              │
└─────────────────────────────────────────────────────────────────┘
```

---

## GLOBAL CSS IMPROVEMENTS

```css
/* Base */
* {
    box-sizing: border-box;
}

body {
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    font-size: 10pt;
    line-height: 1.5;
    color: #1f2937;
    background: white;
}

/* Page setup */
@page {
    size: A4 portrait;
    margin: 20mm 18mm 25mm 18mm;
}

@page :first {
    margin: 0;
}

.page {
    page-break-after: always;
}

.page:last-child {
    page-break-after: auto;
}

/* Typography */
h1 {
    font-size: 24pt;
    font-weight: 700;
    color: #1e1b4b;
    margin-bottom: 24px;
}

h2 {
    font-size: 14pt;
    font-weight: 600;
    color: #1e1b4b;
    margin-bottom: 16px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

h3 {
    font-size: 11pt;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 12px;
}

/* Section header with icon */
.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

.section-icon {
    font-size: 16pt;
}

/* Cards grid */
.cards-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}

.card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    flex: 1;
    min-width: 200px;
}

/* Tables */
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9pt;
}

th {
    background: #f8fafc;
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    font-size: 8pt;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

td {
    padding: 12px 16px;
    border-bottom: 1px solid #f3f4f6;
    color: #1f2937;
}

tr:last-child td {
    border-bottom: none;
}

/* Status colors */
.text-success { color: #16a34a; }
.text-warning { color: #d97706; }
.text-danger { color: #dc2626; }
.text-muted { color: #6b7280; }

.bg-success { background: #dcfce7; }
.bg-warning { background: #fef3c7; }
.bg-danger { background: #fee2e2; }

/* Utilities */
.text-center { text-align: center; }
.text-right { text-align: right; }
.font-bold { font-weight: 700; }
.text-sm { font-size: 9pt; }
.text-xs { font-size: 8pt; }
.text-lg { font-size: 14pt; }
.text-xl { font-size: 18pt; }
.text-2xl { font-size: 24pt; }

.mt-4 { margin-top: 16px; }
.mb-4 { margin-bottom: 16px; }
.mb-6 { margin-bottom: 24px; }
.mb-8 { margin-bottom: 32px; }

.flex { display: flex; }
.flex-col { flex-direction: column; }
.items-center { align-items: center; }
.justify-between { justify-content: space-between; }
.gap-4 { gap: 16px; }
```

---

## IMPLEMENTATION NOTES

1. **Use DomPDF or Browsershot** — Browsershot (Puppeteer) renders charts better
2. **Charts** — Pre-render as SVG or use base64 images (DomPDF doesn't execute JS)
3. **Fonts** — Embed fonts or use system fonts that DomPDF supports
4. **Images** — Use absolute paths or base64 encoded
5. **Test margins** — Print to PDF and verify nothing is cut off

---

## SUMMARY OF FIXES

| Issue | Before | After |
|-------|--------|-------|
| Margins | 0 (content touching edges) | 20mm top, 18mm sides, 25mm bottom |
| Header | None | Logo + report title on every page |
| Footer | None | Simplead logo on every page |
| Cover | Basic text | Full branded page with client logo |
| Metric cards | Plain text | Styled cards with borders, shadows |
| Tables | Basic | Alternating rows, proper spacing |
| Performance scores | Numbers only | Colored circles with indicators |
| Charts | None/basic | Proper area/line charts |
| Typography | Inconsistent | Hierarchy with proper sizing |
| Colors | Random | Consistent brand palette |
