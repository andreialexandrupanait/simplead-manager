<div>
    @if($connection && $connection->is_active)
        <div class="mb-6 flex justify-end">
            <x-ui.date-range-selector :selected="$dateRange" />
        </div>
    @endif

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />
    <x-ui.flash-alert type="info" key="analytics-refreshing" />

    @if($connection && $connection->is_active)
        {{-- Data subtitle --}}
        @if($cache)
            <p class="mb-6 text-xs text-gray-400">
                Data from {{ $cache->start_date->format('M d') }} &ndash; {{ $cache->end_date->format('M d, Y') }}
                &middot; Updated {{ $cache->fetched_at->diffForHumans() }}
            </p>
        @endif

        {{-- Real-Time Card --}}
        <div class="mb-6" x-data="{ loaded: @js($realtimeData !== null) }">
            <x-ui.card>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold text-gray-900">Real-Time</h3>
                    <x-ui.button size="sm" variant="secondary" wire:click="fetchRealtimeData">
                        <span wire:loading.remove wire:target="fetchRealtimeData">Refresh</span>
                        <span wire:loading wire:target="fetchRealtimeData">Loading...</span>
                    </x-ui.button>
                </div>
                @if($realtimeData)
                    <div wire:poll.30s="fetchRealtimeData">
                        <div class="text-center mb-4">
                            <div class="text-4xl font-bold text-purple-600">{{ number_format($realtimeData['active_users']) }}</div>
                            <div class="text-sm text-gray-500 mt-1">Active users right now</div>
                        </div>
                        @if(count($realtimeData['active_pages'] ?? []) > 0)
                            <div class="border-t border-gray-100 pt-3">
                                <h4 class="text-xs font-medium text-gray-500 mb-2">Top Active Pages</h4>
                                <div class="space-y-1">
                                    @foreach(array_slice($realtimeData['active_pages'], 0, 5) as $page)
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="truncate text-gray-700 max-w-[300px]" title="{{ $page['page'] }}">{{ $page['page'] }}</span>
                                            <span class="text-gray-500 font-medium">{{ $page['active_users'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <p class="text-xs text-gray-400 mt-3">Updated {{ \Carbon\Carbon::parse($realtimeData['fetched_at'])->diffForHumans() }}</p>
                    </div>
                @else
                    <p class="text-sm text-gray-400">Click refresh to load real-time data.</p>
                @endif
            </x-ui.card>
        </div>

        @if($overview)
            {{-- Insight Alerts --}}
            @if(count($insights) > 0)
                <div class="mb-6 space-y-2">
                    @foreach($insights as $insight)
                        <div class="rounded-lg border px-4 py-3 text-sm flex items-center gap-2 {{ $insight['type'] === 'good' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' }}">
                            @if($insight['type'] === 'good')
                                <svg class="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            @else
                                <svg class="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
                            @endif
                            <span><strong>{{ $insight['metric'] }}</strong> {{ $insight['direction'] === 'up' ? 'increased' : 'decreased' }} by {{ abs($insight['change']) }}% compared to previous period</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Overview metric cards with sparklines --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Users</div>
                        @if(isset($deltas['total_users']) && $deltas['total_users'] !== null)
                            <span class="text-xs font-medium {{ $deltas['total_users'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $deltas['total_users'] >= 0 ? '+' : '' }}{{ $deltas['total_users'] }}%</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['total_users']) }}</div>
                    @if(count($usersOverTime) > 1)
                        <x-charts.sparkline :data="collect($usersOverTime)->pluck('users')->toArray()" color="#8D5CF5" />
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">New Users</div>
                        @if(isset($deltas['new_users']) && $deltas['new_users'] !== null)
                            <span class="text-xs font-medium {{ $deltas['new_users'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $deltas['new_users'] >= 0 ? '+' : '' }}{{ $deltas['new_users'] }}%</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['new_users']) }}</div>
                    @if(count($usersOverTime) > 1)
                        <x-charts.sparkline :data="collect($usersOverTime)->pluck('new_users')->toArray()" color="#06b6d4" />
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Sessions</div>
                        @if(isset($deltas['sessions']) && $deltas['sessions'] !== null)
                            <span class="text-xs font-medium {{ $deltas['sessions'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $deltas['sessions'] >= 0 ? '+' : '' }}{{ $deltas['sessions'] }}%</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['sessions']) }}</div>
                    @if(count($usersOverTime) > 1)
                        <x-charts.sparkline :data="collect($usersOverTime)->pluck('sessions')->toArray()" color="#10b981" />
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Pageviews</div>
                        @if(isset($deltas['pageviews']) && $deltas['pageviews'] !== null)
                            <span class="text-xs font-medium {{ $deltas['pageviews'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $deltas['pageviews'] >= 0 ? '+' : '' }}{{ $deltas['pageviews'] }}%</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['pageviews']) }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Bounce Rate</div>
                        @if(isset($deltas['bounce_rate']) && $deltas['bounce_rate'] !== null)
                            {{-- Inverted: lower bounce = better = green --}}
                            <span class="text-xs font-medium {{ $deltas['bounce_rate'] <= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $deltas['bounce_rate'] >= 0 ? '+' : '' }}{{ $deltas['bounce_rate'] }}%</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['bounce_rate'] }}%</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Avg Time</div>
                        @if(isset($deltas['avg_session_duration']) && $deltas['avg_session_duration'] !== null)
                            <span class="text-xs font-medium {{ $deltas['avg_session_duration'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $deltas['avg_session_duration'] >= 0 ? '+' : '' }}{{ $deltas['avg_session_duration'] }}%</span>
                        @endif
                    </div>
                    @php
                        $mins = floor($overview['avg_session_duration'] / 60);
                        $secs = (int) ($overview['avg_session_duration'] % 60);
                    @endphp
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $mins }}m {{ $secs }}s</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Engagement</div>
                        @if(isset($deltas['engagement_rate']) && $deltas['engagement_rate'] !== null)
                            <span class="text-xs font-medium {{ $deltas['engagement_rate'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $deltas['engagement_rate'] >= 0 ? '+' : '' }}{{ $deltas['engagement_rate'] }}%</span>
                        @endif
                    </div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['engagement_rate'] }}%</div>
                </x-ui.card>
            </div>

            {{-- Users Over Time chart with Daily/Weekly toggle --}}
            @if(count($usersOverTime) > 0)
                <div class="mt-6" x-data="{
                    aggregation: 'daily',
                    annotations: @js($annotations),
                    rawLabels: @js(collect($usersOverTime)->pluck('date')->toArray()),
                    rawUsers: @js(collect($usersOverTime)->pluck('users')->toArray()),
                    rawNewUsers: @js(collect($usersOverTime)->pluck('new_users')->toArray()),
                    rawSessions: @js(collect($usersOverTime)->pluck('sessions')->toArray()),

                    get chartData() {
                        if (this.aggregation === 'weekly') return this.weeklyData();
                        return {
                            labels: this.rawLabels.map(d => this.fmtDate(d)),
                            users: this.rawUsers,
                            newUsers: this.rawNewUsers,
                            sessions: this.rawSessions,
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
                            if (!weeks[key]) weeks[key] = { users: 0, newUsers: 0, sessions: 0 };
                            weeks[key].users += this.rawUsers[i] || 0;
                            weeks[key].newUsers += this.rawNewUsers[i] || 0;
                            weeks[key].sessions += this.rawSessions[i] || 0;
                        });
                        const keys = Object.keys(weeks).sort();
                        return {
                            labels: keys.map(k => 'W' + k.split('-W')[1]),
                            users: keys.map(k => weeks[k].users),
                            newUsers: keys.map(k => weeks[k].newUsers),
                            sessions: keys.map(k => weeks[k].sessions),
                        };
                    },

                    chart: null,
                    init() { this.$nextTick(() => this.renderChart()); },
                    renderChart() {
                        if (this.chart) this.chart.destroy();
                        const d = this.chartData;
                        const datasets = [
                            { label: 'Users', data: d.users, borderColor: '#8D5CF5', backgroundColor: '#8D5CF51A', borderWidth: 2, fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5 },
                            { label: 'New Users', data: d.newUsers, borderColor: '#06b6d4', backgroundColor: '#06b6d41A', borderWidth: 2, fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5 },
                            { label: 'Sessions', data: d.sessions, borderColor: '#10b981', backgroundColor: '#10b9811A', borderWidth: 2, fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5 },
                        ];

                        // Add annotation markers (only in daily mode)
                        if (this.aggregation === 'daily' && this.annotations && this.annotations.length > 0) {
                            let annData = [];
                            this.annotations.forEach(ann => {
                                let idx = d.labels.indexOf(ann.date);
                                if (idx !== -1) {
                                    annData.push({ x: idx, y: Math.max(...d.users.filter(v => v > 0), 10), label: ann.label, type: ann.type });
                                }
                            });
                            if (annData.length > 0) {
                                datasets.push({
                                    label: 'Events', data: annData.map(a => ({ x: a.x, y: a.y })), type: 'scatter',
                                    pointStyle: 'triangle', pointRadius: 8, pointHoverRadius: 10,
                                    backgroundColor: annData.map(a => a.type === 'success' ? '#10B981' : '#EF4444'),
                                    borderColor: annData.map(a => a.type === 'success' ? '#10B981' : '#EF4444'),
                                    showLine: false, _annotationLabels: annData.map(a => a.label),
                                });
                            }
                        }

                        this.chart = new Chart(this.$refs.usersCanvas, {
                            type: 'line',
                            data: { labels: d.labels, datasets },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: true, position: 'bottom', labels: { usePointStyle: true, padding: 16, filter: item => item.text !== 'Events' } },
                                    tooltip: { callbacks: { label(ctx) {
                                        if (ctx.dataset._annotationLabels) return ctx.dataset._annotationLabels[ctx.dataIndex] || '';
                                        return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString();
                                    } } },
                                },
                                scales: {
                                    x: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280' } },
                                    y: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280' }, beginAtZero: true },
                                },
                            },
                        });
                    },
                }">
                    <x-ui.card>
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900">Users Over Time</h3>
                            <div class="flex gap-1">
                                <button @click="aggregation = 'daily'; renderChart()" :class="aggregation === 'daily' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'" class="rounded-lg px-3 py-1 text-xs font-medium transition">Daily</button>
                                <button @click="aggregation = 'weekly'; renderChart()" :class="aggregation === 'weekly' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'" class="rounded-lg px-3 py-1 text-xs font-medium transition">Weekly</button>
                            </div>
                        </div>
                        <div style="height: 300px" class="relative">
                            <canvas x-ref="usersCanvas"></canvas>
                        </div>
                    </x-ui.card>
                </div>
            @endif

            {{-- Traffic Sources & Top Pages --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Traffic Sources --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Traffic Sources</h3>
                    @if(count($trafficSources) > 0)
                        <div class="mb-4">
                            <x-charts.donut-chart
                                :labels="collect($trafficSources)->pluck('channel')->toArray()"
                                :data="collect($trafficSources)->pluck('sessions')->toArray()"
                                :colors="['#8D5CF5', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#9ca3af']"
                                height="220px"
                            />
                        </div>
                        <div class="space-y-3">
                            @foreach($trafficSources as $source)
                                <div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-medium text-gray-700">{{ $source['channel'] }}</span>
                                        <span class="text-gray-500">{{ number_format($source['sessions']) }} ({{ $source['percentage'] }}%)</span>
                                    </div>
                                    <div class="mt-1 h-2 w-full rounded-full bg-gray-100">
                                        <div class="h-2 rounded-full bg-purple-500" style="width: {{ $source['percentage'] }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No traffic source data available.</p>
                    @endif
                </x-ui.card>

                {{-- Top Pages --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Top Pages</h3>
                    @if(count($topPages) > 0)
                        <div class="space-y-2">
                            @foreach($topPages as $page)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="truncate font-medium text-gray-700 max-w-[200px]" title="{{ $page['path'] }}">{{ $page['path'] }}</span>
                                    <span class="text-gray-500">{{ number_format($page['pageviews']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No page data available.</p>
                    @endif
                </x-ui.card>
            </div>

            {{-- Devices & Countries --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Devices --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Devices</h3>
                    @if(count($devices) > 0)
                        <x-charts.donut-chart
                            :labels="collect($devices)->pluck('device')->toArray()"
                            :data="collect($devices)->pluck('sessions')->toArray()"
                            :colors="['#8D5CF5', '#06b6d4', '#f59e0b', '#9ca3af']"
                            height="250px"
                        />
                    @else
                        <p class="text-sm text-gray-400">No device data available.</p>
                    @endif
                </x-ui.card>

                {{-- Countries --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Countries</h3>
                    @if(count($countries) > 0)
                        <div class="mb-4">
                            <x-charts.bar-chart
                                :labels="collect($countries)->pluck('country')->take(10)->toArray()"
                                :data="collect($countries)->pluck('users')->take(10)->toArray()"
                                color="#8D5CF5"
                                height="200px"
                                :horizontal="true"
                            />
                        </div>
                        <div x-data="{ limit: 10, total: {{ count($countries) }} }">
                            <div class="space-y-2">
                                @foreach($countries as $i => $country)
                                    <div class="flex items-center justify-between text-sm" x-show="{{ $i }} < limit">
                                        <span class="font-medium text-gray-700">{{ $country['country'] }}</span>
                                        <div class="flex items-center gap-3 text-gray-500">
                                            <span>{{ number_format($country['users']) }} users</span>
                                            <span>{{ number_format($country['sessions']) }} sessions</span>
                                        </div>
                                    </div>
                                @endforeach
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
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No country data available.</p>
                    @endif

                    {{-- Cities subsection --}}
                    @if(count($cities) > 0)
                        <div x-data="{ limit: 10, total: {{ count($cities) }} }">
                            <h4 class="mt-6 mb-3 text-sm font-semibold text-gray-700">Top Cities</h4>
                            <div class="space-y-2">
                                @foreach($cities as $i => $city)
                                    <div class="flex items-center justify-between text-sm" x-show="{{ $i }} < limit">
                                        <span class="font-medium text-gray-700">{{ $city['city'] }} <span class="text-xs text-gray-400">{{ $city['country'] }}</span></span>
                                        <span class="text-gray-500">{{ number_format($city['users']) }} users</span>
                                    </div>
                                @endforeach
                            </div>
                            <template x-if="total > 10">
                                <div class="mt-3 text-center">
                                    <button x-show="limit < total" @click="limit = total" class="text-sm text-purple-600 hover:text-purple-800">
                                        Show all <span x-text="total"></span> cities
                                    </button>
                                    <button x-show="limit >= total" @click="limit = 10" class="text-sm text-purple-600 hover:text-purple-800">
                                        Show less
                                    </button>
                                </div>
                            </template>
                        </div>
                    @endif
                </x-ui.card>
            </div>

            {{-- Referral Sources (Alpine-driven: sort, search, CSV export) --}}
            @if(count($referralSources) > 0)
                <div class="mt-6" x-data="{
                    rows: @js($referralSources),
                    limit: 10, search: '', sortCol: null, sortDir: 'desc',
                    get sorted() {
                        if (!this.sortCol) return this.rows;
                        const col = this.sortCol; const dir = this.sortDir;
                        return [...this.rows].sort((a, b) => {
                            let av = a[col], bv = b[col];
                            if (typeof av === 'string') { av = av.toLowerCase(); bv = bv.toLowerCase(); }
                            if (av < bv) return dir === 'asc' ? -1 : 1;
                            if (av > bv) return dir === 'asc' ? 1 : -1;
                            return 0;
                        });
                    },
                    get filtered() {
                        if (!this.search) return this.sorted;
                        const q = this.search.toLowerCase();
                        return this.sorted.filter(r => (r.source + ' ' + r.medium).toLowerCase().includes(q));
                    },
                    get total() { return this.filtered.length; },
                    toggleSort(col) {
                        if (this.sortCol === col) { this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'; }
                        else { this.sortCol = col; this.sortDir = 'desc'; }
                    },
                    sortIcon(col) {
                        if (this.sortCol !== col) return '↕';
                        return this.sortDir === 'asc' ? '↑' : '↓';
                    },
                    exportCsv() {
                        const headers = ['Source','Medium','Sessions','Users','Bounce Rate','Percentage'];
                        const csv = [headers.join(','), ...this.filtered.map(r =>
                            [this.csvEscape(r.source), this.csvEscape(r.medium), r.sessions, r.users, r.bounce_rate + '%', r.percentage + '%'].join(',')
                        )].join('\n');
                        this.downloadCsv(csv, 'referral-sources.csv');
                    },
                    csvEscape(v) { return '\"' + String(v).replace(/\"/g, '\"\"') + '\"'; },
                    downloadCsv(csv, name) {
                        const blob = new Blob([csv], { type: 'text/csv' });
                        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
                        a.download = name; a.click(); URL.revokeObjectURL(a.href);
                    },
                }">
                    <x-ui.card>
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-gray-900">Referral Sources</h3>
                            <div class="flex items-center gap-2">
                                <input x-model="search" type="text" placeholder="Search sources..." class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500 w-48" />
                                <button @click="exportCsv()" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200 transition" title="Export CSV">
                                    <svg class="inline h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>CSV
                                </button>
                            </div>
                        </div>
                        <div class="mb-4">
                            <x-charts.bar-chart
                                :labels="collect($referralSources)->map(fn($r) => $r['source'])->take(10)->toArray()"
                                :data="collect($referralSources)->pluck('sessions')->take(10)->toArray()"
                                color="#06b6d4"
                                height="200px"
                                :horizontal="false"
                            />
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('source')">Source / Medium <span class="text-xs" x-text="sortIcon('source')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('sessions')">Sessions <span class="text-xs" x-text="sortIcon('sessions')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('users')">Users <span class="text-xs" x-text="sortIcon('users')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('bounce_rate')">Bounce% <span class="text-xs" x-text="sortIcon('bounce_rate')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('percentage')">% <span class="text-xs" x-text="sortIcon('percentage')"></span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row, idx) in filtered" :key="idx">
                                        <tr x-show="idx < limit">
                                            <td class="py-2 font-medium text-gray-700" x-text="row.source + ' / ' + row.medium"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.sessions.toLocaleString()"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.users.toLocaleString()"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.bounce_rate + '%'"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.percentage + '%'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <template x-if="total > 10">
                            <div class="mt-3 text-center">
                                <button x-show="limit < total" @click="limit = total" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show all <span x-text="total"></span> sources
                                </button>
                                <button x-show="limit >= total" @click="limit = 10" class="text-sm text-purple-600 hover:text-purple-800">
                                    Show less
                                </button>
                            </div>
                        </template>
                    </x-ui.card>
                </div>
            @endif

            {{-- Landing Pages (Alpine-driven: sort, search, CSV export) --}}
            @if(count($landingPages) > 0)
                <div class="mt-6" x-data="{
                    rows: @js($landingPages),
                    limit: 10, search: '', sortCol: null, sortDir: 'desc',
                    get sorted() {
                        if (!this.sortCol) return this.rows;
                        const col = this.sortCol; const dir = this.sortDir;
                        return [...this.rows].sort((a, b) => {
                            let av = a[col], bv = b[col];
                            if (typeof av === 'string') { av = av.toLowerCase(); bv = bv.toLowerCase(); }
                            if (av < bv) return dir === 'asc' ? -1 : 1;
                            if (av > bv) return dir === 'asc' ? 1 : -1;
                            return 0;
                        });
                    },
                    get filtered() {
                        if (!this.search) return this.sorted;
                        const q = this.search.toLowerCase();
                        return this.sorted.filter(r => r.page.toLowerCase().includes(q));
                    },
                    get total() { return this.filtered.length; },
                    toggleSort(col) {
                        if (this.sortCol === col) { this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'; }
                        else { this.sortCol = col; this.sortDir = 'desc'; }
                    },
                    sortIcon(col) {
                        if (this.sortCol !== col) return '↕';
                        return this.sortDir === 'asc' ? '↑' : '↓';
                    },
                    fmtDuration(s) {
                        const m = Math.floor(s / 60); const sec = Math.round(s % 60);
                        return m + 'm ' + sec + 's';
                    },
                    exportCsv() {
                        const headers = ['Page','Sessions','Bounce Rate','Engagement Rate','Avg Duration'];
                        const csv = [headers.join(','), ...this.filtered.map(r =>
                            [this.csvEscape(r.page), r.sessions, r.bounce_rate + '%', r.engagement_rate + '%', this.fmtDuration(r.avg_duration)].join(',')
                        )].join('\n');
                        this.downloadCsv(csv, 'landing-pages.csv');
                    },
                    csvEscape(v) { return '\"' + String(v).replace(/\"/g, '\"\"') + '\"'; },
                    downloadCsv(csv, name) {
                        const blob = new Blob([csv], { type: 'text/csv' });
                        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
                        a.download = name; a.click(); URL.revokeObjectURL(a.href);
                    },
                }">
                    <x-ui.card>
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-gray-900">Landing Pages</h3>
                            <div class="flex items-center gap-2">
                                <input x-model="search" type="text" placeholder="Search pages..." class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500 w-48" />
                                <button @click="exportCsv()" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200 transition" title="Export CSV">
                                    <svg class="inline h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>CSV
                                </button>
                            </div>
                        </div>
                        <div class="mb-4">
                            <x-charts.bar-chart
                                :labels="collect($landingPages)->pluck('page')->take(10)->toArray()"
                                :data="collect($landingPages)->pluck('sessions')->take(10)->toArray()"
                                color="#8D5CF5"
                                height="200px"
                                :horizontal="true"
                            />
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('page')">Page <span class="text-xs" x-text="sortIcon('page')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('sessions')">Sessions <span class="text-xs" x-text="sortIcon('sessions')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('bounce_rate')">Bounce% <span class="text-xs" x-text="sortIcon('bounce_rate')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('engagement_rate')">Engage% <span class="text-xs" x-text="sortIcon('engagement_rate')"></span></th>
                                        <th class="pb-2 text-right font-medium text-gray-500 cursor-pointer select-none" @click="toggleSort('avg_duration')">Avg Time <span class="text-xs" x-text="sortIcon('avg_duration')"></span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row, idx) in filtered" :key="idx">
                                        <tr x-show="idx < limit">
                                            <td class="py-2 max-w-xs truncate font-medium text-gray-700" :title="row.page" x-text="row.page"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.sessions.toLocaleString()"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.bounce_rate + '%'"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="row.engagement_rate + '%'"></td>
                                            <td class="py-2 text-right text-gray-600" x-text="fmtDuration(row.avg_duration)"></td>
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

            {{-- Demographics --}}
            @if(!empty($demographics))
                <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {{-- Age --}}
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Age</h3>
                        @if(count($demographics['age'] ?? []) > 0)
                            <x-charts.bar-chart
                                :labels="collect($demographics['age'])->pluck('bracket')->toArray()"
                                :data="collect($demographics['age'])->pluck('users')->toArray()"
                                color="#8D5CF5"
                                height="250px"
                                :horizontal="true"
                            />
                        @else
                            <p class="text-sm text-gray-400">Age data not available. Enable Google Signals in your GA4 property.</p>
                        @endif
                    </x-ui.card>

                    {{-- Gender --}}
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Gender</h3>
                        @if(count($demographics['gender'] ?? []) > 0)
                            <x-charts.donut-chart
                                :labels="collect($demographics['gender'])->pluck('gender')->toArray()"
                                :data="collect($demographics['gender'])->pluck('users')->toArray()"
                                :colors="['#8D5CF5', '#06b6d4', '#f59e0b', '#9ca3af']"
                                height="250px"
                            />
                        @else
                            <p class="text-sm text-gray-400">Gender data not available. Enable Google Signals in your GA4 property.</p>
                        @endif
                    </x-ui.card>
                </div>
            @endif

            {{-- Disconnect link --}}
            <div class="mt-6 text-center">
                <button wire:click="disconnectAnalytics" wire:confirm="Disconnect Google Analytics? Cached data will be removed." class="text-sm text-gray-400 hover:text-red-600 transition">
                    Disconnect Analytics
                </button>
            </div>
        @else
            {{-- Connected but no data yet --}}
            <x-ui.card>
                <x-ui.empty-state
                    title="Fetching analytics data"
                    description="Data is being fetched from Google Analytics. This may take a moment. Try refreshing the page."
                    icon="bar-chart-2"
                />
            </x-ui.card>
        @endif
    @else
        {{-- Not connected empty state --}}
        <x-ui.card>
            <x-ui.empty-state
                title="Google Analytics not connected"
                description="Connect a Google Analytics property to view traffic, engagement, and audience data for this site."
                icon="bar-chart-2"
            >
                <x-slot:action>
                    <x-ui.button wire:click="connectAnalytics">Connect Google Analytics</x-ui.button>
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
                <h3 class="text-base font-semibold text-gray-900 mb-3">Select GA4 Property</h3>
                <div x-data="{
                    search: '',
                    get filtered() {
                        if (!this.search) return @js($availableProperties).map((p, i) => ({...p, _index: i}));
                        const q = this.search.toLowerCase();
                        return @js($availableProperties).map((p, i) => ({...p, _index: i})).filter(p =>
                            p.property_name.toLowerCase().includes(q) ||
                            p.account_name.toLowerCase().includes(q) ||
                            p.property_id.toLowerCase().includes(q)
                        );
                    }
                }">
                    <x-ui.input
                        x-model="search"
                        type="text"
                        placeholder="Search properties..."
                        class="mb-3"
                    />
                    <div class="max-h-64 overflow-y-auto space-y-1">
                        <template x-for="property in filtered" :key="property._index">
                            <button
                                @click="$wire.selectProperty(property._index)"
                                class="w-full rounded-lg border border-gray-200 p-3 text-left hover:border-purple-300 hover:bg-purple-50 transition"
                            >
                                <div class="text-sm font-medium text-gray-900" x-text="property.property_name"></div>
                                <div class="mt-0.5 text-xs text-gray-500">
                                    <span x-text="property.property_id"></span> &middot; <span x-text="property.account_name"></span>
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
