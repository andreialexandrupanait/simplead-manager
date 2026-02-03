<div>
    @if($connection && $connection->is_active)
        <div class="mb-6 flex justify-end">
            <div class="flex items-center gap-2">
                @foreach(['7d' => '7d', '28d' => '28d', '90d' => '90d'] as $value => $label)
                    <button
                        wire:click="setDateRange('{{ $value }}')"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                            {{ $dateRange === $value
                                ? 'bg-purple-100 text-purple-700'
                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach

                <button
                    wire:click="refreshData"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-gray-100 p-1.5 text-gray-600 hover:bg-gray-200 transition"
                    title="Refresh data"
                >
                    <svg class="h-4 w-4" wire:loading.class="animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </button>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif
    @if(session('gsc-refreshing'))
        <div class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">Data is being refreshed in the background. Reload in a moment.</div>
    @endif

    @if($connection && $connection->is_active)
        @if($cache)
            <p class="mb-6 text-xs text-gray-400">
                Data from {{ $cache->start_date->format('M d') }} &ndash; {{ $cache->end_date->format('M d, Y') }}
                &middot; Updated {{ $cache->fetched_at->diffForHumans() }}
            </p>
        @endif

        @if($overview)
            {{-- Overview metric cards --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Clicks</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['clicks']) }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Impressions</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['impressions']) }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">CTR</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['ctr'] }}%</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Position</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['position'] }}</div>
                </x-ui.card>
            </div>

            {{-- Performance Over Time chart --}}
            @if(count($performanceOverTime) > 0)
                <div class="mt-6">
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Performance Over Time</h3>
                        <x-charts.line-chart
                            :labels="collect($performanceOverTime)->pluck('date')->map(fn($d) => \Carbon\Carbon::parse($d)->format('M d'))->toArray()"
                            :datasets="[
                                ['label' => 'Clicks', 'data' => collect($performanceOverTime)->pluck('clicks')->toArray(), 'color' => '#8D5CF5'],
                                ['label' => 'Impressions', 'data' => collect($performanceOverTime)->pluck('impressions')->toArray(), 'color' => '#06b6d4'],
                            ]"
                            height="300px"
                        />
                    </x-ui.card>
                </div>
            @endif

            {{-- Top Search Queries --}}
            @if(count($queries) > 0)
                <div class="mt-6">
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Top Search Queries</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500">Query</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Clicks</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Impr.</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">CTR</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Position</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($queries as $query)
                                        <tr>
                                            <td class="py-2 font-medium text-gray-700">{{ $query['query'] }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($query['clicks']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($query['impressions']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ $query['ctr'] }}%</td>
                                            <td class="py-2 text-right text-gray-600">{{ $query['position'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                </div>
            @endif

            {{-- Top Pages --}}
            @if(count($pages) > 0)
                <div class="mt-6">
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Top Pages</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500">Page</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Clicks</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Impr.</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">CTR</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Position</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($pages as $page)
                                        <tr>
                                            <td class="py-2 max-w-xs truncate font-medium text-gray-700" title="{{ $page['page'] }}">{{ $page['page'] }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($page['clicks']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($page['impressions']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ $page['ctr'] }}%</td>
                                            <td class="py-2 text-right text-gray-600">{{ $page['position'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                </div>
            @endif

            {{-- Countries & Devices --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Countries --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Countries</h3>
                    @if(count($countries) > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500">Country</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Clicks</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Impr.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($countries as $country)
                                        <tr>
                                            <td class="py-2 font-medium text-gray-700">{{ $country['country'] }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($country['clicks']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($country['impressions']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No country data available.</p>
                    @endif
                </x-ui.card>

                {{-- Devices --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Devices</h3>
                    @if(count($devices) > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="pb-2 text-left font-medium text-gray-500">Device</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Clicks</th>
                                        <th class="pb-2 text-right font-medium text-gray-500">Impr.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($devices as $device)
                                        <tr>
                                            <td class="py-2 font-medium text-gray-700">{{ $device['device'] }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($device['clicks']) }}</td>
                                            <td class="py-2 text-right text-gray-600">{{ number_format($device['impressions']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No device data available.</p>
                    @endif
                </x-ui.card>
            </div>

            {{-- Disconnect link --}}
            <div class="mt-6 text-center">
                <button wire:click="disconnectSearchConsole" wire:confirm="Disconnect Search Console? Cached data will be removed." class="text-sm text-gray-400 hover:text-red-600 transition">
                    Disconnect Search Console
                </button>
            </div>
        @else
            {{-- Connected but no data yet --}}
            <x-ui.card>
                <x-ui.empty-state
                    title="Fetching Search Console data"
                    description="Data is being fetched from Google Search Console. This may take a moment. Try refreshing the page."
                    icon="search"
                />
            </x-ui.card>
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
