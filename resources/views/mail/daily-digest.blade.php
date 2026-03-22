<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f9fafb; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { font-size: 20px; margin: 0 0 4px; color: #1f2937; }
        .header p { font-size: 14px; color: #6b7280; margin: 0; }
        .stats-grid { display: table; width: 100%; border-collapse: collapse; margin: 24px 0; }
        .stat-row { display: table-row; }
        .stat-label, .stat-value { display: table-cell; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .stat-label { color: #6b7280; }
        .stat-value { text-align: right; font-weight: 600; color: #1f2937; }
        .stat-value.success { color: #16a34a; }
        .stat-value.danger { color: #dc2626; }
        .stat-value.warning { color: #d97706; }
        .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #8D5CF5; margin: 24px 0 8px; padding-top: 16px; border-top: 2px solid #f3f4f6; }
        .action-btn { display: inline-block; padding: 12px 24px; background-color: #8D5CF5; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; margin-top: 16px; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <h1>Daily Health Digest</h1>
            <p>{{ $digest['date'] }}</p>
        </div>

        <div class="section-title">Sites Overview</div>
        <div class="stats-grid">
            <div class="stat-row">
                <div class="stat-label">Total Sites</div>
                <div class="stat-value">{{ $digest['total_sites'] }}</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Sites Up</div>
                <div class="stat-value success">{{ $digest['sites_up'] }}</div>
            </div>
            @if($digest['sites_down'] > 0)
            <div class="stat-row">
                <div class="stat-label">Sites Down</div>
                <div class="stat-value danger">{{ $digest['sites_down'] }}</div>
            </div>
            @endif
        </div>

        <div class="section-title">Incidents (24h)</div>
        <div class="stats-grid">
            <div class="stat-row">
                <div class="stat-label">New Incidents</div>
                <div class="stat-value {{ $digest['incidents_24h'] > 0 ? 'danger' : '' }}">{{ $digest['incidents_24h'] }}</div>
            </div>
            <div class="stat-row">
                <div class="stat-label">Resolved</div>
                <div class="stat-value success">{{ $digest['resolved_24h'] }}</div>
            </div>
        </div>

        <div class="section-title">Backups (24h)</div>
        <div class="stats-grid">
            <div class="stat-row">
                <div class="stat-label">Completed</div>
                <div class="stat-value success">{{ $digest['backups_24h'] }}</div>
            </div>
            @if($digest['backups_failed_24h'] > 0)
            <div class="stat-row">
                <div class="stat-label">Failed</div>
                <div class="stat-value danger">{{ $digest['backups_failed_24h'] }}</div>
            </div>
            @endif
        </div>

        <div class="section-title">Attention Needed</div>
        <div class="stats-grid">
            @if($digest['updates_available'] > 0)
            <div class="stat-row">
                <div class="stat-label">Sites with Updates</div>
                <div class="stat-value warning">{{ $digest['updates_available'] }}</div>
            </div>
            @endif
            @if($digest['updates_available'] === 0)
            <div class="stat-row">
                <div class="stat-label" style="color: #16a34a;">All clear — no attention needed</div>
                <div class="stat-value"></div>
            </div>
            @endif
        </div>

        <div style="text-align: center; margin-top: 24px;">
            <a href="{{ config('app.url') }}" class="action-btn">Open Dashboard</a>
        </div>
    </div>

    <div class="footer">
        {{ config('app.name', 'SimpleAd Manager') }}
    </div>
</div>
</body>
</html>
