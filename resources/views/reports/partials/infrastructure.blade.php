@php
    $ssl = $data['ssl'] ?? null;
    $domain = $data['domain'] ?? null;
    $email = $data['email'] ?? null;
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['infrastructure']['title'] ?? __('report.section_infrastructure', [], $lang),
])

{{-- SSL Certificate sub-card --}}
@if($ssl && ($sectionOptions['infrastructure']['show_ssl'] ?? true))
    <div class="subcard mt-4">
        <div class="subcard-title">{{ __('report.tech_ssl_subcard', [], $lang) }}</div>
        <div class="subcard-inner">
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.ssl_status', [], $lang) }}</div>
                @php
                    $sslBadge = match($ssl['status'] ?? '') {
                        'valid' => 'badge-success',
                        'expiring_soon' => 'badge-warning',
                        'expired' => 'badge-danger',
                        default => 'badge-info',
                    };
                    $sslLabel = match($ssl['status'] ?? '') {
                        'valid' => __('report.ssl_valid', [], $lang),
                        'expiring_soon' => __('report.ssl_expiring_soon', [], $lang),
                        'expired' => __('report.ssl_expired', [], $lang),
                        default => $ssl['status_label'] ?? '—',
                    };
                @endphp
                <div style="font-size: 9pt; font-weight: 600; color: #0f172a;"><span class="badge {{ $sslBadge }}">{{ $sslLabel }}</span></div>
            </div>
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.ssl_issuer', [], $lang) }}</div>
                <div style="font-size: 9pt; font-weight: 600; color: #0f172a;">{{ $ssl['issuer'] ?? '—' }}</div>
            </div>
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.ssl_expires', [], $lang) }}</div>
                <div style="font-size: 9pt; font-weight: 600; color: #0f172a;">{{ $ssl['expires_at'] ?? '—' }}</div>
            </div>
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.ssl_days_remaining', [], $lang) }}</div>
                @php
                    $daysColor = ($ssl['days_remaining'] ?? 0) > 30 ? '#10b981' : (($ssl['days_remaining'] ?? 0) > 7 ? '#f59e0b' : '#ef4444');
                @endphp
                <div style="font-size: 9pt; font-weight: 700; color: {{ $daysColor }};">{{ $ssl['days_remaining'] ?? '—' }}</div>
            </div>
        </div>
    </div>
@endif

{{-- Domain Registration sub-card --}}
@if($domain && ($sectionOptions['infrastructure']['show_domain'] ?? true))
    <div class="subcard mt-4">
        <div class="subcard-title">{{ __('report.tech_domain_subcard', [], $lang) }}</div>
        <div class="subcard-inner">
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.domain_registrar', [], $lang) }}</div>
                <div style="font-size: 9pt; font-weight: 600; color: #0f172a;">{{ $domain['registrar'] ?? '—' }}</div>
            </div>
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.domain_expires', [], $lang) }}</div>
                <div style="font-size: 9pt; font-weight: 600; color: #0f172a;">{{ $domain['expires_at'] ?? '—' }}</div>
            </div>
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.domain_days_remaining', [], $lang) }}</div>
                @php
                    $domDaysColor = ($domain['days_remaining'] ?? 0) > 60 ? '#10b981' : (($domain['days_remaining'] ?? 0) > 14 ? '#f59e0b' : '#ef4444');
                @endphp
                <div style="font-size: 9pt; font-weight: 700; color: {{ $domDaysColor }};">{{ $domain['days_remaining'] ?? '—' }}</div>
            </div>
            <div class="subcard-field">
                <div class="kpi-label">{{ __('report.domain_dns_provider', [], $lang) }}</div>
                <div style="font-size: 9pt; font-weight: 600; color: #0f172a;">{{ $domain['dns_provider'] ?? '—' }}</div>
            </div>
        </div>
    </div>
@endif

{{-- Email Deliverability sub-card --}}
@if($email && ($sectionOptions['infrastructure']['show_email'] ?? true))
    <div class="subcard mt-4">
        <div class="subcard-title">{{ __('report.tech_email_subcard', [], $lang) }}</div>
        <div style="display: flex; gap: 16px; align-items: flex-start;">
            <div style="text-align: center; min-width: 90px;">
                @include('reports.components.score-circle', ['score' => $email['score'] ?? null, 'size' => 60])
                <div class="kpi-label" style="margin-top: 4px;">{{ __('report.email_score', [], $lang) }}</div>
            </div>
            <div style="flex: 1;">
                <div class="email-check">
                    <span class="email-indicator {{ ($email['spf_exists'] ?? false) ? 'pass' : 'fail' }}">
                        {{ ($email['spf_exists'] ?? false) ? '✓' : '✗' }}
                    </span>
                    <span>
                        {{ __('report.email_spf', [], $lang) }}
                        <span class="text-muted">— {{ ($email['spf_exists'] ?? false) ? __('report.email_configured', [], $lang) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
                <div class="email-check">
                    <span class="email-indicator {{ ($email['dkim_exists'] ?? false) ? 'pass' : 'fail' }}">
                        {{ ($email['dkim_exists'] ?? false) ? '✓' : '✗' }}
                    </span>
                    <span>
                        {{ __('report.email_dkim', [], $lang) }}
                        <span class="text-muted">— {{ ($email['dkim_exists'] ?? false) ? __('report.email_configured', [], $lang) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
                <div class="email-check">
                    <span class="email-indicator {{ ($email['dmarc_exists'] ?? false) ? 'pass' : 'fail' }}">
                        {{ ($email['dmarc_exists'] ?? false) ? '✓' : '✗' }}
                    </span>
                    <span>
                        {{ __('report.email_dmarc', [], $lang) }}
                        <span class="text-muted">— {{ ($email['dmarc_exists'] ?? false) ? ($email['dmarc_policy'] ?? __('report.email_configured', [], $lang)) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
@endif
