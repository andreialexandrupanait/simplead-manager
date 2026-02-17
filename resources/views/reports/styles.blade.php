<style>
    /* ============================================================
       PDF Report Styles — Premium Redesign (Navy/Slate palette)
       DomPDF Compatible — @page margin with page_script header
       ============================================================ */

    /* --- @page --- */
    @page {
        size: A4 portrait;
        margin: 24mm 0 10mm 0;
    }
    @page :first {
        margin: 0;
    }

    /* --- Reset --- */
    html, body, div, table, thead, tbody, tr, th, td,
    h1, h2, h3, h4, h5, h6, p, span, img, svg {
        margin: 0;
        padding: 0;
        font-family: 'DejaVu Sans', sans-serif;
    }

    /* --- Typography --- */
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 8.5pt;
        line-height: 1.7;
        color: #334155;
        background: #ffffff;
    }

    h2 {
        font-size: 14pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 12px;
    }

    h3 {
        font-size: 10pt;
        font-weight: 700;
        color: #334155;
        margin-bottom: 10px;
    }

    /* --- Page structure --- */
    .page {
        padding: 0 14mm 0 14mm;
    }

    .page-first {
        /* cover's page-break-after is sufficient */
    }

    /* Prevent awkward splits inside cards, tables, sections */
    .no-break,
    .kpi-grid,
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

    /* --- Page header (drawn by page_script) --- */
    .page-header {
        width: 100%;
        margin-bottom: 16px;
    }
    .page-header table {
        width: 100%;
        border-collapse: collapse;
    }
    .page-header td {
        padding: 0 0 6px 0;
        vertical-align: middle;
    }
    .page-header .header-logo {
        max-height: 26px;
    }
    .page-header .header-title {
        text-align: right;
        font-size: 8pt;
        color: #64748b;
    }

    /* --- Page footer --- */
    .page-footer {
        text-align: center;
        font-size: 7pt;
        color: #94a3b8;
        padding-top: 16px;
        margin-top: 24px;
        border-top: 1px solid #e2e8f0;
    }
    .page-footer .footer-logo {
        max-height: 18px;
        margin-bottom: 2px;
    }

    /* --- Cover page (full bleed via negative margin into @page margin area) --- */
    .cover-page {
        width: 210mm;
        height: 297mm;
        margin: 0;
        padding: 0;
        page-break-after: always;
        position: relative;
        overflow: hidden;
        background: #0f172a;
        color: #ffffff;
        text-align: center;
    }
    .cover-content {
        padding: 60mm 23mm 20mm 23mm;
    }
    .cover-client-logo {
        max-width: 180px;
        max-height: 80px;
        margin-bottom: 20px;
    }
    .cover-site-name {
        font-size: 22pt;
        font-weight: 700;
        color: #ffffff;
        opacity: 0.95;
        margin-bottom: 20px;
    }
    .cover-accent-line {
        width: 60px;
        height: 3px;
        background: #2563eb;
        margin: 0 auto 20px auto;
    }
    .cover-title {
        font-size: 32pt;
        font-weight: 700;
        line-height: 1.2;
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
        max-width: none;
        margin-left: 0;
        text-align: left;
    }
    .cover-sections {
        margin-top: 16px;
        font-size: 9pt;
        opacity: 0.5;
        text-align: left;
    }
    .cover-sections-item {
        padding: 2px 0;
        text-align: left;
    }
    .cover-company-logo {
        position: absolute;
        bottom: 20mm;
        left: 50%;
        margin-left: -60px;
        max-height: 50px;
    }

    /* --- Intro page --- */
    .intro-title {
        font-size: 24pt;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.25;
        margin-bottom: 20px;
        margin-top: 30px;
    }
    .intro-body {
        font-size: 8.5pt;
        line-height: 1.8;
        color: #334155;
        margin-bottom: 24px;
    }
    .intro-sections-title {
        font-size: 8pt;
        font-weight: 600;
        color: #64748b;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .intro-section-item {
        padding: 7px 10px;
        font-size: 8.5pt;
        color: #334155;
        border-bottom: 1px solid #f1f5f9;
    }
    .intro-section-check {
        font-weight: 700;
        margin-right: 8px;
    }

    /* --- Section header --- */
    .section-header {
        margin-bottom: 18px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-number {
        font-size: 7pt;
        font-weight: 600;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 4px;
    }
    .section-header-title {
        font-size: 15pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 6px;
    }
    .section-header-line {
        width: 50px;
        height: 3px;
        border-radius: 0;
        background-color: #2563eb;
    }

    /* --- Card grid (overview, KPI rows) --- */
    .card-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .overview-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .overview-card {
        background: #f1f5f9;
        border: none;
        border-radius: 0;
        padding: 10px 12px;
        vertical-align: top;
    }
    .card-label {
        font-size: 8pt;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }
    .card-value {
        font-size: 18pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 2px;
    }
    .card-value-sm {
        font-size: 14pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 2px;
    }
    .card-sublabel {
        font-size: 8pt;
        color: #94a3b8;
        margin-bottom: 4px;
    }
    .card-trend {
        font-size: 7pt;
        font-weight: 700;
    }

    /* --- Trend colors --- */
    .trend-up { color: #10b981; }
    .trend-down { color: #ef4444; }
    .trend-neutral { color: #64748b; }

    /* --- Score gauge (number + progress bar) --- */
    /* No special CSS needed — fully inline-styled in the component */

    /* --- Data tables (light header, subtle borders) --- */
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
        color: #334155;
        letter-spacing: 0.5px;
        padding: 8px 12px;
        background: #f1f5f9;
        border-bottom: 2px solid #e2e8f0;
    }
    .data-table td {
        padding: 9px 12px;
        color: #334155;
        border-bottom: 1px solid #e2e8f0;
        font-size: 8pt;
        line-height: 1.6;
    }
    .data-table thead tr {
        page-break-inside: avoid;
    }
    .data-table tbody tr {
        page-break-inside: avoid;
    }

    /* --- KPI grid --- */
    .kpi-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .kpi-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-top: 3px solid #2563eb;
        border-radius: 0;
        padding: 14px 16px;
        vertical-align: top;
        text-align: center;
        height: 80px;
    }
    .kpi-value {
        font-size: 18pt;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.1;
        height: 24px;
        margin-bottom: 2px;
    }
    .kpi-label {
        font-size: 7.5pt;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        height: 16px;
        margin-bottom: 0;
    }

    /* --- Summary badges --- */
    .summary-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .summary-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0;
        padding: 10px 14px;
        text-align: center;
        vertical-align: top;
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
        color: #0f172a;
    }

    /* --- Uptime styles --- */
    .uptime-percentage {
        font-size: 28pt;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 4px;
    }
    .uptime-percentage.good { color: #10b981; }
    .uptime-percentage.warning { color: #f59e0b; }
    .uptime-percentage.bad { color: #ef4444; }

    .uptime-bar {
        width: 100%;
        height: 10px;
        background: #e2e8f0;
        border-radius: 0;
        overflow: hidden;
        margin-bottom: 12px;
    }
    .uptime-segment {
        float: left;
        height: 10px;
    }
    .uptime-segment.up { background: #10b981; }
    .uptime-segment.down { background: #ef4444; }

    .status-up { color: #10b981; font-weight: 600; font-size: 8.5pt; }
    .status-down { color: #ef4444; font-weight: 600; font-size: 8.5pt; }

    /* --- Performance indicators --- */
    .perf-indicator {
        font-weight: 700;
        margin-right: 4px;
    }
    .perf-indicator.good { color: #10b981; }
    .perf-indicator.moderate { color: #f59e0b; }
    .perf-indicator.poor { color: #ef4444; }

    .perf-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-top: 3px solid #2563eb;
        border-radius: 0;
        padding: 18px;
        vertical-align: top;
    }
    .perf-card-title {
        font-size: 11pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 2px;
    }
    .perf-card-subtitle {
        font-size: 7.5pt;
        color: #94a3b8;
        margin-bottom: 14px;
    }
    .perf-metric-row {
        width: 100%;
        border-collapse: collapse;
    }
    .perf-metric-row td {
        padding: 6px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 8.5pt;
    }
    .perf-metric-row tr:last-child td {
        border-bottom: none;
    }
    .perf-legend {
        font-size: 8pt;
        color: #94a3b8;
        margin-top: 14px;
        text-align: center;
    }

    /* --- Progress bars --- */
    .progress-bar {
        width: 100%;
        height: 6px;
        background: #e2e8f0;
        border-radius: 0;
        overflow: hidden;
    }
    .progress-fill {
        height: 6px;
        border-radius: 0;
    }
    .progress-fill.primary { background: #2563eb; }
    .progress-fill.blue { background: #2563eb; }
    .progress-fill.green { background: #10b981; }
    .progress-fill.amber { background: #f59e0b; }

    /* --- 2x2 quad grid layout --- */
    .quad-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .quad-grid td {
        width: 50%;
        vertical-align: top;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0;
        padding: 12px;
    }
    .quad-grid h3 {
        font-size: 10pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 6px;
    }
    .quad-grid .data-table {
        margin-bottom: 0;
    }
    .quad-grid .data-table th {
        font-size: 7pt;
        padding: 5px 6px;
        background: #f1f5f9;
        color: #334155;
    }
    .quad-grid .data-table td {
        font-size: 8pt;
        padding: 5px 6px;
    }

    /* --- Two-column layout --- */
    .two-col {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px 0;
    }
    .two-col td {
        width: 50%;
        vertical-align: top;
    }

    /* --- Badges --- */
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 7pt;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .badge-success { background: #dcfce7; color: #16a34a; }
    .badge-warning { background: #fef3c7; color: #d97706; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-info { background: #dbeafe; color: #2563eb; }

    /* --- Version arrow --- */
    .version-arrow { color: #94a3b8; }
    .version-new { font-weight: 700; color: #0f172a; }

    /* --- Check marks --- */
    .check-success { color: #10b981; font-weight: 700; }

    /* --- Thank you / Closing page --- */
    .closing-page {
        text-align: center;
        padding-top: 60px;
    }
    .closing-title {
        font-size: 22pt;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.3;
        margin-bottom: 16px;
    }
    .closing-divider {
        width: 50px;
        height: 3px;
        margin: 20px auto;
        background-color: #2563eb;
    }
    .closing-text {
        font-size: 9.5pt;
        color: #64748b;
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
        color: #0f172a;
    }

    /* --- Chart containers --- */
    .chart-container {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0;
        padding: 16px;
        margin-bottom: 16px;
    }
    .chart-title {
        font-size: 10pt;
        font-weight: 600;
        color: #334155;
        margin-bottom: 10px;
    }

    /* --- Highlight box --- */
    .highlight-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #2563eb;
        border-radius: 0;
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
        border-bottom: 1px solid #f1f5f9;
    }
    .info-row tr:last-child td {
        border-bottom: none;
    }
    .info-label { color: #64748b; width: 40%; font-size: 8.5pt; }
    .info-value { font-weight: 600; font-size: 8.5pt; }

    /* --- Text utilities --- */
    .text-success { color: #10b981; }
    .text-warning { color: #f59e0b; }
    .text-danger { color: #ef4444; }
    .text-muted { color: #64748b; }
    .text-light { color: #94a3b8; }
    .text-sm { font-size: 8.5pt; }
    .text-xs { font-size: 8pt; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }

    /* --- Value muted (zero/weak/no data) --- */
    .value-muted { color: #94a3b8 !important; }

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
        color: #94a3b8;
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
        border-top: 1px solid #e2e8f0;
        margin: 12px 0 6px 0;
    }

    /* --- Security section --- */
    .security-score-box {
        text-align: center;
        padding: 14px;
        background: #f1f5f9;
        border-radius: 0;
        vertical-align: top;
    }
    .security-summary-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }
    .security-summary-table td {
        padding: 6px 10px;
        font-size: 8.5pt;
        border-bottom: 1px solid #f1f5f9;
    }
    .severity-critical { color: #dc2626; font-weight: 700; }
    .severity-high { color: #ea580c; font-weight: 700; }
    .severity-medium { color: #d97706; font-weight: 600; }
    .severity-low { color: #64748b; }

    /* --- Section spacing for flowing content --- */
    .section-block {
        margin-bottom: 28px;
    }
    .section-break {
        page-break-before: always;
    }

    /* --- Header spacer (pushes content below the page_script header bar) --- */
    .header-spacer {
        height: 22mm;
    }

    /* --- Section description text --- */
    .section-description {
        font-size: 8.5pt;
        color: #64748b;
        line-height: 1.7;
        margin-bottom: 16px;
    }

    /* --- Executive Snapshot grid --- */
    .snapshot-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .snapshot-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0;
        padding: 14px 16px;
        vertical-align: top;
        width: 25%;
    }
    .snapshot-value {
        font-size: 18pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }
    .snapshot-label {
        font-size: 7.5pt;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .snapshot-note {
        font-size: 7pt;
        color: #94a3b8;
        margin-top: 4px;
    }
    .snapshot-status-good { border-left: 4px solid #10b981; }
    .snapshot-status-warning { border-left: 4px solid #f59e0b; }
    .snapshot-status-danger { border-left: 4px solid #ef4444; }
    .snapshot-status-neutral { border-left: 4px solid #94a3b8; }

    /* --- Executive Snapshot hero cards --- */
    .snapshot-hero-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .snapshot-hero-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0;
        padding: 18px 20px;
        vertical-align: top;
        width: 50%;
    }
    .snapshot-hero-value {
        font-size: 24pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }

    /* --- Recommendation cards --- */
    .rec-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0;
        padding: 12px 16px;
        margin-bottom: 10px;
        page-break-inside: avoid;
    }
    .rec-title {
        font-size: 9pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }
    .rec-description {
        font-size: 8pt;
        color: #64748b;
        line-height: 1.6;
    }
    .rec-priority-high { border-left: 4px solid #ef4444; }
    .rec-priority-medium { border-left: 4px solid #f59e0b; }
    .rec-priority-low { border-left: 4px solid #94a3b8; }
    .rec-category-label {
        font-size: 8pt;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 10px;
        margin-top: 16px;
        padding-bottom: 6px;
    }

    /* --- Sub-cards (for security/database inside Technical Stability) --- */
    .subcard {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #2563eb;
        border-radius: 0;
        padding: 14px 16px;
        margin-bottom: 14px;
        page-break-inside: avoid;
    }
    .subcard-title {
        font-size: 10pt;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid #f1f5f9;
    }

    /* --- Chart legend --- */
    .chart-legend {
        font-size: 8pt;
        color: #64748b;
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
        border-radius: 0;
        vertical-align: middle;
        margin-right: 4px;
    }

    /* --- Footnote --- */
    .table-footnote {
        font-size: 7pt;
        color: #94a3b8;
        margin-top: 4px;
    }
</style>
