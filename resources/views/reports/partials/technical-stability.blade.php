@php
    $ut = $data['uptime'] ?? [];
    $sec = $data['security'] ?? null;
    $db = $data['database'] ?? null;
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['technical_stability']['title'] ?? __('report.section_technical_stability', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">{{ $sectionOverrides['technical_stability']['description'] ?? __('report.technical_stability_description', [], $lang) }}</p>

@if(!($ut['available'] ?? false))
    <p style="color: #94a3b8; font-size: 8.5pt;">{{ __('report.uptime_no_monitoring', [], $lang) }}</p>
@else
    {{-- 4 KPI cards (flex row) --}}
    @php
        $uptimePct = $ut['uptime_percentage'] ?? null;
        $uptimeClass = $uptimePct === null ? '' : ($uptimePct >= 95 ? 'good' : ($uptimePct >= 90 ? 'warning' : 'bad'));
        $incCount = $ut['incidents_count'] ?? 0;
        $avgResp = $ut['avg_response_time'] ?? null;
    @endphp
    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-value uptime-percentage {{ $uptimeClass }}">
                {{ $uptimePct !== null ? number_format($uptimePct, 2, $lang === 'ro' ? ',' : '.', '') . '%' : '—' }}
            </div>
            <div class="kpi-label">{{ __('report.uptime_average', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['uptime_trend'] ?? null])</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value {{ $incCount == 0 ? 'value-muted' : '' }}">{{ $incCount }}</div>
            <div class="kpi-label">{{ __('report.uptime_incidents', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['incidents_trend'] ?? null])</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value {{ $avgResp === null ? 'value-muted' : '' }}">{{ $avgResp ? $avgResp . 'ms' : '—' }}</div>
            <div class="kpi-label">{{ __('report.uptime_response_time', [], $lang) }}</div>
            <div class="card-trend">@include('reports.components.trend', ['trend' => $ut['response_time_trend'] ?? null])</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value">{{ $ut['formatted_downtime'] ?? '0 min' }}</div>
            <div class="kpi-label">{{ __('report.uptime_downtime', [], $lang) }}</div>
            <div class="card-trend">&nbsp;</div>
        </div>
    </div>

    {{-- Response Time chart --}}
    @if(!empty($ut['chart_points']['line_points'] ?? ''))
        <hr class="subsection-divider">
        <div class="chart-container">
            <div class="chart-title">{{ __('report.uptime_response_chart', [], $lang) }}</div>
            @include('reports.components.chart-line', [
                'points' => $ut['chart_points'],
                'primaryColor' => $primaryColor ?? '#7C3AED',
                'areaColor' => '#EDE9FE',
                'yLabels' => $ut['chart_y_labels'] ?? [],
                'xLabels' => $ut['chart_x_labels'] ?? [],
                'legendLabel' => __('report.uptime_response_chart', [], $lang),
            ])
        </div>
    @endif

    {{-- Incidents table (capped at 5) --}}
    @if(($sectionOptions['technical_stability']['show_incidents_table'] ?? true) && count($ut['incidents'] ?? []) > 0)
        @php
            $incidents = $ut['incidents'];
            $totalIncidents = count($incidents);
            $displayIncidents = array_slice($incidents, 0, 5);
        @endphp
        <hr class="subsection-divider">
        <h3>{{ __('report.uptime_activity_changes', [], $lang) }}</h3>
        <table class="data-table mb-4">
            <thead>
                <tr>
                    <th>{{ __('report.uptime_status', [], $lang) }}</th>
                    <th>{{ __('report.uptime_from', [], $lang) }}</th>
                    <th>{{ __('report.uptime_to', [], $lang) }}</th>
                    <th>{{ __('report.uptime_duration', [], $lang) }}</th>
                    <th>{{ __('report.uptime_cause', [], $lang) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($displayIncidents as $incident)
                    <tr>
                        <td><span class="status-down">&#8600; {{ __('report.uptime_status_down', [], $lang) }}</span></td>
                        <td>{{ \Carbon\Carbon::parse($incident['started_at'])->format('d.m.Y, H:i') }}</td>
                        <td>{{ $incident['resolved_at'] ? \Carbon\Carbon::parse($incident['resolved_at'])->format('d.m.Y, H:i') : '—' }}</td>
                        <td>{{ $incident['duration'] }}</td>
                        <td>{{ $incident['cause'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($totalIncidents > 5)
            <div class="table-footnote">{{ __('report.showing_of', ['shown' => 5, 'total' => $totalIncidents], $lang) }}</div>
        @endif
    @else
        <div style="padding: 8px 0; color: #10b981; font-weight: 600; font-size: 8.5pt;">
            <span class="status-up">&#8599; {{ __('report.uptime_status_up', [], $lang) }}</span>
            &mdash; {{ __('report.uptime_no_incidents', [], $lang) }}
        </div>
    @endif
@endif

{{-- Security & Database sub-cards side-by-side --}}
@php
    $showSec = $sec && ($sectionOptions['technical_stability']['show_security'] ?? true);
    $showDb = $db && ($sectionOptions['technical_stability']['show_database'] ?? true);
@endphp

@if($showSec && $showDb)
    <hr class="subsection-divider">
    <div class="two-col">
@endif

{{-- Security sub-card --}}
@if($showSec)
    @if(!($showSec && $showDb))
        <hr class="subsection-divider">
    @endif
    <div class="subcard" style="margin-top: 0;">
        <div class="subcard-title">{{ __('report.tech_security_subcard', [], $lang) }}</div>
        @if(($sec['total_issues'] ?? 0) === 0)
            <p style="color: #10b981; font-size: 8.5pt; font-weight: 600;">{{ __('report.security_no_issues', [], $lang) }}</p>
        @else
            <div style="display: flex; gap: 16px; align-items: flex-start;">
                <div style="text-align: center; min-width: 90px;">
                    @include('reports.components.score-circle', ['score' => $sec['score'] ?? null, 'size' => 70])
                    <div class="kpi-label" style="margin-top: 4px;">{{ __('report.security_score', [], $lang) }}</div>
                </div>
                <div style="flex: 1;">
                    <table class="security-summary-table">
                        @if(($sec['critical_count'] ?? 0) > 0)
                            <tr><td class="severity-critical" style="width: 30px;">{{ $sec['critical_count'] }}</td><td>{{ __('report.security_critical', [], $lang) }}</td></tr>
                        @endif
                        @if(($sec['high_count'] ?? 0) > 0)
                            <tr><td class="severity-high" style="width: 30px;">{{ $sec['high_count'] }}</td><td>{{ __('report.security_high', [], $lang) }}</td></tr>
                        @endif
                        @if(($sec['medium_count'] ?? 0) > 0)
                            <tr><td class="severity-medium" style="width: 30px;">{{ $sec['medium_count'] }}</td><td>{{ __('report.security_medium', [], $lang) }}</td></tr>
                        @endif
                        @if(($sec['low_count'] ?? 0) > 0)
                            <tr><td class="severity-low" style="width: 30px;">{{ $sec['low_count'] }}</td><td>{{ __('report.security_low', [], $lang) }}</td></tr>
                        @endif
                    </table>
                </div>
            </div>
        @endif
        {{-- Vulnerabilities table --}}
        @if(!empty($sec['vulnerabilities']))
            <h3 style="margin-top: 12px;">{{ __('report.security_vulnerabilities', [], $lang) }}</h3>
            <table class="data-table" style="margin-bottom: 8px;">
                <thead>
                    <tr>
                        <th>{{ __('report.security_vulnerability', [], $lang) }}</th>
                        <th>{{ __('report.security_severity_col', [], $lang) }}</th>
                        <th>{{ __('report.security_fix_version', [], $lang) }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sec['vulnerabilities'] as $vuln)
                        @php
                            $sevClass = match($vuln['severity'] ?? '') {
                                'critical' => 'badge-danger',
                                'high' => 'badge-warning',
                                'medium' => 'badge-info',
                                default => 'badge-info',
                            };
                        @endphp
                        <tr>
                            <td class="cell-break">{{ $vuln['title'] ?? ($vuln['software_slug'] ?? '—') }}</td>
                            <td><span class="badge {{ $sevClass }}">{{ ucfirst($vuln['severity'] ?? '—') }}</span></td>
                            <td>{{ $vuln['fixed_in_version'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        {{-- Scan date --}}
        @if(!empty($sec['scanned_at']))
            <div class="table-footnote">{{ __('report.security_scanned_at', [], $lang) }}: {{ \Carbon\Carbon::parse($sec['scanned_at'])->format('d/m/Y H:i') }}</div>
        @endif
    </div>
@endif

{{-- Database sub-card --}}
@if($showDb)
    @if(!($showSec && $showDb))
        <hr class="subsection-divider">
    @endif
    <div class="subcard" style="margin-top: 0;">
        <div class="subcard-title">{{ __('report.tech_database_subcard', [], $lang) }}</div>
        <div class="subcard-inner">
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.database_saved', [], $lang) }}</div>
                <div style="font-size: 14pt; font-weight: 700; color: #0f172a; font-feature-settings: 'tnum';">{{ \App\Helpers\FormatHelper::bytes($db['total_saved'] ?? 0) }}</div>
            </div>
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.database_last_cleanup', [], $lang) }}</div>
                <div style="font-size: 10pt; font-weight: 600; color: #0f172a;">
                    {{ $db['last_cleanup_date'] ? \Carbon\Carbon::parse($db['last_cleanup_date'])->format('d/m/Y') : '—' }}
                </div>
            </div>
        </div>
        {{-- Database categories breakdown --}}
        @if(!empty($db['categories']))
            @php
                $activeCats = collect($db['categories'] ?? [])->filter(fn($cat) => ($cat['deleted'] ?? 0) > 0);
            @endphp
            @if($activeCats->count() > 0)
                <h3 style="margin-top: 12px;">{{ __('report.database_cleanup_title', [], $lang) }}</h3>
                <table class="data-table" style="margin-bottom: 0;">
                    <thead>
                        <tr>
                            <th>{{ __('report.database_category', [], $lang) }}</th>
                            <th>{{ __('report.database_deleted', [], $lang) }}</th>
                            <th>{{ __('report.database_space_saved', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activeCats as $cat)
                            @php
                                $catLabel = __('report.database_' . ($cat['key'] ?? 'orphaned'), [], $lang);
                            @endphp
                            <tr>
                                <td>{{ $catLabel }}</td>
                                <td>{{ number_format($cat['deleted']) }}</td>
                                <td>{{ \App\Helpers\FormatHelper::bytes($cat['saved'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>
@endif

@if($showSec && $showDb)
    </div>
@endif

{{-- SSL / Domain / Email quick summary --}}
@php
    $ssl = $data['ssl'] ?? null;
    $domain = $data['domain'] ?? null;
    $email = $data['email'] ?? null;
    $hasInfraInfo = $ssl || $domain || $email;
@endphp

@if($hasInfraInfo)
    <hr class="subsection-divider">
    <h3>{{ __('report.tech_infra_summary', [], $lang) }}</h3>
    <div class="kpi-row">
        @if($ssl)
            @php
                $sslDays = $ssl['days_remaining'] ?? null;
                $sslOk = $ssl['status'] === 'valid';
            @endphp
            <div class="kpi-card">
                <div class="kpi-value" style="font-size: 14pt; color: {{ $sslOk ? '#10b981' : '#ef4444' }};">
                    {{ $sslOk ? __('report.ssl_valid', [], $lang) : __('report.ssl_expired', [], $lang) }}
                </div>
                <div class="kpi-label">{{ __('report.tech_ssl_subcard', [], $lang) }}</div>
                @if($sslDays !== null)
                    <div class="card-sublabel">{{ __('report.days_remaining', ['count' => $sslDays], $lang) }}</div>
                @endif
            </div>
        @endif

        @if($domain)
            @php
                $domainDays = $domain['days_remaining'] ?? null;
            @endphp
            <div class="kpi-card">
                <div class="kpi-value" style="font-size: 14pt;">{{ $domain['registrar'] ?? '—' }}</div>
                <div class="kpi-label">{{ __('report.tech_domain_subcard', [], $lang) }}</div>
                @if($domainDays !== null)
                    <div class="card-sublabel">{{ __('report.days_remaining', ['count' => $domainDays], $lang) }}</div>
                @endif
            </div>
        @endif

        @if($email)
            @php
                $emailScore = $email['score'] ?? null;
                $emailColor = $emailScore >= 80 ? '#10b981' : ($emailScore >= 50 ? '#f59e0b' : '#ef4444');
            @endphp
            <div class="kpi-card">
                <div class="kpi-value" style="font-size: 14pt; color: {{ $emailColor }};">{{ $emailScore !== null ? $emailScore . '/100' : '—' }}</div>
                <div class="kpi-label">{{ __('report.tech_email_subcard', [], $lang) }}</div>
                @php
                    $dmarcEffective = ($email['dmarc_exists'] ?? false) && !in_array(strtolower($email['dmarc_policy'] ?? ''), ['none', '']);
                @endphp
                <div class="card-sublabel">
                    SPF {!! ($email['spf_exists'] ?? false) ? '✓' : '✗' !!}
                    &nbsp; DKIM {!! ($email['dkim_exists'] ?? false) ? '✓' : '✗' !!}
                    &nbsp; DMARC {!! $dmarcEffective ? '✓' : '✗' !!}
                </div>
            </div>
        @endif
    </div>
@endif
