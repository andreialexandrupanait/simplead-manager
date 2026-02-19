<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        html, body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif;
        }

        .header-bar {
            width: 100%;
            height: 44px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18mm;
            border-bottom: 2px solid {{ $primaryColor ?? '#7C3AED' }};
        }

        .header-logo {
            height: 16px;
            width: auto;
            display: block;
        }

        .header-fallback-text {
            font-size: 9pt;
            font-weight: 700;
            color: #0f172a;
            line-height: 1;
        }

        .header-center {
            text-align: center;
            flex: 1;
        }

        .header-title-line {
            font-size: 7.5pt;
            font-weight: 600;
            color: #334155;
            line-height: 1.3;
        }

        .header-right {
            text-align: right;
        }

        .header-date-line {
            font-size: 7pt;
            color: #94a3b8;
            line-height: 1.3;
        }
    </style>
</head>
<body>
    <div class="header-bar">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" class="header-logo" alt="" style="filter: brightness(0);">
        @else
            <span class="header-fallback-text">{{ $companyName }}</span>
        @endif
        <div class="header-center">
            <div class="header-title-line">{{ $reportTitle }}  &middot;  {{ $clientName }}</div>
        </div>
        <div class="header-right">
            <div class="header-date-line">{{ $dateRange }}</div>
        </div>
    </div>
</body>
</html>
