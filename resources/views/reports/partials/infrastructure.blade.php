@php
    $domain = $data['domain'] ?? null;
    $email = $data['email'] ?? null;
    $lang = $language ?? 'ro';

    $showDomain = $domain && ($sectionOptions['infrastructure']['show_domain'] ?? true);
    $showEmail = $email && ($sectionOptions['infrastructure']['show_email'] ?? true);
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['infrastructure']['title'] ?? __('report.section_infrastructure', [], $lang),
    'number' => $sectionNumber ?? null,
])

<div class="infra-stack">
    {{-- Domain Registration --}}
    @if($showDomain)
        @php
            $domDaysColor = ($domain['days_remaining'] ?? 0) > 60 ? '#10b981' : (($domain['days_remaining'] ?? 0) > 14 ? '#f59e0b' : '#ef4444');
        @endphp
        <div class="infra-card">
            <div class="infra-card-header">
                <span class="infra-card-title">{{ __('report.tech_domain_subcard', [], $lang) }}</span>
            </div>
            <div class="infra-card-body">
                <div class="infra-fields">
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.domain_registrar', [], $lang) }}</span>
                        <span class="field-value">{{ $domain['registrar'] ?? '—' }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.domain_expires', [], $lang) }}</span>
                        <span class="field-value">{{ $domain['expires_at'] ?? '—' }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.domain_days_remaining', [], $lang) }}</span>
                        <span class="field-value" style="color: {{ $domDaysColor }};">{{ $domain['days_remaining'] ?? '—' }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.domain_dns_provider', [], $lang) }}</span>
                        <span class="field-value">{{ $domain['dns_provider'] ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Email Deliverability --}}
    @if($showEmail)
        @php
            $dmarcIsEffective = ($email['dmarc_exists'] ?? false) && !in_array(strtolower($email['dmarc_policy'] ?? ''), ['none', '']);
        @endphp
        <div class="infra-card">
            <div class="infra-card-header">
                <span class="infra-card-title">{{ __('report.tech_email_subcard', [], $lang) }}</span>
                @if(($email['score'] ?? null) !== null)
                    <span style="font-size: 9pt; font-weight: 700; color: {{ ($email['score'] ?? 0) >= 80 ? '#10b981' : (($email['score'] ?? 0) >= 50 ? '#f59e0b' : '#ef4444') }};">{{ $email['score'] }}/100</span>
                @endif
            </div>
            <div class="infra-card-body">
                <div class="email-check">
                    <span class="email-indicator {{ ($email['spf_exists'] ?? false) ? 'pass' : 'fail' }}">
                        {{ ($email['spf_exists'] ?? false) ? '✓' : '✗' }}
                    </span>
                    <span>
                        {{ __('report.email_spf', [], $lang) }}
                        — <span class="{{ ($email['spf_exists'] ?? false) ? 'email-text-pass' : 'email-text-fail' }}">{{ ($email['spf_exists'] ?? false) ? __('report.email_configured', [], $lang) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
                <div class="email-check">
                    <span class="email-indicator {{ ($email['dkim_exists'] ?? false) ? 'pass' : 'fail' }}">
                        {{ ($email['dkim_exists'] ?? false) ? '✓' : '✗' }}
                    </span>
                    <span>
                        {{ __('report.email_dkim', [], $lang) }}
                        — <span class="{{ ($email['dkim_exists'] ?? false) ? 'email-text-pass' : 'email-text-fail' }}">{{ ($email['dkim_exists'] ?? false) ? __('report.email_configured', [], $lang) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
                <div class="email-check">
                    <span class="email-indicator {{ $dmarcIsEffective ? 'pass' : 'fail' }}">
                        {{ $dmarcIsEffective ? '✓' : '⚠' }}
                    </span>
                    <span>
                        {{ __('report.email_dmarc', [], $lang) }}
                        — <span class="{{ $dmarcIsEffective ? 'email-text-pass' : 'email-text-fail' }}">
                            {{ ($email['dmarc_exists'] ?? false) ? ($email['dmarc_policy'] ?? __('report.email_configured', [], $lang)) : __('report.email_missing', [], $lang) }}
                        </span>
                    </span>
                </div>
            </div>
        </div>
    @endif
</div>
