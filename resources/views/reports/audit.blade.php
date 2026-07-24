@php
    /** @var array{client: array{name:string,url:string,date:string}, counter: array{implemented:int,total:int}, sections: array} $report */
    $c = $report['client'];
    $counter = $report['counter'];
    $impactColor = ['mare' => '#dc2626', 'mediu' => '#d97706', 'mic' => '#2563eb'];
@endphp
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Raport audit — {{ $c['name'] }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1f2937; background: #f9fafb; line-height: 1.5; }
        .wrap { max-width: 880px; margin: 0 auto; padding: 32px 20px 64px; }
        header { border-bottom: 2px solid #e5e7eb; padding-bottom: 20px; margin-bottom: 24px; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        .muted { color: #6b7280; font-size: 14px; }
        .counter { display: inline-block; margin-top: 12px; background: #111827; color: #fff; border-radius: 999px; padding: 6px 14px; font-size: 13px; font-weight: 600; font-variant-numeric: tabular-nums; }
        h2 { font-size: 18px; margin: 32px 0 12px; display: flex; align-items: baseline; gap: 8px; }
        h2 .nr { font-family: monospace; font-size: 13px; color: #9ca3af; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; margin-bottom: 14px; }
        .card.done { opacity: .7; }
        .card-head { display: flex; align-items: flex-start; gap: 10px; }
        .card h3 { font-size: 15px; margin: 0; flex: 1; }
        .badge { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; color: #fff; white-space: nowrap; }
        .diag { font-size: 14px; color: #374151; margin: 8px 0 0; }
        .evidence { font-size: 12px; color: #6b7280; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        th { color: #6b7280; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
        td.url { font-family: monospace; font-size: 12px; word-break: break-all; }
        .impl { margin-top: 10px; font-size: 13px; color: #6b7280; }
        .impl input { transform: translateY(1px); margin-right: 6px; }
        footer { margin-top: 40px; text-align: center; color: #9ca3af; font-size: 12px; }
    </style>
</head>
<body data-impl-state="{{ json_encode($toggles ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) }}">
<div class="wrap">
    <header>
        <h1>Raport audit SEO — {{ $c['name'] }}</h1>
        <div class="muted">{{ $c['url'] }} · {{ $c['date'] }} · metodologia v2</div>
        <div class="counter">{{ $counter['implemented'] }} din {{ $counter['total'] }} recomandări implementate</div>
    </header>

    @forelse ($report['sections'] as $section)
        <section>
            <h2><span class="nr">{{ $section['nr'] }}</span> {{ $section['name'] }}</h2>
            @foreach ($section['cards'] as $card)
                <article class="card {{ $card['implemented'] ? 'done' : '' }}">
                    <div class="card-head">
                        <h3>{{ $card['title'] }}</h3>
                        @if ($card['impact'])
                            <span class="badge" style="background: {{ $impactColor[$card['impact']] ?? '#6b7280' }}">{{ ucfirst($card['impact']) }}</span>
                        @endif
                    </div>
                    @if ($card['recommendation'])
                        <p class="diag">{{ $card['recommendation'] }}</p>
                    @endif
                    @if ($card['evidence_text'])
                        <p class="evidence">{{ $card['evidence_text'] }}</p>
                    @endif
                    @if (! empty($card['table']['rows']))
                        <table>
                            <thead>
                                <tr>
                                    @foreach (($card['table']['columns'] ?? ['URL', 'Valoare actuală', 'Recomandare']) as $col)
                                        <th>{{ $col }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($card['table']['rows'] as $row)
                                    <tr>
                                        @foreach ((array) $row as $i => $cell)
                                            <td class="{{ $i === 0 || $i === 'url' ? 'url' : '' }}">{{ is_scalar($cell) ? $cell : '' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    <label class="impl">
                        <input type="checkbox" data-rec="{{ $card['id'] }}" {{ $card['implemented'] ? 'checked' : '' }} disabled>
                        Implementat
                    </label>
                </article>
            @endforeach
        </section>
    @empty
        <p class="muted">Nicio recomandare validată în acest raport.</p>
    @endforelse

    <footer>Generat de SimpleAd Manager · {{ $c['date'] }}</footer>
</div>
</body>
</html>
