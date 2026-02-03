@php $sc = $data['search_console']; $overview = $sc['overview'] ?? []; @endphp

<h2>Google Console de Căutare</h2>

{{-- Colored left-border metric boxes --}}
<table class="gsc-metrics mb-6">
    <tr>
        <td class="gsc-metric-box blue">
            <div class="gsc-metric-value">{{ isset($overview['total_clicks']) ? number_format($overview['total_clicks']) : 'N/A' }}</div>
            <div class="gsc-metric-label">Total clicuri</div>
        </td>
        <td class="gsc-metric-box red">
            <div class="gsc-metric-value">{{ isset($overview['total_impressions']) ? number_format($overview['total_impressions']) : 'N/A' }}</div>
            <div class="gsc-metric-label">Impresii</div>
        </td>
        <td class="gsc-metric-box green">
            <div class="gsc-metric-value">{{ isset($overview['avg_ctr']) ? number_format($overview['avg_ctr'] * 100, 1) . '%' : 'N/A' }}</div>
            <div class="gsc-metric-label">CTR mediu</div>
        </td>
        <td class="gsc-metric-box orange">
            <div class="gsc-metric-value">{{ isset($overview['avg_position']) ? number_format($overview['avg_position'], 1) : 'N/A' }}</div>
            <div class="gsc-metric-label">Poziție medie</div>
        </td>
    </tr>
</table>

{{-- Performance over time bar chart --}}
@if(isset($overview['daily_data']) && is_array($overview['daily_data']) && count($overview['daily_data']) > 0)
    <h3>Performanță în timp</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th style="width: 80px;">Dată</th>
                <th style="width: 60px;">Clicuri</th>
                <th style="width: 80px;">Impresii</th>
                <th>Grafic clicuri</th>
            </tr>
        </thead>
        <tbody>
            @php
                $maxClicks = max(1, max(array_column($overview['daily_data'], 'clicks')));
            @endphp
            @foreach(array_slice($overview['daily_data'], -14) as $day)
                <tr>
                    <td>{{ isset($day['date']) ? \Carbon\Carbon::parse($day['date'])->format('d/m') : '—' }}</td>
                    <td>{{ number_format($day['clicks'] ?? 0) }}</td>
                    <td>{{ number_format($day['impressions'] ?? 0) }}</td>
                    <td>
                        <div class="bar-container">
                            <div class="bar-fill" style="width: {{ min(100, (($day['clicks'] ?? 0) / $maxClicks) * 100) }}%;"></div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Top 10 queries --}}
@if(isset($sc['queries']) && is_array($sc['queries']) && count($sc['queries']) > 0)
    <h3>Top 10 căutări</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Căutare</th>
                <th>Clicuri</th>
                <th>Impresii</th>
                <th>CTR</th>
                <th>Poziție</th>
            </tr>
        </thead>
        <tbody>
            @foreach(array_slice($sc['queries'], 0, 10) as $query)
                <tr>
                    <td>{{ $query['query'] ?? $query['keys'][0] ?? '—' }}</td>
                    <td>{{ number_format($query['clicks'] ?? 0) }}</td>
                    <td>{{ number_format($query['impressions'] ?? 0) }}</td>
                    <td>{{ isset($query['ctr']) ? number_format($query['ctr'] * 100, 1) . '%' : '—' }}</td>
                    <td>{{ isset($query['position']) ? number_format($query['position'], 1) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
