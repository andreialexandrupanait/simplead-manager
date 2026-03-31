<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f9fafb; }
        .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 24px; }
        .details { margin: 24px 0; }
        .action-btn { display: inline-block; padding: 12px 24px; background-color: #8D5CF5; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; margin-top: 16px; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #9ca3af; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-weight: 600; font-size: 12px; background-color: #ede9fe; color: #7c3aed; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1 style="margin: 0 0 8px; font-size: 20px;">Maintenance Report</h1>
                <span class="badge">{{ $site->name }}</span>
            </div>

            @if($schedule?->email_body)
                <div style="margin-bottom: 20px; font-size: 14px; color: #374151;">
                    {!! nl2br(e($schedule->email_body)) !!}
                </div>
            @else
                <p style="font-size: 14px; color: #374151;">
                    Your maintenance report for <strong>{{ $site->name }}</strong> is ready.
                </p>
            @endif

            <div class="details">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px; width: 120px;">Site</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $site->name }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Period</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">
                            {{ $report->period_start->format('M d, Y') }} — {{ $report->period_end->format('M d, Y') }}
                        </td>
                    </tr>
                    @if($report->reportTemplate)
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Template</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $report->reportTemplate->name }}</td>
                        </tr>
                    @endif
                    @if($report->file_size)
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">File Size</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $report->file_size_formatted }}</td>
                        </tr>
                    @endif
                </table>
            </div>

            <div style="text-align: center;">
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 12px;">The PDF report is attached to this email.</p>
                @if($viewUrl)
                    <a href="{{ $viewUrl }}" class="action-btn">View Report Online</a>
                    <div style="margin-top: 12px;">
                        <a href="{{ $downloadUrl }}" style="font-size: 13px; color: #8D5CF5; text-decoration: underline;">Download PDF</a>
                        <span style="font-size: 11px; color: #9ca3af; margin-left: 4px;">(expires in 7 days)</span>
                    </div>
                @else
                    <a href="{{ $downloadUrl }}" class="action-btn">Download Report</a>
                @endif
            </div>
        </div>

        <div class="footer">
            <p>Sent by SimpleAd Manager</p>
        </div>
    </div>
</body>
</html>
