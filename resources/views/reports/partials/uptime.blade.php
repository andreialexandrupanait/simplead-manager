@php $ut = $data['uptime']; @endphp

<h2>Monitorizare timp de funcționare</h2>

<h3>Timp de funcționare mediu</h3>

{{-- Big percentage --}}
@php
    $uptimePct = $ut['uptime_percentage'] ?? null;
    $uptimeClass = $uptimePct === null ? '' : ($uptimePct >= 99 ? 'good' : ($uptimePct >= 95 ? 'warning' : 'bad'));
@endphp

<div class="uptime-percentage {{ $uptimeClass }}" style="margin-bottom: 8px;">
    {{ $uptimePct !== null ? number_format($uptimePct, 2) . '%' : 'N/A' }}
</div>

<div class="text-sm text-muted mb-4">
    Timp mediu de răspuns: {{ $ut['avg_response_time'] ? $ut['avg_response_time'] . 'ms' : 'N/A' }}
    &bull; Incidente: {{ $ut['incidents_count'] }}
    &bull; Timp nefuncțional: {{ $ut['total_downtime_minutes'] }}m
</div>

{{-- Uptime bar --}}
@if($uptimePct !== null)
    <div class="uptime-bar">
        <div class="uptime-segment up" style="width: {{ min(100, $uptimePct) }}%;"></div>
        @if($uptimePct < 100)
            <div class="uptime-segment down" style="width: {{ 100 - $uptimePct }}%;"></div>
        @endif
    </div>
@endif

{{-- Activity changes table --}}
@if(count($ut['incidents'] ?? []) > 0)
    <h3 class="mt-4">Modificări activitate</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Stare</th>
                <th>De la</th>
                <th>Până la</th>
                <th>Durată</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ut['incidents'] as $incident)
                <tr>
                    <td>
                        <span class="status-down">&#8600; NEFUNCȚIONAL</span>
                    </td>
                    <td>{{ $incident['started_at'] }}</td>
                    <td>{{ $incident['resolved_at'] ?? '—' }}</td>
                    <td>{{ $incident['duration'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <div style="text-align: center; padding: 20px; color: #16a34a; font-weight: 600;">
        <span class="status-up">&#8599; FUNCȚIONEAZĂ</span>
        &mdash; Niciun incident în această perioadă.
    </div>
@endif

@if(count($ut['response_time_chart'] ?? []) > 0)
    <h3 class="mt-8">Timp de răspuns mediu pe zi</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th style="width: 100px;">Dată</th>
                <th style="width: 80px;">Mediu (ms)</th>
                <th>Grafic</th>
            </tr>
        </thead>
        <tbody>
            @php
                $maxResponse = max(array_column($ut['response_time_chart'], 'avg_response_time'));
                $maxResponse = max($maxResponse, 1);
            @endphp
            @foreach(array_slice($ut['response_time_chart'], -14) as $row)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($row['date'])->format('d/m') }}</td>
                    <td>{{ round($row['avg_response_time']) }}</td>
                    <td>
                        <div class="bar-container">
                            <div class="bar-fill" style="width: {{ min(100, ($row['avg_response_time'] / $maxResponse) * 100) }}%;"></div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
