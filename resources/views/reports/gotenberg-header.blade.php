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
            height: 52px;
            background: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 14mm;
        }

        .header-logo {
            height: 18px;
            width: auto;
            display: block;
        }

        .header-fallback-text {
            font-size: 10pt;
            font-weight: 700;
            color: #ffffff;
            line-height: 1;
        }

        .header-right {
            text-align: right;
        }

        .header-title-line {
            font-size: 8pt;
            color: rgba(255, 255, 255, 0.75);
            line-height: 1.3;
        }

        .header-date-line {
            font-size: 6.5pt;
            color: rgba(255, 255, 255, 0.55);
            line-height: 1.3;
            margin-top: 2px;
        }

        .header-generated-line {
            font-size: 6.5pt;
            color: rgba(255, 255, 255, 0.45);
            line-height: 1.3;
            margin-top: 1px;
        }
    </style>
</head>
<body>
    <div class="header-bar">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" class="header-logo" alt="">
        @else
            <span class="header-fallback-text">{{ $companyName }}</span>
        @endif
        <div class="header-right">
            <div class="header-title-line">{{ $reportTitle }}  |  {{ $clientName }}</div>
            <div class="header-date-line">{{ $dateRange }}</div>
            <div class="header-generated-line">{{ $generatedAt }}</div>
        </div>
    </div>
</body>
</html>
