<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f9fafb; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 24px; }
        .status-badge { display: inline-block; padding: 6px 16px; border-radius: 9999px; font-weight: 600; font-size: 14px; }
        .status-down { background-color: #fef2f2; color: #dc2626; }
        .status-recovery { background-color: #f0fdf4; color: #16a34a; }
        .details { margin: 24px 0; }
        .detail-row { display: flex; padding: 12px 0; border-bottom: 1px solid #f3f4f6; }
        .detail-label { color: #6b7280; font-size: 14px; width: 120px; flex-shrink: 0; }
        .detail-value { color: #1f2937; font-size: 14px; font-weight: 500; }
        .action-btn { display: inline-block; padding: 12px 24px; background-color: #7B68EE; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; margin-top: 16px; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1 style="margin: 0 0 8px; font-size: 20px;">
                    {{ $type === 'down' ? 'Site Down' : 'Site Recovered' }}
                </h1>
                <span class="status-badge {{ $type === 'down' ? 'status-down' : 'status-recovery' }}">
                    {{ $type === 'down' ? 'DOWN' : 'RECOVERED' }}
                </span>
            </div>

            <div class="details">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px; width: 120px;">Site</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $site->name }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">URL</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $monitor->url }}</td>
                    </tr>
                    @if($type === 'down')
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Cause</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $incident->cause ?? 'Unknown' }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Down Since</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $incident->started_at->format('M d, Y H:i:s') }} UTC</td>
                        </tr>
                    @else
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Duration</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $incident->duration }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Resolved At</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $incident->resolved_at->format('M d, Y H:i:s') }} UTC</td>
                        </tr>
                    @endif
                </table>
            </div>

            <div style="text-align: center;">
                <a href="{{ url('/sites/' . $site->id . '/uptime') }}" class="action-btn">View Uptime Details</a>
            </div>
        </div>

        <div class="footer">
            <p>Sent by SimpleAd Manager</p>
        </div>
    </div>
</body>
</html>
