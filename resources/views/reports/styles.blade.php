<style>
    /* ============================================================
       PDF Report Styles — DomPDF Compatible
       ============================================================ */

    /* --- @page rules --- */
    @page {
        size: A4 portrait;
        margin: 20mm 18mm 25mm 18mm;
        @top-center { content: element(page-header); }
        @bottom-center { content: element(page-footer); }
    }
    @page :first {
        margin: 0;
        @top-center { content: none; }
        @bottom-center { content: none; }
    }

    /* --- Reset --- */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'DejaVu Sans', sans-serif;
    }

    /* --- Running header / footer --- */
    .running-header {
        position: running(page-header);
        width: 100%;
        padding: 0 0 6px 0;
        border-bottom: 1px solid #e5e7eb;
        font-size: 8px;
        color: #6b7280;
    }
    .running-header table {
        width: 100%;
        border-collapse: collapse;
    }
    .running-header td {
        padding: 0;
        vertical-align: middle;
    }
    .running-header .header-logo {
        max-height: 22px;
    }
    .running-header .header-title {
        text-align: right;
        font-size: 8px;
        color: #6b7280;
        letter-spacing: 0.3px;
    }

    .running-footer {
        position: running(page-footer);
        width: 100%;
        padding: 6px 0 0 0;
        border-top: 1px solid #e5e7eb;
        text-align: center;
        font-size: 7px;
        color: #9ca3af;
    }

    /* --- Typography --- */
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 10pt;
        line-height: 1.5;
        color: #1f2937;
    }

    h1 {
        font-size: 24pt;
        font-weight: 700;
        color: #1e1b4b;
        margin-bottom: 8px;
    }

    h2 {
        font-size: 14pt;
        font-weight: 600;
        color: #1e1b4b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 14px;
        padding-bottom: 0;
    }

    h3 {
        font-size: 11pt;
        font-weight: 600;
        color: #6b7280;
        margin-bottom: 8px;
    }

    .page {
        page-break-after: always;
    }
    .page:last-child {
        page-break-after: auto;
    }

    /* --- Cover page --- */
    .cover-page {
        background: #f8f9fc;
        width: 100%;
        height: 100%;
        text-align: center;
        padding: 60mm 20mm 30mm 20mm;
        position: relative;
    }
    .cover-logo {
        max-width: 200px;
        max-height: 80px;
        margin-bottom: 30px;
    }
    .cover-company-name {
        font-size: 26pt;
        font-weight: 700;
        color: #1e1b4b;
        margin-bottom: 30px;
    }
    .cover-divider {
        width: 60px;
        height: 3px;
        background: #6366f1;
        margin: 0 auto 30px auto;
    }
    .cover-title {
        font-size: 32pt;
        font-weight: 700;
        color: #1e1b4b;
        margin-bottom: 12px;
        line-height: 1.2;
    }
    .cover-url {
        font-size: 14pt;
        color: #6b7280;
        margin-bottom: 6px;
    }
    .cover-period {
        font-size: 14pt;
        color: #6b7280;
        margin-top: 12px;
    }
    .cover-bottom {
        position: absolute;
        bottom: 40mm;
        left: 0;
        right: 0;
        text-align: center;
    }
    .cover-bottom-name {
        font-size: 10pt;
        color: #9ca3af;
    }

    /* --- Intro page --- */
    .intro-title {
        font-size: 28pt;
        font-weight: 700;
        color: #1e1b4b;
        line-height: 1.25;
        margin-bottom: 24px;
    }
    .intro-body {
        font-size: 10pt;
        line-height: 1.8;
        color: #374151;
        margin-bottom: 20px;
    }
    .intro-sections-title {
        font-size: 11pt;
        font-weight: 600;
        color: #6b7280;
        margin-bottom: 10px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .intro-section-item {
        padding: 5px 8px;
        font-size: 10pt;
        color: #374151;
        border-bottom: 1px solid #f3f4f6;
    }
    .intro-section-check {
        color: #6366f1;
        font-weight: 700;
        margin-right: 8px;
    }

    /* --- Overview cards --- */
    .overview-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .overview-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 0;
        vertical-align: top;
    }
    .overview-card-inner {
        padding: 14px;
    }
    .overview-card-title {
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6b7280;
        border-bottom: 1px solid #f3f4f6;
        padding-bottom: 8px;
        margin-bottom: 10px;
    }
    .overview-metric-table {
        width: 100%;
        border-collapse: collapse;
    }
    .overview-metric-table td {
        padding: 3px 0;
        font-size: 9px;
    }
    .overview-metric-label {
        color: #6b7280;
    }
    .overview-metric-value {
        text-align: right;
        font-weight: 600;
        color: #1f2937;
    }

    /* --- Score circles --- */
    .score-circle {
        display: inline-block;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        border: 3px solid;
        text-align: center;
        line-height: 54px;
        font-size: 18px;
        font-weight: 700;
    }
    .score-circle-lg {
        display: inline-block;
        width: 80px;
        height: 80px;
        border-radius: 50%;
        border: 4px solid;
        text-align: center;
        line-height: 72px;
        font-size: 24px;
        font-weight: 700;
    }
    .score-green {
        border-color: #22c55e;
        background: #dcfce7;
        color: #16a34a;
    }
    .score-orange {
        border-color: #f59e0b;
        background: #fef3c7;
        color: #d97706;
    }
    .score-red {
        border-color: #ef4444;
        background: #fee2e2;
        color: #dc2626;
    }
    .score-na {
        border-color: #9ca3af;
        background: #f3f4f6;
        color: #9ca3af;
    }

    /* --- Data tables --- */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }
    .data-table th {
        background: #f8fafc;
        color: #6b7280;
        padding: 7px 8px;
        text-align: left;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border-bottom: 2px solid #e5e7eb;
    }
    .data-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 9px;
        color: #374151;
    }

    /* --- Search Console metric boxes --- */
    .gsc-metrics {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .gsc-metric-box {
        background: #f8fafc;
        border-left: 4px solid #e5e7eb;
        padding: 10px 12px;
        vertical-align: top;
        width: 25%;
    }
    .gsc-metric-box.blue { border-left-color: #3b82f6; }
    .gsc-metric-box.red { border-left-color: #ef4444; }
    .gsc-metric-box.green { border-left-color: #22c55e; }
    .gsc-metric-box.orange { border-left-color: #f59e0b; }
    .gsc-metric-value {
        font-size: 18px;
        font-weight: 700;
        color: #1f2937;
    }
    .gsc-metric-label {
        font-size: 8px;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 2px;
    }

    /* --- Analytics metric cards --- */
    .analytics-metrics {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .analytics-metric-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-top: 4px solid #e5e7eb;
        border-radius: 0 0 6px 6px;
        padding: 12px 14px;
        vertical-align: top;
        width: 25%;
    }
    .analytics-metric-card.purple { border-top-color: #7c3aed; }
    .analytics-metric-card.blue { border-top-color: #3b82f6; }
    .analytics-metric-card.green { border-top-color: #22c55e; }
    .analytics-metric-card.amber { border-top-color: #f59e0b; }
    .analytics-metric-value {
        font-size: 18px;
        font-weight: 700;
        color: #1f2937;
    }
    .analytics-metric-label {
        font-size: 8px;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 2px;
    }

    /* --- Update badges --- */
    .update-badges {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .update-badge {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 14px;
        text-align: center;
        vertical-align: top;
    }
    .update-badge-label {
        font-size: 9px;
        color: #6366f1;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .update-badge-value {
        font-size: 20px;
        font-weight: 700;
        color: #1f2937;
    }

    /* --- Uptime styles --- */
    .uptime-percentage {
        font-size: 48px;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 4px;
    }
    .uptime-percentage.good { color: #16a34a; }
    .uptime-percentage.warning { color: #d97706; }
    .uptime-percentage.bad { color: #dc2626; }

    .uptime-bar {
        width: 100%;
        height: 20px;
        background: #e5e7eb;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 16px;
    }
    .uptime-segment {
        float: left;
        height: 20px;
    }
    .uptime-segment.up { background: #22c55e; }
    .uptime-segment.down { background: #ef4444; }

    .status-up {
        color: #16a34a;
        font-weight: 600;
        font-size: 9px;
    }
    .status-down {
        color: #dc2626;
        font-weight: 600;
        font-size: 9px;
    }

    /* --- Performance indicators --- */
    .perf-indicator {
        font-weight: 700;
        margin-right: 4px;
    }
    .perf-indicator.good { color: #16a34a; }
    .perf-indicator.moderate { color: #d97706; }
    .perf-indicator.poor { color: #dc2626; }

    .perf-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 16px;
        vertical-align: top;
    }
    .perf-card-title {
        font-size: 13pt;
        font-weight: 600;
        color: #1e1b4b;
        margin-bottom: 4px;
    }
    .perf-card-subtitle {
        font-size: 8px;
        color: #9ca3af;
        margin-bottom: 14px;
    }
    .perf-metric-row {
        width: 100%;
        border-collapse: collapse;
    }
    .perf-metric-row td {
        padding: 5px 0;
        border-bottom: 1px solid #f3f4f6;
        font-size: 9px;
    }
    .perf-legend {
        font-size: 8px;
        color: #9ca3af;
        margin-top: 16px;
        text-align: center;
    }

    /* --- Progress bars --- */
    .progress-bar {
        width: 100%;
        height: 8px;
        background: #e5e7eb;
        border-radius: 4px;
        overflow: hidden;
    }
    .progress-fill {
        height: 8px;
        border-radius: 4px;
    }
    .progress-fill.primary { background: #6366f1; }
    .progress-fill.blue { background: #3b82f6; }
    .progress-fill.green { background: #22c55e; }
    .progress-fill.amber { background: #f59e0b; }

    /* --- Bar chart (inline) --- */
    .bar-container {
        background: #e5e7eb;
        border-radius: 3px;
        height: 12px;
        width: 100%;
    }
    .bar-fill {
        height: 12px;
        border-radius: 3px;
        background: #6366f1;
    }

    /* --- Thank-you page --- */
    .thankyou-page {
        text-align: center;
        padding-top: 120px;
    }
    .thankyou-title {
        font-size: 28pt;
        font-weight: 700;
        color: #1e1b4b;
        line-height: 1.3;
        margin-bottom: 20px;
    }
    .thankyou-text {
        font-size: 11pt;
        color: #6b7280;
        line-height: 1.8;
        max-width: 400px;
        margin: 0 auto;
    }
    .thankyou-divider {
        width: 60px;
        height: 3px;
        background: #6366f1;
        margin: 30px auto;
    }
    .thankyou-company {
        font-size: 12pt;
        font-weight: 600;
        color: #1e1b4b;
        margin-top: 10px;
    }
    .thankyou-website {
        font-size: 9pt;
        color: #9ca3af;
        margin-top: 4px;
    }

    /* --- Final branding page --- */
    .final-page {
        text-align: center;
        background: #f8f9fc;
        padding-top: 200px;
        height: 100%;
    }
    .final-logo {
        max-width: 220px;
        max-height: 90px;
        margin-bottom: 20px;
    }
    .final-company {
        font-size: 30pt;
        font-weight: 700;
        color: #1e1b4b;
        margin-bottom: 8px;
    }
    .final-subtitle {
        font-size: 11pt;
        color: #6366f1;
        text-transform: uppercase;
        letter-spacing: 3px;
        font-weight: 600;
    }

    /* --- Badges --- */
    .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .badge-success {
        background: #dcfce7;
        color: #16a34a;
    }
    .badge-warning {
        background: #fef3c7;
        color: #d97706;
    }
    .badge-danger {
        background: #fee2e2;
        color: #dc2626;
    }
    .badge-info {
        background: #ede9fe;
        color: #6366f1;
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

    /* --- Info row --- */
    .info-row {
        width: 100%;
        border-collapse: collapse;
    }
    .info-row td {
        padding: 4px 0;
        border-bottom: 1px solid #f3f4f6;
    }
    .info-label {
        color: #6b7280;
        width: 40%;
        font-size: 9px;
    }
    .info-value {
        font-weight: 600;
        font-size: 9px;
    }

    /* --- Links metric cards --- */
    .links-metrics {
        width: 100%;
        border-collapse: separate;
        border-spacing: 8px;
    }
    .links-metric-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 14px;
        text-align: center;
        vertical-align: top;
        width: 25%;
    }
    .links-metric-value {
        font-size: 20px;
        font-weight: 700;
        color: #1f2937;
    }
    .links-metric-label {
        font-size: 8px;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 2px;
    }

    /* --- Text utilities --- */
    .text-success { color: #16a34a; }
    .text-warning { color: #d97706; }
    .text-danger { color: #dc2626; }
    .text-muted { color: #6b7280; }
    .text-sm { font-size: 9px; }
    .text-xs { font-size: 8px; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }

    /* --- Margin utilities --- */
    .mb-2 { margin-bottom: 6px; }
    .mb-4 { margin-bottom: 12px; }
    .mb-6 { margin-bottom: 16px; }
    .mb-8 { margin-bottom: 20px; }
    .mt-2 { margin-top: 6px; }
    .mt-4 { margin-top: 12px; }
    .mt-8 { margin-top: 20px; }

    /* --- Version arrow (for updates) --- */
    .version-arrow {
        color: #22c55e;
        font-weight: 700;
        margin: 0 2px;
    }

    /* --- Checkmark --- */
    .check-success {
        color: #16a34a;
        font-weight: 700;
    }
</style>
