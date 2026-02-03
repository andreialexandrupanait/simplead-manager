@php $o = $data['overview']; @endphp

<h2>Privire de ansamblu globală</h2>

{{-- Row 1: Updates, Uptime, Backup --}}
<table class="overview-grid">
    <tr>
        <td class="overview-card" style="width: 33%;">
            <div class="overview-card-inner">
                <div class="overview-card-title">Actualizări</div>
                <table class="overview-metric-table">
                    <tr>
                        <td class="overview-metric-label">Plugin-uri</td>
                        <td class="overview-metric-value">{{ $o['updates_count'] ?? 0 }}</td>
                    </tr>
                    <tr>
                        <td class="overview-metric-label">WordPress</td>
                        <td class="overview-metric-value">{{ $o['wp_version'] ?? 'N/A' }}</td>
                    </tr>
                </table>
            </div>
        </td>
        <td class="overview-card" style="width: 33%;">
            <div class="overview-card-inner">
                <div class="overview-card-title">Uptime</div>
                <table class="overview-metric-table">
                    <tr>
                        <td class="overview-metric-label">Stare</td>
                        <td class="overview-metric-value">
                            @if(($o['uptime_percentage'] ?? null) !== null)
                                <span class="{{ $o['uptime_percentage'] >= 99 ? 'text-success' : ($o['uptime_percentage'] >= 95 ? 'text-warning' : 'text-danger') }}">
                                    {{ $o['uptime_percentage'] >= 99 ? 'Activ' : 'Instabil' }}
                                </span>
                            @else
                                N/A
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="overview-metric-label">Disponibilitate</td>
                        <td class="overview-metric-value">{{ $o['uptime_percentage'] !== null ? number_format($o['uptime_percentage'], 2) . '%' : 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td class="overview-metric-label">Incidente</td>
                        <td class="overview-metric-value">{{ $o['incidents_count'] ?? 0 }}</td>
                    </tr>
                </table>
            </div>
        </td>
        <td class="overview-card" style="width: 33%;">
            <div class="overview-card-inner">
                <div class="overview-card-title">Backup</div>
                <table class="overview-metric-table">
                    <tr>
                        <td class="overview-metric-label">Stare</td>
                        <td class="overview-metric-value">
                            <span class="badge badge-success">Activ</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="overview-metric-label">Efectuate</td>
                        <td class="overview-metric-value">{{ $o['backups_count'] ?? 0 }}</td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>

{{-- Row 2: Traffic + Performance --}}
<table class="overview-grid mt-4">
    <tr>
        <td class="overview-card" colspan="2" style="width: 66%;">
            <div class="overview-card-inner">
                <div class="overview-card-title">Trafic</div>
                <table class="overview-metric-table">
                    <tr>
                        <td class="overview-metric-label">Vizualizări pagini</td>
                        <td class="overview-metric-value">{{ isset($o['total_sessions']) ? number_format($o['total_sessions']) : 'N/A' }}</td>
                        <td class="overview-metric-label" style="padding-left: 16px;">Utilizatori</td>
                        <td class="overview-metric-value">{{ isset($o['total_users']) ? number_format($o['total_users']) : 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td class="overview-metric-label">Clicuri căutare</td>
                        <td class="overview-metric-value">{{ isset($o['total_clicks']) ? number_format($o['total_clicks']) : 'N/A' }}</td>
                        <td class="overview-metric-label" style="padding-left: 16px;">Impresii</td>
                        <td class="overview-metric-value">{{ isset($o['total_impressions']) ? number_format($o['total_impressions']) : 'N/A' }}</td>
                    </tr>
                </table>
            </div>
        </td>
        <td class="overview-card" style="width: 33%;">
            <div class="overview-card-inner">
                <div class="overview-card-title">Performanță</div>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="text-align: center; padding: 4px;">
                            @php
                                $mScore = $o['mobile_score'] ?? null;
                                $mClass = $mScore === null ? 'score-na' : ($mScore >= 90 ? 'score-green' : ($mScore >= 50 ? 'score-orange' : 'score-red'));
                            @endphp
                            <div class="score-circle {{ $mClass }}">{{ $mScore ?? '—' }}</div>
                            <div style="font-size: 8px; color: #6b7280; margin-top: 4px;">Mobil</div>
                        </td>
                        <td style="text-align: center; padding: 4px;">
                            @php
                                $dScore = $o['desktop_score'] ?? null;
                                $dClass = $dScore === null ? 'score-na' : ($dScore >= 90 ? 'score-green' : ($dScore >= 50 ? 'score-orange' : 'score-red'));
                            @endphp
                            <div class="score-circle {{ $dClass }}">{{ $dScore ?? '—' }}</div>
                            <div style="font-size: 8px; color: #6b7280; margin-top: 4px;">Desktop</div>
                        </td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>

{{-- Row 3: Search Console full-width --}}
@if(isset($o['total_clicks']) || isset($o['total_impressions']))
<table class="overview-grid mt-4">
    <tr>
        <td class="overview-card" colspan="3">
            <div class="overview-card-inner">
                <div class="overview-card-title">Search Console</div>
                <table class="gsc-metrics" style="margin: 0 -4px;">
                    <tr>
                        <td class="gsc-metric-box blue">
                            <div class="gsc-metric-value">{{ isset($o['total_clicks']) ? number_format($o['total_clicks']) : 'N/A' }}</div>
                            <div class="gsc-metric-label">Total clicuri</div>
                        </td>
                        <td class="gsc-metric-box red">
                            <div class="gsc-metric-value">{{ isset($o['total_impressions']) ? number_format($o['total_impressions']) : 'N/A' }}</div>
                            <div class="gsc-metric-label">Impresii</div>
                        </td>
                        <td class="gsc-metric-box green">
                            <div class="gsc-metric-value">{{ $o['broken_links'] ?? 0 }}</div>
                            <div class="gsc-metric-label">Link-uri rupte</div>
                        </td>
                        <td class="gsc-metric-box orange">
                            <div class="gsc-metric-value">{{ $o['total_links'] ?? 0 }}</div>
                            <div class="gsc-metric-label">Total link-uri</div>
                        </td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>
@endif
