<style>
    /* ============================================================
       PDF Report Styles — Chrome/Gotenberg Renderer
       Modern design with Inter font, flexbox, rounded corners
       ============================================================ */

    /* --- CSS Variables --- */
    :root {
        --navy: #0f172a;
        --slate-900: #0f172a;
        --slate-700: #334155;
        --slate-500: #64748b;
        --slate-400: #94a3b8;
        --slate-200: #e2e8f0;
        --slate-100: #f1f5f9;
        --slate-50: #f8fafc;
        --white: #ffffff;
        --blue-600: #2563eb;
        --blue-100: #dbeafe;
        --green-500: #10b981;
        --green-50: #ecfdf5;
        --amber-500: #f59e0b;
        --amber-50: #fffbeb;
        --red-500: #ef4444;
        --red-50: #fef2f2;
        --radius: 8px;
        --radius-sm: 4px;
        --radius-pill: 99px;
        --shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
    }

    /* --- @page --- */
    @page {
        size: A4 portrait;
        margin: 0;
    }

    /* --- Reset --- */
    *, *::before, *::after {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    /* --- Typography --- */
    body {
        font-family: 'Inter', sans-serif;
        font-size: 8.5pt;
        line-height: 1.7;
        color: var(--slate-700);
        background: var(--white);
    }

    h2 {
        font-size: 14pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 12px;
    }

    h3 {
        font-size: 10pt;
        font-weight: 700;
        color: var(--slate-700);
        margin-bottom: 10px;
    }

    /* --- Page header bar (in-flow, full-bleed via negative margins) --- */
    .page-header-bar {
        margin-left: -14mm;
        margin-right: -14mm;
        padding: 3mm 14mm;
        background: var(--navy);
        margin-bottom: 4mm;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .page-header-logo {
        height: 18px;
        width: auto;
        display: block;
    }
    .page-header-company {
        font-size: 10pt;
        font-weight: 700;
        color: #ffffff;
    }
    .page-header-right {
        text-align: right;
    }
    .page-header-title {
        font-size: 7.5pt;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.85);
        line-height: 1.6;
        letter-spacing: 0.5px;
    }
    .page-header-sub {
        font-size: 6.5pt;
        color: rgba(255, 255, 255, 0.5);
        line-height: 1.6;
    }

    /* --- Page structure --- */
    .page {
        padding: 8mm 14mm 0 14mm;
    }

    /* Prevent awkward splits inside cards, tables, sections */
    .no-break,
    .kpi-row,
    .overview-grid,
    .highlight-box,
    .chart-container,
    .summary-grid,
    .section-header {
        page-break-inside: avoid;
    }

    .report-section {
        page-break-inside: avoid;
    }

    /* --- Cover page --- */
    .cover-page {
        margin: 0;
        padding: 0;
        width: 210mm;
        min-height: 297mm;
        page-break-after: always;
        position: relative;
        overflow: hidden;
        background: var(--navy);
        color: var(--white);
        text-align: center;
    }
    .cover-page::before {
        content: '';
        position: absolute;
        top: -40%;
        right: -20%;
        width: 80%;
        height: 80%;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(37, 99, 235, 0.15) 0%, transparent 70%);
        pointer-events: none;
    }
    .cover-page::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: -15%;
        width: 60%;
        height: 60%;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, transparent 70%);
        pointer-events: none;
    }
    .cover-content {
        position: relative;
        z-index: 1;
        padding: 75mm 30mm 20mm 30mm;
    }
    .cover-client-logo {
        max-width: 180px;
        max-height: 80px;
        margin-bottom: 20px;
    }
    .cover-site-name {
        font-size: 18pt;
        font-weight: 700;
        color: var(--white);
        opacity: 0.95;
        margin-bottom: 20px;
    }
    .cover-accent-line {
        width: 60px;
        height: 3px;
        background: var(--blue-600);
        border-radius: 2px;
        margin: 0 auto 20px auto;
    }
    .cover-title {
        font-size: 30pt;
        font-weight: 800;
        line-height: 1.15;
        letter-spacing: -0.5px;
        margin-bottom: 10px;
    }
    .cover-url {
        font-size: 14pt;
        opacity: 0.8;
        margin-bottom: 8px;
    }
    .cover-date {
        font-size: 12pt;
        opacity: 0.65;
        margin-bottom: 24px;
    }
    .cover-intro {
        font-size: 10pt;
        line-height: 1.6;
        opacity: 0.6;
        margin-top: 16px;
        text-align: center;
    }
    .cover-sections {
        margin-top: 16px;
        font-size: 9pt;
        opacity: 0.5;
        text-align: center;
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 6px 12px;
    }
    .cover-sections-item {
        padding: 3px 10px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: var(--radius-pill);
        font-size: 8pt;
        white-space: nowrap;
    }
    .cover-company-logo {
        position: absolute;
        bottom: 20mm;
        left: 50%;
        transform: translateX(-50%);
        max-height: 50px;
    }

    /* --- Section header --- */
    .section-header {
        margin-bottom: 18px;
    }
    .section-number {
        font-size: 7pt;
        font-weight: 600;
        color: var(--slate-400);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 4px;
    }
    .section-header-title {
        font-size: 15pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 6px;
    }
    .section-header-line {
        width: 50px;
        height: 3px;
        border-radius: 2px;
        background-color: var(--blue-600);
    }

    /* --- Flex row utility --- */
    .flex-row {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
    }
    .flex-row > * {
        flex: 1;
        min-width: 0;
    }

    /* --- KPI cards (flex-based) --- */
    .kpi-row {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
    }
    .kpi-card {
        flex: 1;
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-top: 3px solid var(--blue-600);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 12px 14px;
        text-align: center;
    }
    .kpi-value {
        font-size: 16pt;
        font-weight: 700;
        color: var(--navy);
        line-height: 1.1;
        margin-bottom: 2px;
        font-feature-settings: 'tnum';
    }
    .kpi-label {
        font-size: 7.5pt;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 0;
    }

    /* --- Card grid (overview, snapshot) --- */
    .overview-grid {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
    }
    .overview-card {
        flex: 1;
        background: var(--slate-100);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 10px 12px;
    }
    .card-label {
        font-size: 8pt;
        font-weight: 700;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }
    .card-value {
        font-size: 18pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 2px;
        font-feature-settings: 'tnum';
    }
    .card-value-sm {
        font-size: 14pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 2px;
        font-feature-settings: 'tnum';
    }
    .card-sublabel {
        font-size: 8pt;
        color: var(--slate-400);
        margin-bottom: 4px;
    }
    .card-trend {
        font-size: 7pt;
        font-weight: 700;
    }

    /* --- Trend colors --- */
    .trend-up { color: var(--green-500); }
    .trend-down { color: var(--red-500); }
    .trend-neutral { color: var(--slate-500); }

    /* --- Data tables --- */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
        font-size: 8pt;
    }
    .data-table th {
        text-align: left;
        font-size: 7.5pt;
        font-weight: 600;
        color: var(--slate-700);
        letter-spacing: 0.5px;
        padding: 6px 10px;
        background: var(--slate-100);
        border-bottom: 2px solid var(--slate-200);
    }
    .data-table td {
        padding: 7px 10px;
        color: var(--slate-700);
        border-bottom: 1px solid var(--slate-200);
        font-size: 8pt;
        line-height: 1.5;
    }
    .data-table tbody tr:nth-child(even) {
        background: var(--slate-50);
    }
    .data-table thead tr,
    .data-table tbody tr {
        page-break-inside: avoid;
    }

    /* --- Summary badges --- */
    .summary-grid {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
    }
    .summary-card {
        flex: 1;
        background: var(--slate-50);
        border: 1px solid var(--slate-200);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 10px 14px;
        text-align: center;
    }
    .summary-label {
        font-size: 8pt;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 4px;
    }
    .summary-value {
        font-size: 18pt;
        font-weight: 700;
        color: var(--navy);
        font-feature-settings: 'tnum';
    }

    /* --- Uptime styles --- */
    .uptime-percentage {
        font-size: 28pt;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 4px;
        font-feature-settings: 'tnum';
    }
    .uptime-percentage.good { color: var(--green-500); }
    .uptime-percentage.warning { color: var(--amber-500); }
    .uptime-percentage.bad { color: var(--red-500); }

    .uptime-bar {
        width: 100%;
        height: 10px;
        background: var(--slate-200);
        border-radius: var(--radius-pill);
        overflow: hidden;
        margin-bottom: 12px;
    }
    .uptime-segment {
        float: left;
        height: 10px;
    }
    .uptime-segment.up { background: var(--green-500); }
    .uptime-segment.down { background: var(--red-500); }

    .status-up { color: var(--green-500); font-weight: 600; font-size: 8.5pt; }
    .status-down { color: var(--red-500); font-weight: 600; font-size: 8.5pt; }

    /* --- Performance indicators --- */
    .perf-indicator {
        font-weight: 700;
        margin-right: 4px;
    }
    .perf-indicator.good { color: var(--green-500); }
    .perf-indicator.moderate { color: var(--amber-500); }
    .perf-indicator.poor { color: var(--red-500); }

    .perf-card {
        flex: 1;
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-top: 3px solid var(--blue-600);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 18px;
    }
    .perf-card-title {
        font-size: 11pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 2px;
    }
    .perf-card-subtitle {
        font-size: 7.5pt;
        color: var(--slate-400);
        margin-bottom: 14px;
    }
    .perf-metric-row {
        width: 100%;
        border-collapse: collapse;
    }
    .perf-metric-row td {
        padding: 6px 0;
        border-bottom: 1px solid var(--slate-100);
        font-size: 8.5pt;
    }
    .perf-metric-row tr:last-child td {
        border-bottom: none;
    }
    .perf-legend {
        font-size: 8pt;
        color: var(--slate-400);
        margin-top: 14px;
        text-align: center;
    }

    /* --- Progress bars --- */
    .progress-bar {
        width: 100%;
        height: 8px;
        background: var(--slate-200);
        border-radius: var(--radius-pill);
        overflow: hidden;
    }
    .progress-fill {
        height: 8px;
        border-radius: var(--radius-pill);
    }
    .progress-fill.primary { background: var(--blue-600); }
    .progress-fill.blue { background: var(--blue-600); }
    .progress-fill.green { background: var(--green-500); }
    .progress-fill.amber { background: var(--amber-500); }

    /* --- Two-column flex layout --- */
    .two-col {
        display: flex;
        gap: 10px;
    }
    .two-col > * {
        flex: 1;
        min-width: 0;
    }

    /* --- Badges --- */
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: var(--radius-sm);
        font-size: 7pt;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .badge-success { background: #dcfce7; color: #16a34a; }
    .badge-warning { background: #fef3c7; color: #d97706; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-info { background: var(--blue-100); color: var(--blue-600); }

    /* --- Priority badge pills --- */
    .badge-pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: var(--radius-pill);
        font-size: 6.5pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .badge-pill-high { background: #fee2e2; color: #dc2626; }
    .badge-pill-medium { background: #fef3c7; color: #d97706; }
    .badge-pill-low { background: var(--slate-100); color: var(--slate-500); }

    /* --- Version arrow --- */
    .version-arrow { color: var(--slate-400); }
    .version-new { font-weight: 700; color: var(--navy); }

    /* --- Check marks --- */
    .check-success { color: var(--green-500); font-weight: 700; }

    /* --- Closing page (full-bleed, mirrors cover) --- */
    .closing-page {
        margin: 0;
        padding: 0;
        width: 210mm;
        min-height: 297mm;
        position: relative;
        overflow: hidden;
        background: var(--navy);
        color: var(--white);
        text-align: center;
    }
    .closing-page::before {
        content: '';
        position: absolute;
        bottom: -40%;
        left: -20%;
        width: 80%;
        height: 80%;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(37, 99, 235, 0.15) 0%, transparent 70%);
        pointer-events: none;
    }
    .closing-page::after {
        content: '';
        position: absolute;
        top: -30%;
        right: -15%;
        width: 60%;
        height: 60%;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(37, 99, 235, 0.1) 0%, transparent 70%);
        pointer-events: none;
    }
    .closing-content {
        position: relative;
        z-index: 1;
        padding: 80mm 30mm 20mm 30mm;
    }
    .closing-title {
        font-size: 26pt;
        font-weight: 700;
        color: var(--white);
        line-height: 1.3;
        margin-bottom: 16px;
    }
    .closing-divider {
        width: 60px;
        height: 3px;
        margin: 20px auto;
        background: var(--blue-600);
        border-radius: 2px;
    }
    .closing-text {
        font-size: 10pt;
        color: rgba(255, 255, 255, 0.6);
        line-height: 1.8;
        max-width: 100%;
        margin: 0 auto 28px auto;
    }
    .closing-logo {
        max-width: 200px;
        max-height: 80px;
        margin-bottom: 8px;
    }
    .closing-company {
        font-size: 12pt;
        font-weight: 600;
        color: var(--white);
        opacity: 0.9;
    }
    .closing-website {
        font-size: 9pt;
        color: rgba(255, 255, 255, 0.45);
        margin-top: 4px;
    }

    /* --- Chart containers --- */
    .chart-container {
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 16px;
        margin-bottom: 16px;
    }
    .chart-title {
        font-size: 10pt;
        font-weight: 600;
        color: var(--slate-700);
        margin-bottom: 10px;
    }

    /* --- Highlight box --- */
    .highlight-box {
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-left: 4px solid var(--blue-600);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 16px 18px;
        margin-bottom: 16px;
    }

    /* --- Info rows --- */
    .info-row {
        width: 100%;
        border-collapse: collapse;
    }
    .info-row td {
        padding: 7px 0;
        border-bottom: 1px solid var(--slate-100);
    }
    .info-row tr:last-child td {
        border-bottom: none;
    }
    .info-label { color: var(--slate-500); width: 40%; font-size: 8.5pt; }
    .info-value { font-weight: 600; font-size: 8.5pt; }

    /* --- Text utilities --- */
    .text-success { color: var(--green-500); }
    .text-warning { color: var(--amber-500); }
    .text-danger { color: var(--red-500); }
    .text-muted { color: var(--slate-500); }
    .text-light { color: var(--slate-400); }
    .text-sm { font-size: 8.5pt; }
    .text-xs { font-size: 8pt; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .value-muted { color: var(--slate-400) !important; }

    /* --- Margin utilities --- */
    .mb-2 { margin-bottom: 4px; }
    .mb-4 { margin-bottom: 10px; }
    .mb-6 { margin-bottom: 14px; }
    .mb-8 { margin-bottom: 18px; }
    .mt-2 { margin-top: 4px; }
    .mt-4 { margin-top: 10px; }
    .mt-6 { margin-top: 14px; }
    .mt-8 { margin-top: 18px; }

    .no-break {
        page-break-inside: avoid;
    }

    /* --- Overview group labels --- */
    .overview-group-label {
        font-size: 7pt;
        font-weight: 700;
        color: var(--slate-400);
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-top: 14px;
        margin-bottom: 4px;
    }
    .overview-group-label:first-child {
        margin-top: 0;
    }
    .overview-divider {
        border: none;
        border-top: 1px solid var(--slate-200);
        margin: 12px 0 6px 0;
    }

    /* --- Security section --- */
    .security-score-box {
        text-align: center;
        padding: 14px;
        background: var(--slate-100);
        border-radius: var(--radius);
    }
    .security-summary-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }
    .security-summary-table td {
        padding: 6px 10px;
        font-size: 8.5pt;
        border-bottom: 1px solid var(--slate-100);
    }
    .severity-critical { color: #dc2626; font-weight: 700; }
    .severity-high { color: #ea580c; font-weight: 700; }
    .severity-medium { color: #d97706; font-weight: 600; }
    .severity-low { color: var(--slate-500); }

    /* --- Section spacing --- */
    .section-block {
        margin-bottom: 20px;
    }
    .section-break {
        page-break-before: always;
    }

    /* --- Section description --- */
    .section-description {
        font-size: 8.5pt;
        color: var(--slate-500);
        line-height: 1.7;
        margin-bottom: 16px;
    }

    /* --- Executive Snapshot grid --- */
    .snapshot-hero-grid {
        display: flex;
        gap: 8px;
        margin-bottom: 8px;
    }
    .snapshot-hero-card {
        flex: 1;
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 18px 20px;
    }
    .snapshot-hero-value {
        font-size: 22pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 4px;
        font-feature-settings: 'tnum';
    }

    .snapshot-grid {
        display: flex;
        gap: 8px;
        margin-bottom: 8px;
    }
    .snapshot-card {
        flex: 1;
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 14px 16px;
    }
    .snapshot-value {
        font-size: 16pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 4px;
        font-feature-settings: 'tnum';
    }
    .snapshot-label {
        font-size: 7.5pt;
        font-weight: 600;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .snapshot-note {
        font-size: 7pt;
        color: var(--slate-400);
        margin-top: 4px;
    }
    .snapshot-status-good { border-left: 4px solid var(--green-500); }
    .snapshot-status-warning { border-left: 4px solid var(--amber-500); }
    .snapshot-status-danger { border-left: 4px solid var(--red-500); }
    .snapshot-status-neutral { border-left: 4px solid var(--slate-400); }

    /* Status tint backgrounds for snapshot cards */
    .snapshot-status-good { background: linear-gradient(135deg, #f0fdf4, var(--white)); }
    .snapshot-status-warning { background: linear-gradient(135deg, #fffbeb, var(--white)); }
    .snapshot-status-danger { background: linear-gradient(135deg, #fef2f2, var(--white)); }

    /* --- Recommendation cards --- */
    .rec-card {
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 12px 16px;
        margin-bottom: 10px;
        page-break-inside: avoid;
    }
    .rec-title {
        font-size: 9pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 4px;
    }
    .rec-description {
        font-size: 8pt;
        color: var(--slate-500);
        line-height: 1.6;
    }
    .rec-priority-high { border-left: 6px solid var(--red-500); }
    .rec-priority-medium { border-left: 6px solid var(--amber-500); }
    .rec-priority-low { border-left: 6px solid var(--slate-400); }
    .rec-category-label {
        font-size: 8pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 10px;
        margin-top: 16px;
        padding-bottom: 6px;
    }

    /* --- Sub-cards (security/database inside Technical Stability, infrastructure) --- */
    .subcard {
        background: var(--white);
        border: 1px solid var(--slate-200);
        border-left: 4px solid var(--blue-600);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 14px 16px;
        margin-bottom: 14px;
        page-break-inside: avoid;
    }
    .subcard-title {
        font-size: 10pt;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid var(--slate-100);
    }
    .subcard-inner {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .subcard-field {
        flex: 1;
        min-width: 120px;
        padding: 6px 10px;
    }

    /* --- Chart legend --- */
    .chart-legend {
        font-size: 8pt;
        color: var(--slate-500);
        margin-bottom: 6px;
    }
    .chart-legend-item {
        display: inline-block;
        margin-right: 14px;
    }
    .chart-legend-swatch {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 2px;
        vertical-align: middle;
        margin-right: 4px;
    }

    /* --- Footnote --- */
    .table-footnote {
        font-size: 7pt;
        color: var(--slate-400);
        margin-top: 4px;
    }

    /* --- Donut chart layout --- */
    .donut-layout {
        display: flex;
        gap: 20px;
        align-items: center;
    }

    /* --- Email check indicators --- */
    .email-check {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 4px 0;
        border-bottom: 1px solid var(--slate-100);
        font-size: 8.5pt;
    }
    .email-check:last-child {
        border-bottom: none;
    }
    .email-indicator {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        font-weight: 700;
        font-size: 10pt;
        flex-shrink: 0;
    }
    .email-indicator.pass {
        background: var(--green-50);
        color: var(--green-500);
    }
    .email-indicator.fail {
        background: var(--red-50);
        color: var(--red-500);
    }

    /* --- Search console position coloring --- */
    .position-good { color: #16a34a; font-weight: 700; }
    .position-moderate { color: #d97706; font-weight: 700; }
    .position-poor { color: #dc2626; font-weight: 700; }

    /* --- WP Core status card --- */
    .wp-core-card {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--green-50);
        border: 1px solid #bbf7d0;
        border-radius: var(--radius);
        padding: 8px 14px;
        margin-bottom: 12px;
        font-size: 8.5pt;
        color: var(--slate-700);
    }
</style>
