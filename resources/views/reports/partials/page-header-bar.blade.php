<div class="page-header-bar">
    @if($logoBase64White)
        <img src="{{ $logoBase64White }}" class="page-header-logo" alt="">
    @else
        <span class="page-header-company">{{ $branding['company_name'] ?? 'SimpleAd' }}</span>
    @endif
    <div class="page-header-right">
        <div class="page-header-title">{{ mb_strtoupper(__('report.title', [], $lang)) }}  |  {{ mb_strtoupper($branding['client_name'] ?? '') }}</div>
        <div class="page-header-sub">{{ $periodStart->format('d/m/Y') }} &ndash; {{ $periodEnd->format('d/m/Y') }}</div>
        <div class="page-header-sub">{{ __('report.generated_at', [], $lang) }}: {{ now()->format('d/m/Y H:i') }}</div>
    </div>
</div>
