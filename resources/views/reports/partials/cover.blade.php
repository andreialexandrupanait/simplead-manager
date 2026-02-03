<div class="cover-page">
    @if($template->company_logo_path && file_exists(storage_path('app/' . $template->company_logo_path)))
        <img src="{{ storage_path('app/' . $template->company_logo_path) }}" class="cover-logo" alt="">
    @elseif($template->company_name)
        <div class="cover-company-name">{{ $template->company_name }}</div>
    @endif

    <div class="cover-divider"></div>

    <div class="cover-title">Raport {{ $site->client->name ?? $site->name }}</div>

    <div class="cover-url">{{ $site->url }}</div>

    <div class="cover-period">
        {{ $periodStart->format('d.m.Y') }} &mdash; {{ $periodEnd->format('d.m.Y') }}
    </div>

    <div class="cover-bottom">
        <div class="cover-bottom-name">{{ $template->company_name ?? 'SimpleAd' }}</div>
    </div>
</div>
