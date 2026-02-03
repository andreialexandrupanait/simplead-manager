<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f9fafb; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 24px; }
        .status-badge { display: inline-block; padding: 6px 16px; border-radius: 9999px; font-weight: 600; font-size: 14px; background-color: #fef2f2; color: #dc2626; }
        .violation-row { padding: 12px 0; border-bottom: 1px solid #f3f4f6; }
        .violation-key { color: #6b7280; font-size: 14px; }
        .violation-value { color: #dc2626; font-weight: 600; font-size: 14px; }
        .violation-budget { color: #6b7280; font-size: 13px; }
        .action-btn { display: inline-block; padding: 12px 24px; background-color: #8D5CF5; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; margin-top: 16px; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1 style="margin: 0 0 8px; font-size: 20px;">Performance Budget Exceeded</h1>
                <span class="status-badge">{{ count($violations) }} VIOLATION{{ count($violations) > 1 ? 'S' : '' }}</span>
            </div>

            <div class="details">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px; width: 140px;">Site</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $site->name }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">URL</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $site->url }}</td>
                    </tr>
                </table>

                <div style="margin-top: 20px;">
                    <h3 style="font-size: 15px; color: #1f2937; margin-bottom: 12px;">Budget Violations</h3>
                    @foreach($violations as $violation)
                        <div class="violation-row">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="violation-key">{{ $violation['key'] }}</span>
                                <span>
                                    <span class="violation-value">{{ $violation['actual'] }}</span>
                                    <span class="violation-budget"> / budget: {{ $violation['budget'] }}</span>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div style="text-align: center;">
                <a href="{{ url('/sites/' . $site->id . '/performance') }}" class="action-btn">View Performance Details</a>
            </div>
        </div>

        <div class="footer">
            <p>Sent by SimpleAd Manager</p>
        </div>
    </div>
</body>
</html>
