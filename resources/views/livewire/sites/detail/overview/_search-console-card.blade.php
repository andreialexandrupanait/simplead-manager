@php
    $scData = $this->searchConsoleData;
    $isConnected = $site->searchConsoleConnection?->is_active;
@endphp

<x-ui.module-card title="Search Console" icon="search" tone="dark">
    @if($isConnected && $scData)
        @php
            $scMetrics = [
                ['key' => 'clicks', 'label' => 'Clicks', 'format' => 'number'],
                ['key' => 'impressions', 'label' => 'Impressions', 'format' => 'number'],
                ['key' => 'ctr', 'label' => 'CTR', 'format' => 'percent'],
                ['key' => 'position', 'label' => 'Avg Position', 'format' => 'decimal'],
            ];
        @endphp

        <div class="mg2">
            @foreach($scMetrics as $metric)
                <x-ui.stat-box label="{{ $metric['label'] }}">@if($metric['format'] === 'number'){{ number_format($scData[$metric['key']] ?? 0) }}@elseif($metric['format'] === 'percent'){{ number_format(($scData[$metric['key']] ?? 0) * 100, 1) }}%@else{{ number_format($scData[$metric['key']] ?? 0, 1) }}@endif</x-ui.stat-box>
            @endforeach
        </div>

        <p class="mt-2 text-xs text-gray-400">Last 28 days</p>
        <a href="{{ route('sites.search-console', $site) }}" class="mact">View Details →</a>
    @elseif($isConnected)
        <p class="py-3 text-center text-sm text-gray-500">No Search Console data yet</p>
        <div class="text-center">
            <a href="{{ route('sites.search-console', $site) }}" class="text-xs text-accent-600 hover:text-accent-700">
                View Details →
            </a>
        </div>
    @else
        <div class="rounded-lg border border-dashed border-gray-200 p-4 text-center">
            <p class="text-sm text-gray-500">Search Console not connected</p>
            <a href="{{ route('sites.search-console', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                Connect Search Console →
            </a>
        </div>
    @endif
</x-ui.module-card>
