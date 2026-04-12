@php
    $scData = $this->searchConsoleData;
    $isConnected = $site->searchConsoleConnection?->is_active;
@endphp

<x-ui.card :padding="false">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100">
                <svg aria-hidden="true" class="h-4 w-4 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Search Console</h3>
        </div>
        @if($isConnected)
            <a href="{{ route('sites.search-console', $site) }}" class="text-xs text-accent-600 hover:text-accent-700">
                View Details →
            </a>
        @endif
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        @if($isConnected && $scData)
            @php
                $scMetrics = [
                    ['key' => 'clicks', 'label' => 'Clicks', 'format' => 'number'],
                    ['key' => 'impressions', 'label' => 'Impressions', 'format' => 'number'],
                    ['key' => 'ctr', 'label' => 'CTR', 'format' => 'percent'],
                    ['key' => 'position', 'label' => 'Avg Position', 'format' => 'decimal'],
                ];
            @endphp

            <div class="grid grid-cols-4 gap-3">
                @foreach($scMetrics as $metric)
                    <div class="rounded-lg border border-gray-100 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ $metric['label'] }}</div>
                        <div class="mt-1 text-lg font-bold text-gray-900">
                            @if($metric['format'] === 'number')
                                {{ number_format($scData[$metric['key']] ?? 0) }}
                            @elseif($metric['format'] === 'percent')
                                {{ number_format(($scData[$metric['key']] ?? 0) * 100, 1) }}%
                            @else
                                {{ number_format($scData[$metric['key']] ?? 0, 1) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-2 text-xs text-gray-400">Last 28 days</p>
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
    </div>
</x-ui.card>
