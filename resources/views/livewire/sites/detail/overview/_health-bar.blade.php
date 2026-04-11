@php
    $dims = $this->healthDimensions;
    $score = $dims['healthScore'];
    $barColor = $score >= 75 ? 'bg-green-500' : ($score >= 50 ? 'bg-yellow-500' : 'bg-red-500');
    $scoreTextColor = $score >= 75 ? 'text-green-700' : ($score >= 50 ? 'text-yellow-700' : 'text-red-700');

    $indicators = [
        ['key' => 'uptime', 'label' => 'Uptime'],
        ['key' => 'ssl', 'label' => 'SSL'],
        ['key' => 'performance', 'label' => 'Performance'],
        ['key' => 'backup', 'label' => 'Backups'],
        ['key' => 'plugins', 'label' => 'Updates'],
        ['key' => 'wpVersion', 'label' => 'WordPress'],
    ];
@endphp

<x-ui.card :padding="false">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100">
                <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Health Score</h3>
        </div>
        <span class="text-xl font-bold {{ $scoreTextColor }}">{{ $score }}<span class="text-xs font-normal text-gray-400">/100</span></span>
    </div>

    <div class="p-3 space-y-3">
        {{-- Progress Bar --}}
        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
            <div class="h-full rounded-full transition-all {{ $barColor }}" style="width: {{ min($score, 100) }}%"></div>
        </div>

        {{-- Dimension Indicators --}}
        <div class="grid grid-cols-2 gap-1.5">
            @foreach($indicators as $ind)
                @php
                    $dim = $dims[$ind['key']] ?? ['color' => 'text-gray-300', 'tip' => ''];
                @endphp
                <span class="flex items-center gap-1.5" title="{{ $dim['tip'] }}">
                    <span class="inline-block h-2 w-2 rounded-full {{ str_replace('text-', 'bg-', $dim['color']) }}"></span>
                    <span class="text-xs text-gray-600">{{ $ind['label'] }}</span>
                </span>
            @endforeach
        </div>

        {{-- Score Breakdown --}}
        @php
            $breakdown = $this->healthBreakdown;
        @endphp
        <div class="border-t border-gray-100 pt-3 space-y-2">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Score Breakdown</p>
            @foreach($breakdown['components'] as $component)
                @php
                    $pct = $component['max'] > 0 ? round(($component['score'] / $component['max']) * 100) : 0;
                    $compBarColor = $pct >= 75 ? 'bg-green-500' : ($pct >= 50 ? 'bg-yellow-400' : 'bg-red-400');
                    $compTextColor = $pct >= 75 ? 'text-green-700' : ($pct >= 50 ? 'text-yellow-700' : 'text-red-600');
                @endphp
                <div>
                    <div class="flex items-center justify-between mb-0.5">
                        <span class="text-xs text-gray-600">{{ $component['label'] }}</span>
                        <span class="text-xs font-semibold {{ $compTextColor }}">{{ $component['score'] }}<span class="font-normal text-gray-400">/{{ $component['max'] }}</span></span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full {{ $compBarColor }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- 30-day Trend --}}
        @php
            $trend = $this->healthTrend;
        @endphp
        @if(!empty($trend['history']))
            <div class="border-t border-gray-100 pt-3">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">30-day Trend</p>
                    @php
                        $trendIcon = match($trend['direction']) {
                            'up'   => '&#8593;',
                            'down' => '&#8595;',
                            default => '&#8212;',
                        };
                        $trendColor = match($trend['direction']) {
                            'up'   => 'text-green-600',
                            'down' => 'text-red-500',
                            default => 'text-gray-400',
                        };
                    @endphp
                    <span class="text-xs font-semibold {{ $trendColor }}">
                        {!! $trendIcon !!} {{ $trend['change'] > 0 ? '+' : '' }}{{ $trend['change'] }}
                    </span>
                </div>

                {{-- Mini sparkline bars --}}
                <div class="flex items-end gap-0.5 h-8">
                    @foreach($trend['history'] as $point)
                        @php
                            $barPct = max(10, min(100, $point));
                            $barColor = $point >= 75 ? 'bg-green-400' : ($point >= 50 ? 'bg-yellow-400' : 'bg-red-400');
                        @endphp
                        <div class="flex-1 rounded-sm {{ $barColor }}" style="height: {{ $barPct }}%;" title="{{ $point }}/100"></div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-ui.card>
