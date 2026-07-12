@php
    $dns = $data['dns'] ?? null;
    $errors = $data['error_logs'] ?? null;
    $lang = $language ?? 'ro';

    $showDns = ($dns['available'] ?? false) && ($sectionOptions['infrastructure']['show_dns'] ?? true);
    $showErrors = ($errors['available'] ?? false) && ($sectionOptions['infrastructure']['show_error_logs'] ?? true);
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['infrastructure']['title'] ?? __('report.section_infrastructure', [], $lang),
    'number' => $sectionNumber ?? null,
])

<div class="infra-stack">
    {{-- DNS & Email Deliverability --}}
    @if($showDns)
        @php
            $dmarcOk = (bool) ($dns['has_dmarc'] ?? false);
            $recordCount = collect($dns['current_records'] ?? [])->flatten()->count();
        @endphp
        <div class="infra-card">
            <div class="infra-card-header">
                <span class="infra-card-title">{{ __('report.infra_dns_subcard', [], $lang) }}</span>
            </div>
            <div class="infra-card-body">
                <div class="infra-fields">
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.infra_dns_domain', [], $lang) }}</span>
                        <span class="field-value">{{ $dns['domain'] ?? '—' }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.infra_dns_records', [], $lang) }}</span>
                        <span class="field-value">{{ $recordCount }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.infra_dns_changes', [], $lang) }}</span>
                        <span class="field-value">{{ $dns['changes_count'] ?? 0 }}</span>
                    </div>
                </div>

                <div class="email-check">
                    <span class="email-indicator {{ ($dns['has_spf'] ?? false) ? 'pass' : 'fail' }}">
                        {{ ($dns['has_spf'] ?? false) ? '✓' : '✗' }}
                    </span>
                    <span>
                        {{ __('report.email_spf', [], $lang) }}
                        — <span class="{{ ($dns['has_spf'] ?? false) ? 'email-text-pass' : 'email-text-fail' }}">{{ ($dns['has_spf'] ?? false) ? __('report.email_configured', [], $lang) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
                <div class="email-check">
                    <span class="email-indicator {{ ($dns['has_dkim'] ?? false) ? 'pass' : 'fail' }}">
                        {{ ($dns['has_dkim'] ?? false) ? '✓' : '✗' }}
                    </span>
                    <span>
                        {{ __('report.email_dkim', [], $lang) }}
                        — <span class="{{ ($dns['has_dkim'] ?? false) ? 'email-text-pass' : 'email-text-fail' }}">{{ ($dns['has_dkim'] ?? false) ? __('report.email_configured', [], $lang) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
                <div class="email-check">
                    <span class="email-indicator {{ $dmarcOk ? 'pass' : 'fail' }}">
                        {{ $dmarcOk ? '✓' : '⚠' }}
                    </span>
                    <span>
                        {{ __('report.email_dmarc', [], $lang) }}
                        — <span class="{{ $dmarcOk ? 'email-text-pass' : 'email-text-fail' }}">{{ $dmarcOk ? __('report.email_configured', [], $lang) : __('report.email_missing', [], $lang) }}</span>
                    </span>
                </div>
            </div>
        </div>
    @endif

    {{-- PHP Error Log --}}
    @if($showErrors)
        @php
            $errTotal = (int) ($errors['total_count'] ?? 0);
            $errColor = ($errors['fatal_count'] ?? 0) > 0 ? '#ef4444' : (($errors['unresolved_count'] ?? 0) > 0 ? '#f59e0b' : '#10b981');
        @endphp
        <div class="infra-card">
            <div class="infra-card-header">
                <span class="infra-card-title">{{ __('report.infra_errors_subcard', [], $lang) }}</span>
                <span style="font-size: 9pt; font-weight: 700; color: {{ $errColor }};">{{ $errTotal }}</span>
            </div>
            <div class="infra-card-body">
                <div class="infra-fields">
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.infra_errors_total', [], $lang) }}</span>
                        <span class="field-value">{{ $errTotal }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.infra_errors_fatal', [], $lang) }}</span>
                        <span class="field-value">{{ $errors['fatal_count'] ?? 0 }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.infra_errors_warnings', [], $lang) }}</span>
                        <span class="field-value">{{ $errors['warning_count'] ?? 0 }}</span>
                    </div>
                    <div class="infra-field">
                        <span class="field-label">{{ __('report.infra_errors_unresolved', [], $lang) }}</span>
                        <span class="field-value">{{ $errors['unresolved_count'] ?? 0 }}</span>
                    </div>
                </div>

                @if($errTotal === 0)
                    <div class="email-check">
                        <span class="email-indicator pass">✓</span>
                        <span class="email-text-pass">{{ __('report.infra_errors_none', [], $lang) }}</span>
                    </div>
                @else
                    @foreach(($errors['top_errors'] ?? []) as $err)
                        <div class="email-check">
                            <span class="email-indicator {{ ($err['level'] ?? '') === 'fatal' ? 'fail' : 'pass' }}">
                                {{ ($err['level'] ?? '') === 'fatal' ? '✗' : '⚠' }}
                            </span>
                            <span>{{ \Illuminate\Support\Str::limit($err['message'] ?? '', 90) }} <strong>×{{ $err['count'] ?? 1 }}</strong></span>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    @endif
</div>
