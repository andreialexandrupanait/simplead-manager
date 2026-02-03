@php $a = $data['analytics']; @endphp

<h2>Analitică</h2>

{{-- Color-bar metric cards --}}
<table class="analytics-metrics mb-6">
    <tr>
        <td class="analytics-metric-card purple">
            <div class="analytics-metric-value">{{ isset($a['total_pageviews']) ? number_format($a['total_pageviews']) : 'N/A' }}</div>
            <div class="analytics-metric-label">Pagini vizualizate</div>
        </td>
        <td class="analytics-metric-card blue">
            <div class="analytics-metric-value">{{ isset($a['total_users']) ? number_format($a['total_users']) : 'N/A' }}</div>
            <div class="analytics-metric-label">Utilizatori</div>
        </td>
        <td class="analytics-metric-card green">
            <div class="analytics-metric-value">{{ isset($a['bounce_rate']) ? number_format($a['bounce_rate'], 1) . '%' : 'N/A' }}</div>
            <div class="analytics-metric-label">Rata respingere</div>
        </td>
        <td class="analytics-metric-card amber">
            <div class="analytics-metric-value">{{ isset($a['avg_session_duration']) ? gmdate('i:s', (int) $a['avg_session_duration']) : 'N/A' }}</div>
            <div class="analytics-metric-label">Durata sesiune</div>
        </td>
    </tr>
</table>

{{-- Users over time bar chart --}}
@if(isset($a['daily_users']) && is_array($a['daily_users']) && count($a['daily_users']) > 0)
    <h3>Utilizatori în timp</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th style="width: 90px;">Dată</th>
                <th style="width: 60px;">Utilizatori</th>
                <th>Grafic</th>
            </tr>
        </thead>
        <tbody>
            @php
                $maxUsers = max(array_column($a['daily_users'], 'users'));
                $maxUsers = max($maxUsers, 1);
            @endphp
            @foreach(array_slice($a['daily_users'], -14) as $day)
                <tr>
                    <td>{{ isset($day['date']) ? \Carbon\Carbon::parse($day['date'])->format('d/m') : '—' }}</td>
                    <td>{{ number_format($day['users'] ?? 0) }}</td>
                    <td>
                        <div class="bar-container">
                            <div class="bar-fill" style="width: {{ min(100, (($day['users'] ?? 0) / $maxUsers) * 100) }}%;"></div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- Two-column: Users breakdown + Device distribution --}}
<table class="two-col">
    <tr>
        <td>
            <h3>Utilizatori</h3>
            @php
                $newUsers = $a['new_users'] ?? 0;
                $returningUsers = $a['returning_users'] ?? 0;
                $totalUserSplit = max($newUsers + $returningUsers, 1);
            @endphp
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="font-size: 9px; padding: 4px 0;">Utilizatori noi</td>
                    <td style="font-size: 9px; font-weight: 600; text-align: right; padding: 4px 0;">{{ number_format($newUsers) }}</td>
                </tr>
                <tr>
                    <td colspan="2" style="padding: 2px 0 8px 0;">
                        <div class="progress-bar">
                            <div class="progress-fill primary" style="width: {{ min(100, ($newUsers / $totalUserSplit) * 100) }}%;"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="font-size: 9px; padding: 4px 0;">Utilizatori revenind</td>
                    <td style="font-size: 9px; font-weight: 600; text-align: right; padding: 4px 0;">{{ number_format($returningUsers) }}</td>
                </tr>
                <tr>
                    <td colspan="2" style="padding: 2px 0 8px 0;">
                        <div class="progress-bar">
                            <div class="progress-fill blue" style="width: {{ min(100, ($returningUsers / $totalUserSplit) * 100) }}%;"></div>
                        </div>
                    </td>
                </tr>
            </table>
        </td>
        <td>
            @if(isset($a['devices']) && is_array($a['devices']) && count($a['devices']) > 0)
                <h3>Distribuție dispozitiv</h3>
                @php
                    $totalDeviceUsers = array_sum(array_column($a['devices'], 'users'));
                    $totalDeviceUsers = max($totalDeviceUsers, 1);
                    $deviceColors = ['primary', 'blue', 'green', 'amber'];
                @endphp
                <table style="width: 100%; border-collapse: collapse;">
                    @foreach($a['devices'] as $idx => $device)
                        <tr>
                            <td style="font-size: 9px; padding: 4px 0;">{{ ucfirst($device['device'] ?? $device['name'] ?? '—') }}</td>
                            <td style="font-size: 9px; font-weight: 600; text-align: right; padding: 4px 0;">
                                {{ number_format((($device['users'] ?? 0) / $totalDeviceUsers) * 100, 1) }}%
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding: 2px 0 8px 0;">
                                <div class="progress-bar">
                                    <div class="progress-fill {{ $deviceColors[$idx] ?? 'primary' }}" style="width: {{ min(100, (($device['users'] ?? 0) / $totalDeviceUsers) * 100) }}%;"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </td>
    </tr>
</table>
