@php $lang = $language ?? 'ro'; @endphp
<div class="section-top-bar">
    <span class="bar-left">{{ $branding['company_website'] ?? 'simplead.ro' }}</span>
    <span class="bar-center">{{ mb_strtoupper(__('report.title', [], $lang)) }} &middot; {{ mb_strtoupper($branding['client_name'] ?? '') }}</span>
    <span class="bar-right">{{ $periodStart->format('d/m/Y') }} &ndash; {{ $periodEnd->format('d/m/Y') }}</span>
</div>
<hr class="section-divider-primary">
