@php
    $dns = $this->dnsStatus;
    $dnsTone = $dns['available']
        ? (($dns['has_spf'] && $dns['has_dmarc'] && $dns['has_dkim']) ? 'ok' : 'warn')
        : 'dark';
@endphp

<x-ui.module-card title="DNS" icon="cloud" :tone="$dnsTone">
    @if($dns['available'])
        {{-- SPF --}}
        <x-ui.info-row label="SPF">
            <span class="{{ $dns['has_spf'] ? 'mval-ok' : 'mval-bad' }}">{{ $dns['has_spf'] ? 'Configured' : 'Missing' }}</span>
        </x-ui.info-row>

        {{-- DMARC --}}
        <x-ui.info-row label="DMARC">
            <span class="{{ $dns['has_dmarc'] ? 'mval-ok' : 'mval-bad' }}">{{ $dns['has_dmarc'] ? 'Configured' : 'Missing' }}</span>
        </x-ui.info-row>

        {{-- DKIM --}}
        <x-ui.info-row label="DKIM">
            <span class="{{ $dns['has_dkim'] ? 'mval-ok' : 'mval-bad' }}">{{ $dns['has_dkim'] ? 'Configured' : 'Missing' }}</span>
        </x-ui.info-row>

        {{-- Recent update indicator --}}
        @if($dns['has_changes'])
            <x-ui.info-row label="Records">
                <x-ui.badge variant="blue">Updated</x-ui.badge>
            </x-ui.info-row>
        @endif

        @if($dns['last_checked'])
            <p class="mt-2 text-xs text-gray-400">Checked {{ $dns['last_checked']->diffForHumans() }}</p>
        @endif

        <a href="{{ $site->siteCloudflare ? route('sites.cloudflare', $site) : route('sites.settings', $site) }}" class="mact">Details →</a>
    @else
        <div class="py-2 text-center">
            <p class="text-sm text-gray-500">Not monitored</p>
            <a href="{{ route('sites.settings', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                Enable DNS Monitor →
            </a>
        </div>
    @endif
</x-ui.module-card>
