<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        {{-- Site Selector --}}
        <div class="flex items-center gap-2 flex-wrap" x-data="{ open: false, search: '' }">
            @foreach($comparisonData as $i => $entry)
                <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium text-white" style="background-color: {{ $entry['color'] }}">
                    {{ $entry['site']->name }}
                    <button wire:click="removeSite({{ $entry['site']->id }})" class="ml-1 hover:opacity-75">&times;</button>
                </span>
            @endforeach

            @if(count($selectedSiteIds) < 4)
                <div class="relative">
                    <button @click="open = !open" class="rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-sm text-gray-500 hover:border-purple-400 hover:text-purple-600 transition">
                        + Add Site
                    </button>
                    <div x-show="open" @click.outside="open = false" x-transition class="absolute left-0 top-full mt-1 z-50 w-64 rounded-lg border border-gray-200 bg-white shadow-lg">
                        <div class="p-2">
                            <input x-model="search" type="text" placeholder="Search sites..." class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500" />
                        </div>
                        <div class="max-h-48 overflow-y-auto p-1">
                            @foreach($allSites as $site)
                                @if(!in_array($site->id, $selectedSiteIds))
                                    <button
                                        x-show="!search || '{{ strtolower(addslashes($site->name)) }}'.includes(search.toLowerCase())"
                                        wire:click="addSite({{ $site->id }})"
                                        @click="open = false; search = ''"
                                        class="w-full rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-purple-50 hover:text-purple-700 transition"
                                    >
                                        {{ $site->name }}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Controls --}}
        <div class="flex items-center gap-3">
            {{-- Metric toggle --}}
            <div class="flex gap-1">
                <button wire:click="setMetric('analytics')" @class([
                    'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-purple-100 text-purple-700' => $metric === 'analytics',
                    'bg-gray-100 text-gray-600 hover:bg-gray-200' => $metric !== 'analytics',
                ])>Analytics</button>
                <button wire:click="setMetric('search_console')" @class([
                    'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-purple-100 text-purple-700' => $metric === 'search_console',
                    'bg-gray-100 text-gray-600 hover:bg-gray-200' => $metric !== 'search_console',
                ])>Search Console</button>
            </div>
            {{-- Date range --}}
            <div class="flex gap-1">
                @foreach(['7d' => '7d', '28d' => '28d', '90d' => '90d'] as $value => $label)
                    <button wire:click="setDateRange('{{ $value }}')" @class([
                        'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        'bg-purple-100 text-purple-700' => $dateRange === $value,
                        'bg-gray-100 text-gray-600 hover:bg-gray-200' => $dateRange !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    @if(count($comparisonData) === 0)
        <x-ui.card>
            <x-ui.empty-state
                title="Select sites to compare"
                description="Add 2-4 sites to compare their analytics or search console metrics side by side."
                icon="bar-chart-2"
            />
        </x-ui.card>
    @else
        {{-- Metric Cards Side by Side --}}
        <div class="mb-6">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">Overview Comparison</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="pb-2 text-left font-medium text-gray-500">Metric</th>
                                @foreach($comparisonData as $entry)
                                    <th class="pb-2 text-right font-medium" style="color: {{ $entry['color'] }}">{{ $entry['site']->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @if($metric === 'analytics')
                                @foreach(['total_users' => 'Users', 'new_users' => 'New Users', 'sessions' => 'Sessions', 'pageviews' => 'Pageviews', 'bounce_rate' => 'Bounce Rate', 'avg_session_duration' => 'Avg Duration', 'engagement_rate' => 'Engagement Rate'] as $key => $label)
                                    <tr>
                                        <td class="py-2 font-medium text-gray-700">{{ $label }}</td>
                                        @foreach($comparisonData as $entry)
                                            <td class="py-2 text-right text-gray-600">
                                                @if($entry['metrics'])
                                                    @if(in_array($key, ['bounce_rate', 'engagement_rate']))
                                                        {{ $entry['metrics'][$key] ?? 0 }}%
                                                    @elseif($key === 'avg_session_duration')
                                                        {{ gmdate('i:s', $entry['metrics'][$key] ?? 0) }}
                                                    @else
                                                        {{ number_format($entry['metrics'][$key] ?? 0) }}
                                                    @endif
                                                @else
                                                    <span class="text-gray-300">&mdash;</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @else
                                @foreach(['clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Avg Position'] as $key => $label)
                                    <tr>
                                        <td class="py-2 font-medium text-gray-700">{{ $label }}</td>
                                        @foreach($comparisonData as $entry)
                                            <td class="py-2 text-right text-gray-600">
                                                @if($entry['metrics'])
                                                    @if($key === 'ctr')
                                                        {{ $entry['metrics'][$key] ?? 0 }}%
                                                    @elseif($key === 'position')
                                                        {{ $entry['metrics'][$key] ?? 0 }}
                                                    @else
                                                        {{ number_format($entry['metrics'][$key] ?? 0) }}
                                                    @endif
                                                @else
                                                    <span class="text-gray-300">&mdash;</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endif
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        {{-- Overlay Line Chart --}}
        @php
            $hasTimeSeries = collect($comparisonData)->contains(fn($e) => count($e['timeSeries']) > 0);
        @endphp
        @if($hasTimeSeries)
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">Trend Comparison</h3>
                <div style="height: 350px" class="relative" x-data="{
                    chart: null,
                    init() {
                        this.$nextTick(() => this.render());
                    },
                    render() {
                        if (this.chart) this.chart.destroy();
                        const compData = @js($comparisonData);
                        const metric = @js($metric);

                        const allDates = new Set();
                        compData.forEach(e => e.timeSeries.forEach(d => allDates.add(d.date)));
                        const sortedDates = [...allDates].sort();
                        const labels = sortedDates.map(d => {
                            const dt = new Date(d + 'T00:00:00');
                            return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                        });

                        const datasets = compData.map(entry => {
                            const dateMap = {};
                            entry.timeSeries.forEach(d => { dateMap[d.date] = d; });
                            const key = metric === 'analytics' ? 'users' : 'clicks';
                            const data = sortedDates.map(d => dateMap[d] ? (dateMap[d][key] ?? 0) : null);

                            return {
                                label: entry.site.name,
                                data: data,
                                borderColor: entry.color,
                                backgroundColor: entry.color + '1A',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.3,
                                pointRadius: 1,
                                pointHoverRadius: 4,
                                spanGaps: true,
                            };
                        });

                        this.chart = new Chart(this.$refs.canvas, {
                            type: 'line',
                            data: { labels, datasets },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16 } },
                                },
                                scales: {
                                    x: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280' } },
                                    y: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280' }, beginAtZero: true },
                                },
                            },
                        });
                    },
                }" x-effect="render()">
                    <canvas x-ref="canvas"></canvas>
                </div>
            </x-ui.card>
        @endif
    @endif
</div>
