<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f9fafb; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 24px; }
        .status-badge { display: inline-block; padding: 6px 16px; border-radius: 9999px; font-weight: 600; font-size: 14px; background-color: #fffbeb; color: #d97706; }
        td.k { padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px; width: 160px; }
        td.v { padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1 style="margin: 0 0 8px; font-size: 20px;">Storage Limit Reached</h1>
                <span class="status-badge">QUOTA</span>
            </div>

            <table width="100%" cellpadding="0" cellspacing="0">
                <tr><td class="k">Destination</td><td class="v">{{ $destination->name }}</td></tr>
                <tr><td class="k">Type</td><td class="v">{{ ucfirst($destination->type) }}</td></tr>
                <tr><td class="k">Used</td><td class="v">{{ \App\Helpers\FormatHelper::bytes((int) $destination->used_bytes) }}</td></tr>
                @if($destination->quota_bytes)
                    <tr><td class="k">Quota</td><td class="v">{{ \App\Helpers\FormatHelper::bytes((int) $destination->quota_bytes) }}</td></tr>
                    <tr><td class="k">Usage</td><td class="v">{{ $destination->usage_percent }}%</td></tr>
                @endif
            </table>

            <p style="margin-top: 20px; font-size: 14px; color: #6b7280;">
                New backups to this destination may be blocked until space is freed or the quota is raised.
            </p>

            <p class="footer">SimpleAd Manager — Backup engine V2</p>
        </div>
    </div>
</body>
</html>
