<x-scripts.data-table />
<div @if($hasRunningJobs) wire:poll.3s="checkJobProgress" @endif>
    @if($connection && $connection->is_active)
        <div class="mb-6 flex justify-end">
            <x-ui.date-range-selector :selected="$dateRange" />
        </div>
    @endif

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="fetch" :jobs="$trackedJobs" title="Fetching Search Console data..." />

    @if($connection && $connection->is_active)
        @if($cache)
            <p class="mb-6 text-xs text-gray-400">
                Data from {{ $cache->start_date->format('M d') }} &ndash; {{ $cache->end_date->format('M d, Y') }}
                &middot; Updated {{ $cache->fetched_at->diffForHumans() }}
            </p>
        @endif

        @if($overview)
            {{-- Metric Cards + Performance Chart wrapped in one Alpine scope for toggle + aggregation --}}
            <div x-data="{
                {{-- Metric toggle state --}}
                activeMetrics: { clicks: true, impressions: true, ctr: true, position: true },
                toggleMetric(key) { this.activeMetrics[key] = !this.activeMetrics[key]; this.renderChart(); },

                {{-- Aggregation --}}
                aggregation: 'daily',
                rawLabels: @js(collect($performanceOverTime)->pluck('date')->toArray()),
                rawClicks: @js(collect($performanceOverTime)->pluck('clicks')->toArray()),
                rawImpressions: @js(collect($performanceOverTime)->pluck('impressions')->toArray()),
                rawCtr: @js(collect($performanceOverTime)->pluck('ctr')->toArray()),
                rawPosition: @js(collect($performanceOverTime)->pluck('position')->toArray()),

                get chartData() {
                    if (this.aggregation === 'weekly') return this.weeklyData();
                    return {
                        labels: this.rawLabels.map(d => this.fmtDate(d)),
                        clicks: this.rawClicks,
                        impressions: this.rawImpressions,
                        ctr: this.rawCtr,
                        position: this.rawPosition,
                    };
                },

                fmtDate(d) {
                    const dt = new Date(d + 'T00:00:00');
                    return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                },

                weeklyData() {
                    const weeks = {};
                    this.rawLabels.forEach((d, i) => {
                        const dt = new Date(d + 'T00:00:00');
                        const jan1 = new Date(dt.getFullYear(), 0, 1);
                        const wk = Math.ceil(((dt - jan1) / 86400000 + jan1.getDay() + 1) / 7);
                        const key = dt.getFullYear() + '-W' + wk;
                        if (!weeks[key]) weeks[key] = { label: d, clicks: 0, impressions: 0, ctr: [], position: [], count: 0 };
                        weeks[key].clicks += this.rawClicks[i] || 0;
                        weeks[key].impressions += this.rawImpressions[i] || 0;
                        weeks[key].ctr.push(this.rawCtr[i] || 0);
                        weeks[key].position.push(this.rawPosition[i] || 0);
                        weeks[key].count++;
                    });
                    const keys = Object.keys(weeks).sort();
                    return {
                        labels: keys.map(k => 'W' + k.split('-W')[1]),
                        clicks: keys.map(k => weeks[k].clicks),
                        impressions: keys.map(k => weeks[k].impressions),
                        ctr: keys.map(k => +(weeks[k].ctr.reduce((a,b) => a+b, 0) / weeks[k].ctr.length).toFixed(1)),
                        position: keys.map(k => +(weeks[k].position.reduce((a,b) => a+b, 0) / weeks[k].position.length).toFixed(1)),
                    };
                },

                {{-- Chart --}}
                chart: null,
                init() { this.$nextTick(() => this.renderChart()); },
                renderChart() {
                    if (this.chart) this.chart.destroy();
                    const d = this.chartData;
                    const datasets = [];
                    if (this.activeMetrics.clicks) datasets.push({
                        label: 'Clicks', data: d.clicks, borderColor: '#8D5CF5', backgroundColor: '#8D5CF51A',
                        borderWidth: 2, fill: true, tension: 0.3, pointRadius: 2, pointHoverRadius: 4, yAxisID: 'y',
                    });
                    if (this.activeMetrics.impressions) datasets.push({
                        label: 'Impressions', data: d.impressions, borderColor: '#06b6d4', backgroundColor: '#06b6d41A',
                        borderWidth: 2, fill: true, tension: 0.3, pointRadius: 2, pointHoverRadius: 4, yAxisID: 'y',
                    });
                    if (this.activeMetrics.ctr) datasets.push({
                        label: 'CTR %', data: d.ctr, borderColor: '#10b981', backgroundColor: 'transparent',
                        borderWidth: 2, fill: false, tension: 0.3, pointRadius: 2, pointHoverRadius: 4, borderDash: [4, 2], yAxisID: 'y1',
                    });
                    if (this.activeMetrics.position) datasets.push({
                        label: 'Avg Position', data: d.position, borderColor: '#f59e0b', backgroundColor: 'transparent',
                        borderWidth: 2, fill: false, tension: 0.3, pointRadius: 2, pointHoverRadius: 4, borderDash: [4, 2], yAxisID: 'y2',
                    });

                    const hasY = this.activeMetrics.clicks || this.activeMetrics.impressions;
                    const hasY1 = this.activeMetrics.ctr;
                    const hasY2 = this.activeMetrics.position;

                    this.chart = new Chart(this.$refs.perfCanvas, {
                        type: 'line',
                        data: { labels: d.labels, datasets },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16 } },
                                tooltip: { callbacks: { label(ctx) {
                                    let v = ctx.parsed.y;
                                    if (ctx.dataset.label === 'CTR %') return 'CTR: ' + v + '%';
                                    if (ctx.dataset.label === 'Avg Position') return 'Position: ' + v;
                                    return ctx.dataset.label + ': ' + v.toLocaleString();
                                }}}
                            },
                            scales: {
                                x: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280' } },
                                y: { display: hasY, type: 'linear', position: 'left', grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280' }, beginAtZero: true, title: { display: true, text: 'Clicks / Impressions', color: '#6b7280' } },
                                y1: { display: hasY1, type: 'linear', position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#10b981', callback: v => v + '%' }, beginAtZero: true, title: { display: true, text: 'CTR %', color: '#10b981' } },
                                y2: { display: hasY2, type: 'linear', position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#f59e0b' }, reverse: true, title: { display: true, text: 'Position', color: '#f59e0b' } },
                            },
                        },
                    });
                },
            }">
                {{-- Metric cards with toggle + sparklines --}}
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    {{-- Clicks --}}
                    <div @click="toggleMetric('clicks')" class="cursor-pointer transition" :class="activeMetrics.clicks ? '' : 'opacity-50'">
                        <x-ui.card>
                            <div :class="activeMetrics.clicks ? 'border-b-2 border-purple-500 pb-2' : 'pb-2'">
                                <div class="text-xs font-medium text-gray-500">Clicks</div>
                                <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['clicks']) }}</div>
                            </div>
                            @if(count($performanceOverTime) > 1)
                                <x-charts.sparkline :data="collect($performanceOverTime)->pluck('clicks')->toArray()" color="#8D5CF5" />
                            @endif
                        </x-ui.card>
                    </div>
                    {{-- Impressions --}}
                    <div @click="toggleMetric('impressions')" class="cursor-pointer transition" :class="activeMetrics.impressions ? '' : 'opacity-50'">
                        <x-ui.card>
                            <div :class="activeMetrics.impressions ? 'border-b-2 border-cyan-500 pb-2' : 'pb-2'">
                                <div class="text-xs font-medium text-gray-500">Impressions</div>
                                <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['impressions']) }}</div>
                            </div>
                            @if(count($performanceOverTime) > 1)
                                <x-charts.sparkline :data="collect($performanceOverTime)->pluck('impressions')->toArray()" color="#06b6d4" />
                            @endif
                        </x-ui.card>
                    </div>
                    {{-- CTR --}}
                    <div @click="toggleMetric('ctr')" class="cursor-pointer transition" :class="activeMetrics.ctr ? '' : 'opacity-50'">
                        <x-ui.card>
                            <div :class="activeMetrics.ctr ? 'border-b-2 border-emerald-500 pb-2' : 'pb-2'">
                                <div class="text-xs font-medium text-gray-500">CTR</div>
                                <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['ctr'] }}%</div>
                            </div>
                            @if(count($performanceOverTime) > 1)
                                <x-charts.sparkline :data="collect($performanceOverTime)->pluck('ctr')->toArray()" color="#10b981" />
                            @endif
                        </x-ui.card>
                    </div>
                    {{-- Position (inverted: lower = better = green) --}}
                    <div @click="toggleMetric('position')" class="cursor-pointer transition" :class="activeMetrics.position ? '' : 'opacity-50'">
                        <x-ui.card>
                            <div :class="activeMetrics.position ? 'border-b-2 border-amber-500 pb-2' : 'pb-2'">
                                <div class="text-xs font-medium text-gray-500">Position</div>
                                <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['position'] }}</div>
                            </div>
                            @if(count($performanceOverTime) > 1)
                                <x-charts.sparkline :data="collect($performanceOverTime)->pluck('position')->toArray()" color="#f59e0b" />
                            @endif
                        </x-ui.card>
                    </div>
                </div>

                {{-- Performance Over Time chart --}}
                @if(count($performanceOverTime) > 0)
                    <div class="mt-6">
                        <x-ui.card>
                            <div class="mb-4 flex items-center justify-between">
                                <h3 class="text-base font-semibold text-gray-900">Performance Over Time</h3>
                                <div class="flex gap-1">
                                    <button @click="aggregation = 'daily'; renderChart()" :class="aggregation === 'daily' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'" class="rounded-lg px-3 py-1 text-xs font-medium transition">Daily</button>
                                    <button @click="aggregation = 'weekly'; renderChart()" :class="aggregation === 'weekly' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'" class="rounded-lg px-3 py-1 text-xs font-medium transition">Weekly</button>
                                </div>
                            </div>
                            <div style="height: 350px" class="relative">
                                <canvas x-ref="perfCanvas"></canvas>
                            </div>
                        </x-ui.card>
                    </div>
                @endif
            </div>

            {{-- Top Search Queries (Alpine-driven: sort, search, percentage bars, CSV export) --}}
            @if(count($queries) > 0)
                <div class="mt-6" x-data="{
                    ...dataTableMixin(@js($queries), ['query']),
                    get maxClicks() { return Math.max(...this.rows.map(r => r.clicks), 1); },
                    exportCsv() {
                        const headers = ['Query','Clicks','Impressions','CTR','Position'];
                        const csv = [headers.join(','), ...this.filtered.map(r =>
                            [this.csvEscape(r.query), r.clicks, r.impressions, r.ctr + '%', r.position].join(',')
                        )].join('\n');
                        this.downloadCsv(csv, 'search-queries.csv');
                    },
                }">
                    <x-ui.card>
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-gray-900">Top Search Queries</h3>
                            <div class="flex items-center gap-2">
                                <input x-model="search" type="text" placeholder="Search queries..." class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500 w-48" />
                                <button @click="exportCsv()" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200 transition" title="Export CSV">
                                    <svg class="inline h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>CSV
                                </button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('query')">Query <span class="text-xs" x-text="sortIcon('query')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('clicks')">Clicks <span class="text-xs" x-text="sortIcon('clicks')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('impressions')">Impr. <span class="text-xs" x-text="sortIcon('impressions')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('ctr')">CTR <span class="text-xs" x-text="sortIcon('ctr')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('position')">Position <span class="text-xs" x-text="sortIcon('position')"></span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row, idx) in filtered" :key="idx">
                                        <tr x-show="idx < limit">
                                            <td class="py-2 font-medium text-gray-700 flex items-center gap-1.5">
                                                <button @click="$wire.trackKeyword(row.query)" class="text-gray-300 hover:text-yellow-500 transition flex-shrink-0" title="Track keyword">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                                </button>
                                                <span class="text-purple-700 cursor-pointer hover:underline" x-text="row.query" @click="$wire.drillDown('query', row.query)"></span>
                                            </td>
                                            <td class="py-2 text-right text-gray-600 relative">
                                                <div class="absolute inset-y-0 left-0 bg-purple-50 rounded-sm" :style="'width:' + (row.clicks / maxClicks * 100) + '%'"></div>
                                                <span class="relative" x-text="row.clicks.toLocaleString()"></span>
                                            </td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.impressions.toLocaleString()"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.ctr + '%'"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.position"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <template x-if="total > 10">
                            <div class="mt-3 text-center">
                                <button x-show="limit < total" @click="limit = total" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show all <span x-text="total"></span> queries
                                </button>
                                <button x-show="limit >= total" @click="limit = 10" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show less
                                </button>
                            </div>
                        </template>
                    </x-ui.card>
                </div>
            @endif

            {{-- Tracked Keywords --}}
            @if(isset($trackedKeywords) && $trackedKeywords->count() > 0)
                <div class="mt-6">
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Tracked Keywords</h3>
                        <div class="space-y-3">
                            @foreach($trackedKeywords as $tracked)
                                <div class="rounded-lg border border-gray-200 p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-4 w-4 text-yellow-500" fill="currentColor" viewBox="0 0 24 24"><path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                            <span class="text-sm font-medium text-gray-900">{{ $tracked->keyword }}</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            @php
                                                $latest = $tracked->positions->first();
                                                $prev = $tracked->positions->skip(1)->first();
                                            @endphp
                                            @if($latest && $latest->position)
                                                <span class="text-sm font-semibold text-gray-700">Pos: {{ $latest->position }}</span>
                                                @if($prev && $prev->position)
                                                    @php $posDiff = round($prev->position - $latest->position, 1); @endphp
                                                    @if($posDiff != 0)
                                                        <span class="text-xs font-medium {{ $posDiff > 0 ? 'text-green-600' : 'text-red-600' }}">
                                                            {{ $posDiff > 0 ? '+' : '' }}{{ $posDiff }}
                                                        </span>
                                                    @endif
                                                @endif
                                            @else
                                                <span class="text-xs text-gray-400">No data yet</span>
                                            @endif
                                            <button wire:click="untrackKeyword({{ $tracked->id }})" class="text-gray-300 hover:text-red-500 transition" title="Remove from tracking">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    @if($tracked->positions->count() > 1)
                                        <x-charts.sparkline :data="$tracked->positions->sortBy('date')->pluck('position')->toArray()" color="#f59e0b" :height="28" />
                                        <div class="mt-1 flex justify-between text-[10px] text-gray-400">
                                            <span>{{ $tracked->positions->sortBy('date')->first()->date->format('M d') }}</span>
                                            <span>{{ $tracked->positions->sortByDesc('date')->first()->date->format('M d') }}</span>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                </div>
            @endif

            {{-- Top Pages (Alpine-driven: sort, search, percentage bars, CSV export) --}}
            @if(count($pages) > 0)
                <div class="mt-6" x-data="{
                    ...dataTableMixin(@js($pages), ['page']),
                    get maxClicks() { return Math.max(...this.rows.map(r => r.clicks), 1); },
                    exportCsv() {
                        const headers = ['Page','Clicks','Impressions','CTR','Position'];
                        const csv = [headers.join(','), ...this.filtered.map(r =>
                            [this.csvEscape(r.page), r.clicks, r.impressions, r.ctr + '%', r.position].join(',')
                        )].join('\n');
                        this.downloadCsv(csv, 'search-pages.csv');
                    },
                }">
                    <x-ui.card>
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-gray-900">Top Pages</h3>
                            <div class="flex items-center gap-2">
                                <input x-model="search" type="text" placeholder="Search pages..." class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500 w-48" />
                                <button @click="exportCsv()" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200 transition" title="Export CSV">
                                    <svg class="inline h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>CSV
                                </button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('page')">Page <span class="text-xs" x-text="sortIcon('page')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('clicks')">Clicks <span class="text-xs" x-text="sortIcon('clicks')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('impressions')">Impr. <span class="text-xs" x-text="sortIcon('impressions')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('ctr')">CTR <span class="text-xs" x-text="sortIcon('ctr')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('position')">Position <span class="text-xs" x-text="sortIcon('position')"></span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row, idx) in filtered" :key="idx">
                                        <tr x-show="idx < limit">
                                            <td class="py-2 max-w-xs truncate font-medium text-purple-700 cursor-pointer hover:underline" :title="row.page" x-text="row.page" @click="$wire.drillDown('page', row.page)"></td>
                                            <td class="py-2 text-right text-gray-600 relative">
                                                <div class="absolute inset-y-0 left-0 bg-purple-50 rounded-sm" :style="'width:' + (row.clicks / maxClicks * 100) + '%'"></div>
                                                <span class="relative" x-text="row.clicks.toLocaleString()"></span>
                                            </td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.impressions.toLocaleString()"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.ctr + '%'"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.position"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <template x-if="total > 10">
                            <div class="mt-3 text-center">
                                <button x-show="limit < total" @click="limit = total" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show all <span x-text="total"></span> pages
                                </button>
                                <button x-show="limit >= total" @click="limit = 10" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show less
                                </button>
                            </div>
                        </template>
                    </x-ui.card>
                </div>
            @endif

            {{-- Countries & Devices --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Countries (Alpine-driven: sort, search, CSV export) --}}
                <div x-data="{
                    ...dataTableMixin(@js($countries), ['country']),
                    exportCsv() {
                        const headers = ['Country','Clicks','Impressions'];
                        const csv = [headers.join(','), ...this.filtered.map(r =>
                            [this.csvEscape(r.country), r.clicks, r.impressions].join(',')
                        )].join('\n');
                        this.downloadCsv(csv, 'search-countries.csv');
                    },
                }">
                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <h3 class="text-base font-semibold text-gray-900">Countries</h3>
                        <div class="flex items-center gap-2">
                            <input x-model="search" type="text" placeholder="Search..." class="rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-purple-500 focus:ring-purple-500 w-32" />
                            <button @click="exportCsv()" class="rounded-lg bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 transition" title="Export CSV">CSV</button>
                        </div>
                    </div>
                    @if(count($countries) > 0)
                        <div class="mb-4">
                            <x-charts.bar-chart
                                :labels="collect($countries)->pluck('country')->take(10)->toArray()"
                                :data="collect($countries)->pluck('clicks')->take(10)->toArray()"
                                color="#8D5CF5"
                                height="200px"
                                :horizontal="true"
                            />
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('country')">Country <span class="text-xs" x-text="sortIcon('country')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('clicks')">Clicks <span class="text-xs" x-text="sortIcon('clicks')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('impressions')">Impr. <span class="text-xs" x-text="sortIcon('impressions')"></span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row, idx) in filtered" :key="idx">
                                        <tr x-show="idx < limit">
                                            <td class="py-2 font-medium text-gray-700" x-text="row.country"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.clicks.toLocaleString()"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.impressions.toLocaleString()"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <template x-if="total > 10">
                            <div class="mt-3 text-center">
                                <button x-show="limit < total" @click="limit = total" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show all <span x-text="total"></span> countries
                                </button>
                                <button x-show="limit >= total" @click="limit = 10" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show less
                                </button>
                            </div>
                        </template>
                    @else
                        <p class="text-sm text-gray-400">No country data available.</p>
                    @endif
                </x-ui.card>
                </div>

                {{-- Devices --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Devices</h3>
                    @if(count($devices) > 0)
                        <x-charts.donut-chart
                            :labels="collect($devices)->pluck('device')->toArray()"
                            :data="collect($devices)->pluck('clicks')->toArray()"
                            :colors="['#8D5CF5', '#06b6d4', '#f59e0b', '#9ca3af']"
                            height="250px"
                        />
                    @else
                        <p class="text-sm text-gray-400">No device data available.</p>
                    @endif
                </x-ui.card>
            </div>

            {{-- Search Appearance --}}
            @if(count($searchAppearance) > 0)
                <div class="mt-6">
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Search Appearance</h3>
                        <div class="mb-4">
                            <x-charts.bar-chart
                                :labels="collect($searchAppearance)->pluck('type')->toArray()"
                                :data="collect($searchAppearance)->pluck('clicks')->toArray()"
                                color="#06b6d4"
                                height="200px"
                                :horizontal="false"
                            />
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500">Type</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Clicks</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Impr.</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">CTR</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Position</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($searchAppearance as $appearance)
                                        <tr>
                                            <td class="py-2 font-medium text-gray-700">{{ $appearance['type'] }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($appearance['clicks']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($appearance['impressions']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ $appearance['ctr'] }}%</td>
                                            <td class="py-2 text-right text-gray-600">{{ $appearance['position'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                </div>
            @endif

            {{-- Sitemaps --}}
            @if(count($sitemaps) > 0)
                <div class="mt-6">
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Sitemaps</h3>
                        <div class="space-y-3">
                            @foreach($sitemaps as $sitemap)
                                <div class="rounded-lg border border-gray-200 p-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-gray-900 truncate max-w-md" title="{{ $sitemap['path'] }}">{{ basename($sitemap['path']) }}</span>
                                        <div class="flex items-center gap-2">
                                            @if($sitemap['errors'] > 0)
                                                <x-ui.badge variant="red">{{ $sitemap['errors'] }} errors</x-ui.badge>
                                            @elseif($sitemap['warnings'] > 0)
                                                <x-ui.badge variant="yellow">{{ $sitemap['warnings'] }} warnings</x-ui.badge>
                                            @elseif($sitemap['is_pending'])
                                                <x-ui.badge variant="yellow">Pending</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="green">OK</x-ui.badge>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $sitemap['path'] }}</div>
                                    @if(count($sitemap['contents'] ?? []) > 0)
                                        <div class="mt-2 flex flex-wrap gap-3 text-xs text-gray-500">
                                            @foreach($sitemap['contents'] as $content)
                                                <span>{{ ucfirst($content['type']) }}: {{ number_format($content['submitted']) }} submitted, {{ number_format($content['indexed']) }} indexed</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                </div>
            @endif

            {{-- URL Inspection --}}
            <div class="mt-6">
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">URL Inspection</h3>
                    <div class="flex gap-2">
                        <input
                            wire:model="inspectUrl"
                            wire:keydown.enter="inspectUrlAction"
                            type="url"
                            placeholder="Enter a URL to inspect..."
                            class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"
                        />
                        <x-ui.button wire:click="inspectUrlAction" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="inspectUrlAction">Inspect</span>
                            <span wire:loading wire:target="inspectUrlAction">Inspecting...</span>
                        </x-ui.button>
                    </div>

                    @if($urlInspectionResult)
                        <div class="mt-4 space-y-4">
                            {{-- Index Status --}}
                            <div class="rounded-lg border border-gray-200 p-4">
                                <h4 class="text-sm font-semibold text-gray-900 mb-3">Index Status</h4>
                                @php
                                    $idx = $urlInspectionResult['index_status'];
                                    $idxVerdict = $idx['verdict'] ?? 'VERDICT_UNSPECIFIED';
                                    $idxVariant = match($idxVerdict) {
                                        'PASS' => 'green',
                                        'PARTIAL' => 'yellow',
                                        'FAIL' => 'red',
                                        'NEUTRAL' => 'gray',
                                        default => 'gray',
                                    };
                                @endphp
                                <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                    <div>
                                        <span class="text-gray-500">Verdict:</span>
                                        <x-ui.badge :variant="$idxVariant">{{ $idxVerdict }}</x-ui.badge>
                                    </div>
                                    @if(!empty($idx['coverage_state']))
                                        <div><span class="text-gray-500">Coverage:</span> <span class="text-gray-700">{{ $idx['coverage_state'] }}</span></div>
                                    @endif
                                    @if(!empty($idx['crawled_as']))
                                        <div><span class="text-gray-500">Crawled as:</span> <span class="text-gray-700">{{ $idx['crawled_as'] }}</span></div>
                                    @endif
                                    @if(!empty($idx['last_crawl_time']))
                                        <div><span class="text-gray-500">Last crawl:</span> <span class="text-gray-700">{{ \Carbon\Carbon::parse($idx['last_crawl_time'])->diffForHumans() }}</span></div>
                                    @endif
                                    @if(!empty($idx['page_fetch_state']))
                                        <div><span class="text-gray-500">Page fetch:</span> <span class="text-gray-700">{{ $idx['page_fetch_state'] }}</span></div>
                                    @endif
                                    @if(!empty($idx['robots_txt_state']))
                                        <div><span class="text-gray-500">Robots.txt:</span> <span class="text-gray-700">{{ $idx['robots_txt_state'] }}</span></div>
                                    @endif
                                    @if(!empty($idx['indexing_state']))
                                        <div><span class="text-gray-500">Indexing state:</span> <span class="text-gray-700">{{ $idx['indexing_state'] }}</span></div>
                                    @endif
                                </div>
                            </div>

                            {{-- Mobile Usability --}}
                            <div class="rounded-lg border border-gray-200 p-4">
                                <h4 class="text-sm font-semibold text-gray-900 mb-3">Mobile Usability</h4>
                                @php
                                    $mobile = $urlInspectionResult['mobile_usability'];
                                    $mobVerdict = $mobile['verdict'] ?? 'VERDICT_UNSPECIFIED';
                                    $mobVariant = match($mobVerdict) {
                                        'PASS' => 'green',
                                        'FAIL' => 'red',
                                        default => 'gray',
                                    };
                                @endphp
                                <div class="mb-2">
                                    <span class="text-sm text-gray-500">Verdict:</span>
                                    <x-ui.badge :variant="$mobVariant">{{ $mobVerdict }}</x-ui.badge>
                                </div>
                                @if(count($mobile['issues'] ?? []) > 0)
                                    <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                                        @foreach($mobile['issues'] as $issue)
                                            <li>{{ $issue['message'] ?: $issue['issue_type'] }}</li>
                                        @endforeach
                                    </ul>
                                @elseif($mobVerdict === 'PASS')
                                    <p class="text-sm text-gray-500">No mobile usability issues found.</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            </div>

            {{-- Drill-Down Modal --}}
            <x-ui.modal name="sc-drilldown" maxWidth="2xl">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">
                        @if($drillDownType === 'query')
                            Pages ranking for: <span class="text-purple-600">{{ $drillDownLabel }}</span>
                        @else
                            Queries driving traffic to: <span class="text-purple-600 break-all text-sm">{{ $drillDownLabel }}</span>
                        @endif
                    </h3>
                    <p class="text-xs text-gray-400 mb-4">{{ $dateRange }} period</p>

                    @if(count($drillDownResults) > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500">{{ $drillDownType === 'query' ? 'Page' : 'Query' }}</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Clicks</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Impr.</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">CTR</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Position</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($drillDownResults as $result)
                                        <tr>
                                            <td class="py-2 font-medium text-gray-700 max-w-xs truncate" title="{{ $result['value'] }}">{{ $result['value'] }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($result['clicks']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($result['impressions']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ $result['ctr'] }}%</td>
                                            <td class="py-2 text-right text-gray-600">{{ $result['position'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No results found.</p>
                    @endif
                </div>
            </x-ui.modal>

            {{-- Disconnect link --}}
            <div class="mt-6 text-center">
                <button wire:click="disconnectSearchConsole" wire:confirm="Disconnect Search Console? Cached data will be removed." class="text-sm text-gray-400 hover:text-red-600 transition">
                    Disconnect Search Console
                </button>
            </div>
        @else
            {{-- Connected but no data yet --}}
            @if($connection->last_error)
                <x-ui.card>
                    <x-ui.empty-state
                        title="Failed to fetch Search Console data"
                        :description="$connection->last_error"
                        icon="alert-triangle"
                    >
                        <x-slot:action>
                            <x-ui.button wire:click="refreshData">Retry</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <x-ui.card>
                    <x-ui.empty-state
                        title="Fetching Search Console data"
                        description="Data is being fetched from Google Search Console. This may take a moment. Try refreshing the page."
                        icon="search"
                    />
                </x-ui.card>
            @endif
        @endif
    @else
        {{-- Not connected empty state --}}
        <x-ui.card>
            <x-ui.empty-state
                title="Google Search Console not connected"
                description="Connect a Search Console property to view search queries, impressions, clicks, and ranking data for this site."
                icon="search"
            >
                <x-slot:action>
                    <x-ui.button wire:click="connectSearchConsole">Connect Google Search Console</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @endif

    {{-- Property Picker --}}
    @if(count($availableProperties) > 0)
        <div class="mt-6">
            <x-ui.card>
                @if(count($googleConnections) > 0)
                    <p class="mb-3 text-sm text-gray-500">Connected as: {{ $googleConnections->first()->email }}</p>
                @endif
                <h3 class="text-base font-semibold text-gray-900 mb-3">Select Search Console Property</h3>
                <div x-data="{
                    search: '',
                    get filtered() {
                        if (!this.search) return @js($availableProperties).map((p, i) => ({...p, _index: i}));
                        const q = this.search.toLowerCase();
                        return @js($availableProperties).map((p, i) => ({...p, _index: i})).filter(p =>
                            p.site_url.toLowerCase().includes(q) ||
                            p.permission_level.toLowerCase().includes(q)
                        );
                    }
                }">
                    <input
                        x-model="search"
                        type="text"
                        placeholder="Search properties..."
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500 mb-3"
                    />
                    <div class="max-h-64 overflow-y-auto space-y-1">
                        <template x-for="property in filtered" :key="property._index">
                            <button
                                @click="$wire.selectProperty(property._index)"
                                class="w-full rounded-lg border border-gray-200 p-3 text-left hover:border-purple-300 hover:bg-purple-50 transition"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-900" x-text="property.site_url"></span>
                                    <span class="text-xs text-gray-400" x-text="property.site_url.startsWith('sc-domain:') ? 'Domain' : 'URL prefix'"></span>
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500">
                                    Permission: <span x-text="property.permission_level.charAt(0).toUpperCase() + property.permission_level.slice(1)"></span>
                                </div>
                            </button>
                        </template>
                        <p x-show="filtered.length === 0" class="text-sm text-gray-400 py-2">No properties match your search.</p>
                    </div>
                </div>
            </x-ui.card>
        </div>
    @endif
</div>
