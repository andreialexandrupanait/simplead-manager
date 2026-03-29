@php
    $perfData = $this->performanceData;
@endphp

<x-ui.card :padding="false">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-100">
                <svg class="h-4 w-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Analytics & Performance</h3>
        </div>
        <a href="{{ route('sites.analytics', $site) }}" class="text-xs text-purple-600 hover:text-purple-700">
            View Analytics →
        </a>
    </div>

    <div class="p-4 space-y-4">
        {{-- Analytics Section --}}
        <div>
            {{-- Period Selector --}}
            <div class="mb-3 flex gap-1">
                @foreach(['1d' => 'Yesterday', '7d' => '7 Days', '28d' => '30 Days', '90d' => '90 Days'] as $period => $label)
                    <button
                        wire:click="setAnalyticsPeriod('{{ $period }}')"
                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition {{ $analyticsPeriod === $period ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if($this->analyticsData && ($this->analyticsData['overview'] ?? null))
                @php
                    $overview = $this->analyticsData['overview'];
                    $previous = $this->analyticsData['overview_previous'] ?? [];

                    $metrics = [
                        ['key' => 'total_users', 'label' => 'Users'],
                        ['key' => 'bounce_rate', 'label' => 'Bounce Rate', 'suffix' => '%', 'invert' => true],
                        ['key' => 'pageviews', 'label' => 'Pageviews'],
                    ];
                @endphp

                {{-- Metrics Row --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @foreach($metrics as $metric)
                        @php
                            $current = $overview[$metric['key']] ?? 0;
                            $prev = $previous[$metric['key']] ?? 0;
                            $change = $prev > 0 ? round(($current - $prev) / $prev * 100, 1) : null;
                            $suffix = $metric['suffix'] ?? '';
                            $invert = $metric['invert'] ?? false;
                            $isPositive = $invert ? ($change <= 0) : ($change >= 0);
                        @endphp
                        <div class="rounded-lg border border-gray-100 p-3 text-center">
                            <div class="text-xs text-gray-500">{{ $metric['label'] }}</div>
                            <div class="mt-1 text-lg font-bold text-gray-900">
                                @if($suffix)
                                    {{ round($current, 1) }}{{ $suffix }}
                                @else
                                    {{ number_format($current) }}
                                @endif
                            </div>
                            @if($change !== null)
                                <div class="mt-0.5 flex items-center justify-center gap-0.5 text-xs {{ $isPositive ? 'text-green-600' : 'text-red-600' }}">
                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        @if($change >= 0)
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                                        @else
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                                        @endif
                                    </svg>
                                    {{ abs($change) }}%
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif($site->analyticsConnection?->is_active)
                <p class="py-3 text-center text-sm text-gray-500">No analytics data for this period</p>
            @else
                <div class="rounded-lg border border-dashed border-gray-200 p-4 text-center">
                    <p class="text-sm text-gray-500">Analytics not connected</p>
                    <a href="{{ route('sites.analytics', $site) }}" class="mt-1 inline-block text-xs text-purple-600 hover:text-purple-700">
                        Connect Google Analytics →
                    </a>
                </div>
            @endif
        </div>

        {{-- Performance Section --}}
        <div class="border-t border-gray-100 pt-4">
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-400">Performance</h4>
                <a href="{{ route('sites.performance', $site) }}" class="text-xs text-purple-600 hover:text-purple-700">
                    Details →
                </a>
            </div>

            @if($perfData && ($perfData['mobile_score'] !== null || $perfData['desktop_score'] !== null))
                @php
                    $metricLabels = [
                        'fcp' => 'First Contentful Paint',
                        'si' => 'Speed Index',
                        'lcp' => 'Largest Contentful Paint',
                        'tti' => 'Time to Interactive',
                        'tbt' => 'Total Blocking Time',
                        'cls' => 'Cumulative Layout Shift',
                    ];
                    $colorClasses = [
                        'green' => 'text-green-600',
                        'orange' => 'text-yellow-600',
                        'red' => 'text-red-600',
                        'gray' => 'text-gray-400',
                    ];
                @endphp
                <div x-data="{ showMobile: false, showDesktop: false }" class="space-y-2">
                    <div class="grid grid-cols-2 gap-3">
                        {{-- Mobile Score --}}
                        <div>
                            <button @click="showMobile = !showMobile; showDesktop = false"
                                class="w-full rounded-lg border border-gray-100 p-3 text-center hover:border-gray-200 hover:bg-gray-50 transition cursor-pointer">
                                <div class="text-xs text-gray-500">Mobile</div>
                                @php
                                    $mScore = $perfData['mobile_score'];
                                    $mColor = $mScore === null ? 'text-gray-400' : ($mScore >= 90 ? 'text-green-600' : ($mScore >= 50 ? 'text-yellow-600' : 'text-red-600'));
                                @endphp
                                <div class="mt-1 text-2xl font-bold {{ $mColor }}">{{ $mScore ?? '—' }}</div>
                                <div class="mt-1 text-xs text-gray-400">Click for details</div>
                            </button>
                        </div>

                        {{-- Desktop Score --}}
                        <div>
                            <button @click="showDesktop = !showDesktop; showMobile = false"
                                class="w-full rounded-lg border border-gray-100 p-3 text-center hover:border-gray-200 hover:bg-gray-50 transition cursor-pointer">
                                <div class="text-xs text-gray-500">Desktop</div>
                                @php
                                    $dScore = $perfData['desktop_score'];
                                    $dColor = $dScore === null ? 'text-gray-400' : ($dScore >= 90 ? 'text-green-600' : ($dScore >= 50 ? 'text-yellow-600' : 'text-red-600'));
                                @endphp
                                <div class="mt-1 text-2xl font-bold {{ $dColor }}">{{ $dScore ?? '—' }}</div>
                                <div class="mt-1 text-xs text-gray-400">Click for details</div>
                            </button>
                        </div>
                    </div>

                    {{-- Mobile Metrics Panel --}}
                    @if($perfData['mobile_metrics'])
                        <div x-show="showMobile" x-cloak x-transition class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="mb-2 text-xs font-semibold text-gray-500 uppercase tracking-wider">Mobile Metrics</div>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach($metricLabels as $key => $label)
                                    @php $m = $perfData['mobile_metrics'][$key] ?? null; @endphp
                                    <div class="flex items-center justify-between rounded bg-white px-2 py-1.5">
                                        <span class="text-xs text-gray-600">{{ $label }}</span>
                                        <span class="text-xs font-semibold {{ $colorClasses[$m['color'] ?? 'gray'] }}">{{ $m['value'] ?? '—' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Desktop Metrics Panel --}}
                    @if($perfData['desktop_metrics'])
                        <div x-show="showDesktop" x-cloak x-transition class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="mb-2 text-xs font-semibold text-gray-500 uppercase tracking-wider">Desktop Metrics</div>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach($metricLabels as $key => $label)
                                    @php $m = $perfData['desktop_metrics'][$key] ?? null; @endphp
                                    <div class="flex items-center justify-between rounded bg-white px-2 py-1.5">
                                        <span class="text-xs text-gray-600">{{ $label }}</span>
                                        <span class="text-xs font-semibold {{ $colorClasses[$m['color'] ?? 'gray'] }}">{{ $m['value'] ?? '—' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                @if($perfData['last_tested_at'])
                    <p class="mt-2 text-xs text-gray-400">Last tested {{ $perfData['last_tested_at']->diffForHumans() }}</p>
                @endif
            @else
                <div class="rounded-lg border border-dashed border-gray-200 p-4 text-center">
                    <p class="text-sm text-gray-500">No performance data</p>
                    <a href="{{ route('sites.performance', $site) }}" class="mt-1 inline-block text-xs text-purple-600 hover:text-purple-700">
                        Run first test →
                    </a>
                </div>
            @endif
        </div>
    </div>
</x-ui.card>
