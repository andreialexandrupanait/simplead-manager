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
        .action-btn { display: inline-block; padding: 12px 24px; background-color: #7B68EE; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; margin-top: 16px; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #9ca3af; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-weight: 600; font-size: 12px; background-color: #ede9fe; color: #7c3aed; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1 style="margin: 0 0 8px; font-size: 20px;">Raport de mentenanță</h1>
                <span class="badge">{{ $site->name }}</span>
            </div>

            @if($schedule?->email_body)
                <div style="margin-bottom: 20px; font-size: 14px; color: #374151;">
                    {!! nl2br(e($schedule->email_body)) !!}
                </div>
            @else
                <p style="font-size: 14px; color: #374151;">
                    Raportul de mentenanță pentru <strong>{{ $site->name }}</strong> este gata.
                </p>
            @endif

            <div class="details">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px; width: 120px;">Site</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $site->name }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Perioadă</td>
                        <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">
                            {{ $report->period_start->format('d.m.Y') }} — {{ $report->period_end->format('d.m.Y') }}
                        </td>
                    </tr>
                    @if($report->reportTemplate)
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Șablon</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $report->reportTemplate->name }}</td>
                        </tr>
                    @endif
                    @if($report->file_size)
                        <tr>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 14px;">Dimensiune</td>
                            <td style="padding: 12px 0; border-bottom: 1px solid #f3f4f6; color: #1f2937; font-size: 14px; font-weight: 500;">{{ $report->file_size_formatted }}</td>
                        </tr>
                    @endif
                </table>
            </div>

            <div style="text-align: center;">
                @if($pdfAttached)
                    <p style="font-size: 13px; color: #6b7280; margin-bottom: 12px;">Raportul PDF este atașat la acest email.</p>
                @else
                    <p style="font-size: 13px; color: #dc2626; margin-bottom: 12px;">Raportul PDF nu a putut fi atașat. Folosiți linkul de mai jos pentru a-l descărca sau vizualiza online.</p>
                @endif
                <a href="{{ $downloadUrl }}" class="action-btn">Descarcă raportul</a>
                @if($viewUrl)
                    <div style="margin-top: 12px;">
                        <a href="{{ $viewUrl }}" style="font-size: 13px; color: #7B68EE; text-decoration: underline;">sau vezi online</a>
                    </div>
                @endif
            </div>
        </div>

        <div class="footer">
            <p>Trimis de SimpleAd Manager</p>
        </div>
    </div>
</body>
</html>
