<div>
    <x-scripts.data-table />

    @if($connection && $connection->is_active)
        <div class="mb-6 flex justify-end">
            <x-ui.date-range-selector :selected="$dateRange" />
        </div>
    @endif

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />
    <x-ui.flash-alert type="info" key="analytics-refreshing" />

    @if($connection && $connection->is_active)
        @php $status = $this->googleConnectionStatus; @endphp

        {{-- Google connection broken --}}
        @if($status && !$status['google_active'])
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icons.alert-triangle class="h-5 w-5 text-red-500" />
                        <div>
                            <p class="text-sm font-medium text-red-800">Google account needs to be reconnected</p>
                            <p class="text-xs text-red-600">The access token for {{ $status['email'] ?? 'the connected account' }} has expired or been revoked.</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ui.button wire:click="reconnectGoogle" variant="primary" size="sm">Reconnect</x-ui.button>
                    </div>
                </div>
            </div>
        @else
            {{-- Connected info bar --}}
            <div class="mb-4 flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5">
                <div class="flex items-center gap-4 text-sm text-gray-600">
                    <span>Property: <strong class="text-gray-900">{{ $status['property'] ?? '—' }}</strong></span>
                    <span>Account: <strong class="text-gray-900">{{ $status['email'] ?? '—' }}</strong></span>
                    @if($status['last_sync'] ?? null)
                        <span>Last sync: {{ $status['last_sync']->diffForHumans() }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="changeProperty" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">Change Property</button>
                    <span class="text-gray-300">|</span>
                    <button wire:click="disconnectAnalytics" wire:confirm="Disconnect Google Analytics? Cached data will be removed." class="text-xs font-medium text-gray-400 hover:text-red-600">Disconnect</button>
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

            {{-- Data subtitle --}}
            @if($cache)
                <p class="mb-6 text-xs text-gray-400">
                    Data from {{ $cache->start_date->format('M d') }} &ndash; {{ $cache->end_date->format('M d, Y') }}
                    &middot; Updated {{ $cache->fetched_at->diffForHumans() }}
                </p>
            @endif

            {{-- Trend Analysis & Anomalies --}}
            @if($this->trendAnalysis)
                @php $ta = $this->trendAnalysis; @endphp

                {{-- Anomaly Alerts --}}
                @if(!empty($ta['anomalies']))
                    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <h4 class="text-sm font-semibold text-amber-800 mb-1">Traffic Anomalies Detected</h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach($ta['anomalies'] as $anomaly)
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $anomaly['direction'] === 'spike' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $anomaly['direction'] === 'spike' ? '+' : '' }}{{ $anomaly['value'] }} users on {{ \Carbon\Carbon::parse($anomaly['date'])->format('M j') }}
                                    ({{ $anomaly['z_score'] }}&sigma;)
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Week-over-Week Trends --}}
                @if(!empty($ta['trends']))
                    <div class="mb-4 grid grid-cols-4 gap-3">
                        @foreach(['pageviews' => 'Pageviews', 'total_users' => 'Users', 'sessions' => 'Sessions', 'bounce_rate' => 'Bounce Rate'] as $key => $label)
                            @php $t = $ta['trends'][$key] ?? null; @endphp
                            @if($t && $t['change_percent'] !== null)
                                <div class="rounded-lg border border-gray-200 px-3 py-2">
                                    <p class="text-xs text-gray-500">{{ $label }} vs prev week</p>
                                    <p class="text-sm font-semibold {{ ($key === 'bounce_rate' ? $t['change_percent'] < 0 : $t['change_percent'] > 0) ? 'text-green-600' : ($t['change_percent'] == 0 ? 'text-gray-500' : 'text-red-600') }}">
                                        {{ $t['change_percent'] > 0 ? '+' : '' }}{{ $t['change_percent'] }}%
                                    </p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            @endif

            @if($overview)
            {{-- Overview metric cards --}}
            <div class="grid grid-cols-3 gap-4">
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Users</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($overview['total_users']) }}</div>
                    @if(count($usersOverTime) > 1)
                        <x-charts.sparkline :data="collect($usersOverTime)->pluck('users')->toArray()" color="#8D5CF5" />
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Sessions</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($overview['sessions']) }}</div>
                    @if(count($usersOverTime) > 1)
                        <x-charts.sparkline :data="collect($usersOverTime)->pluck('sessions')->toArray()" color="#10b981" />
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Pageviews</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($overview['pageviews']) }}</div>
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

            @else
                {{-- Connected but no data yet --}}
                @if($connection->last_error)
                    <x-ui.card>
                        <x-ui.empty-state
                            title="Failed to fetch Analytics data"
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
                            title="Fetching analytics data"
                            description="Data is being fetched from Google Analytics. This may take a moment. Try refreshing the page."
                            icon="bar-chart-2"
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
                    description="Set up Google OAuth credentials in Settings > Integrations before connecting Analytics."
                    icon="bar-chart-2"
                >
                    <x-slot:action>
                        <x-ui.button :href="route('settings.integrations')" variant="primary">Go to Integrations</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @elseif(!$this->hasGoogleAccounts)
                <x-ui.empty-state
                    title="No Google account connected"
                    description="Connect a Google account first, then select an Analytics property for this site."
                    icon="bar-chart-2"
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
                    title="Google Analytics not connected"
                    description="Select a Google Analytics property to view traffic, engagement, and audience data."
                    icon="bar-chart-2"
                >
                    <x-slot:action>
                        <x-ui.button wire:click="connectAnalytics" variant="primary">
                            <span wire:loading.remove wire:target="connectAnalytics">Select Property</span>
                            <span wire:loading wire:target="connectAnalytics">Loading properties...</span>
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
                @if(count($googleConnections) > 1)
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Google Account</label>
                        <select wire:change="switchGoogleAccount($event.target.value)" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            @foreach($googleConnections as $gc)
                                <option value="{{ $gc->id }}" @selected($gc->id === ($selectedGoogleConnectionId ?? $googleConnections->first()->id))>{{ $gc->email }}</option>
                            @endforeach
                        </select>
                    </div>
                @elseif(count($googleConnections) === 1)
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
