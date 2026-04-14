<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    <x-scripts.data-table />
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
        @php $status = $this->googleConnectionStatus; @endphp

        {{-- Google connection broken --}}
        @if($status && !$status['google_active'])
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icons.alert-triangle class="h-5 w-5 text-red-500" />
                        <div>
                            <p class="text-sm font-medium text-red-800">{{ __('Google account needs to be reconnected') }}</p>
                            <p class="text-xs text-red-600">{{ __('The access token for :account has expired or been revoked.', ['account' => $status['email'] ?? __('the connected account')]) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ui.button wire:click="reconnectGoogle" variant="primary" size="sm">{{ __('Reconnect') }}</x-ui.button>
                    </div>
                </div>
            </div>
        @else
            {{-- Connected info bar --}}
            <div class="mb-4 flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5">
                <div class="flex items-center gap-4 text-sm text-gray-600">
                    <span>{{ __('Property') }}: <strong class="text-gray-900">{{ $status['property'] ?? '—' }}</strong></span>
                    <span>{{ __('Account') }}: <strong class="text-gray-900">{{ $status['email'] ?? '—' }}</strong></span>
                    @if($status['last_sync'] ?? null)
                        <span>{{ __('Last sync') }}: {{ $status['last_sync']->diffForHumans() }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="changeProperty" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">{{ __('Change Property') }}</button>
                    <span class="text-gray-300">|</span>
                    <button wire:click="disconnectSearchConsole" wire:confirm="{{ __('Disconnect Search Console? Cached data will be removed.') }}" class="text-xs font-medium text-gray-400 hover:text-red-600">{{ __('Disconnect') }}</button>
                </div>
            </div>

            {{-- Last error display --}}
            @if($status && ($status['last_error'] ?? null))
                <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-icons.alert-triangle class="h-4 w-4 text-yellow-600" />
                            <p class="text-sm text-yellow-800">{{ $status['last_error'] }}</p>
                        </div>
                        <x-ui.button wire:click="refreshData" variant="secondary" size="sm">
                            <span wire:loading.remove wire:target="refreshData">Retry</span>
                            <span wire:loading wire:target="refreshData">Retrying...</span>
                        </x-ui.button>
                    </div>
                </div>
            @endif

            @if($cache)
                <p class="mb-6 text-xs text-gray-400">
                    Data from {{ $cache->start_date->format('M d') }} &ndash; {{ $cache->end_date->format('M d, Y') }}
                    &middot; Updated {{ $cache->fetched_at->diffForHumans() }}
                </p>
            @endif

            @if($overview)
            {{-- Metric Cards + Performance Chart wrapped in one Alpine scope for toggle + aggregation --}}
            <div wire:key="sc-chart-{{ $dateRange }}-{{ $customStart }}-{{ $customEnd }}" @search-console-data-updated.window="updateData($event.detail)" x-data="{
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

                {{-- Live update from Livewire after sync --}}
                updateData(detail) {
                    const d = detail[0] || detail;
                    this.rawLabels = d.labels || [];
                    this.rawClicks = d.clicks || [];
                    this.rawImpressions = d.impressions || [];
                    this.rawCtr = d.ctr || [];
                    this.rawPosition = d.position || [];
                    this.$nextTick(() => this.renderChart());
                },

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
                async renderChart() {
                    const Chart = await window.loadChart();
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
                                x: { grid: { color: document.documentElement.classList.contains('dark') ? '#374151' : '#f3f4f6' }, ticks: { color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280' } },
                                y: { display: hasY, type: 'linear', position: 'left', grid: { color: document.documentElement.classList.contains('dark') ? '#374151' : '#f3f4f6' }, ticks: { color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280' }, beginAtZero: true, title: { display: true, text: 'Clicks / Impressions', color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280' } },
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
                            <div :class="activeMetrics.clicks ? 'border-b-2 border-accent-500 pb-2' : 'pb-2'">
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
                                <h3 class="text-base font-semibold text-gray-900">{{ __('Performance Over Time') }}</h3>
                                <div class="flex gap-1">
                                    <button @click="aggregation = 'daily'; renderChart()" :class="aggregation === 'daily' ? 'bg-accent-100 text-accent-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'" class="rounded-lg px-3 py-1 text-xs font-medium transition">Daily</button>
                                    <button @click="aggregation = 'weekly'; renderChart()" :class="aggregation === 'weekly' ? 'bg-accent-100 text-accent-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'" class="rounded-lg px-3 py-1 text-xs font-medium transition">Weekly</button>
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
                            <h3 class="text-base font-semibold text-gray-900">{{ __('Top Search Queries') }}</h3>
                            <div class="flex items-center gap-2">
                                <input x-model="search" type="text" placeholder="Search queries..." class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500 w-48" />
                                <button @click="exportCsv()" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200 transition" title="Export CSV">
                                    <svg aria-hidden="true" class="inline h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>CSV
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
                                            <td class="py-2 font-medium text-gray-700" x-text="row.query"></td>
                                            <td class="py-2 text-right text-gray-600 relative">
                                                <div class="absolute inset-y-0 left-0 bg-accent-50 rounded-sm" :style="'width:' + (row.clicks / maxClicks * 100) + '%'"></div>
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
                                <button x-show="limit < total" @click="limit = total" class="text-sm text-accent-600 hover:text-accent-800">
                                    Show all <span x-text="total"></span> queries
                                </button>
                                <button x-show="limit >= total" @click="limit = 10" class="text-sm text-accent-600 hover:text-accent-800">
                                    Show less
                                </button>
                            </div>
                        </template>
                    </x-ui.card>
                </div>
            @endif

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
                                <x-ui.button wire:click="refreshData">
                                    <span wire:loading.remove wire:target="refreshData">Retry</span>
                                    <span wire:loading wire:target="refreshData">Retrying...</span>
                                </x-ui.button>
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
        @endif {{-- end google_active check --}}
    @else
        {{-- Not connected — show appropriate guidance --}}
        <x-ui.card>
            @if(!$this->hasGoogleCredentials)
                <x-ui.empty-state
                    title="Google API credentials not configured"
                    description="Set up Google OAuth credentials in Settings > Integrations before connecting Search Console."
                    icon="search"
                >
                    <x-slot:action>
                        <x-ui.button :href="route('settings.integrations')" variant="primary">Go to Integrations</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @elseif(!$this->hasGoogleAccounts)
                <x-ui.empty-state
                    title="No Google account connected"
                    description="Connect a Google account first, then select a Search Console property for this site."
                    icon="search"
                >
                    <x-slot:action>
                        <x-ui.button wire:click="reconnectGoogle" variant="primary">
                            <span wire:loading.remove wire:target="reconnectGoogle">Connect Google Account</span>
                            <span wire:loading wire:target="reconnectGoogle">Redirecting...</span>
                        </x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state
                    title="Google Search Console not connected"
                    description="Select a Search Console property to view search queries, impressions, clicks, and ranking data."
                    icon="search"
                >
                    <x-slot:action>
                        <x-ui.button wire:click="connectSearchConsole" variant="primary">
                            <span wire:loading.remove wire:target="connectSearchConsole">Select Property</span>
                            <span wire:loading wire:target="connectSearchConsole">Loading properties...</span>
                        </x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @endif
        </x-ui.card>
    @endif

    {{-- Property Picker --}}
    @if(count($availableProperties) > 0)
        <div class="mt-6">
            <x-ui.card>
                @if(count($googleConnections) > 0)
                    <p class="mb-3 text-sm text-gray-500">Connected as: {{ $googleConnections->first()->email }}</p>
                @endif
                <h3 class="text-base font-semibold text-gray-900 mb-3">{{ __('Select Search Console Property') }}</h3>
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
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-accent-500 focus:ring-accent-500 mb-3"
                    />
                    <div class="max-h-64 overflow-y-auto space-y-1">
                        <template x-for="property in filtered" :key="property._index">
                            <button
                                @click="$wire.selectProperty(property._index)"
                                class="w-full rounded-lg border border-gray-200 p-3 text-left hover:border-accent-300 hover:bg-accent-50 transition"
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
                        <p x-show="filtered.length === 0" class="text-sm text-gray-400 py-2">{{ __('No properties match your search.') }}</p>
                    </div>
                </div>
            </x-ui.card>
        </div>
    @endif
</div>
