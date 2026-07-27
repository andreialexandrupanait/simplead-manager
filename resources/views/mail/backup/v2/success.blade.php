<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f9fafb; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 24px; }
        .status-badge { display: inline-block; padding: 6px 16px; border-radius: 9999px; font-weight: 600; font-size: 14px; background-color: #f0fdf4; color: #16a34a; }
        td.k { padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px; width: 160px; }
        td.v { padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1 style="margin: 0 0 8px; font-size: 20px;">Backup Completed</h1>
                <span class="status-badge">COMPLETED</span>
            </div>

            <table width="100%" cellpadding="0" cellspacing="0">
                <tr><td class="k">Site</td><td class="v">{{ $site->name }}</td></tr>
                <tr><td class="k">URL</td><td class="v">{{ $site->url }}</td></tr>
                <tr><td class="k">Backup type</td><td class="v">{{ ucfirst($session->type) }}</td></tr>
                <tr><td class="k">Resource profile</td><td class="v">{{ str_replace('_', ' ', $session->resource_profile) }}</td></tr>
                <tr><td class="k">Session</td><td class="v">#{{ $session->id }}</td></tr>
                @if($session->completed_at)
                    <tr><td class="k">Completed at</td><td class="v">{{ $session->completed_at->toDayDateTimeString() }}</td></tr>
                @endif
                @if($session->verified_at)
                    <tr><td class="k">Verified at</td><td class="v">{{ $session->verified_at->toDayDateTimeString() }}</td></tr>
                @endif
            </table>

            <p class="footer">SimpleAd Manager — Backup engine V2</p>
        </div>
    </div>
</body>
</html>
