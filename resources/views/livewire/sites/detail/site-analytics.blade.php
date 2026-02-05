<div>
    @if($connection && $connection->is_active)
        <div class="mb-6 flex justify-end">
            <div class="flex items-center gap-2">
                {{-- Date range buttons --}}
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

                {{-- Refresh button --}}
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
    @if(session('analytics-refreshing'))
        <div class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">Data is being refreshed in the background. Reload in a moment.</div>
    @endif

    @if($connection && $connection->is_active)
        {{-- Data subtitle --}}
        @if($cache)
            <p class="mb-6 text-xs text-gray-400">
                Data from {{ $cache->start_date->format('M d') }} &ndash; {{ $cache->end_date->format('M d, Y') }}
                &middot; Updated {{ $cache->fetched_at->diffForHumans() }}
            </p>
        @endif

        @if($overview)
            {{-- Overview metric cards --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Users</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['total_users']) }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">New Users</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['new_users']) }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Sessions</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['sessions']) }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Pageviews</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($overview['pageviews']) }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Bounce Rate</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['bounce_rate'] }}%</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Avg Time</div>
                    @php
                        $mins = floor($overview['avg_session_duration'] / 60);
                        $secs = (int) ($overview['avg_session_duration'] % 60);
                    @endphp
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $mins }}m {{ $secs }}s</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-medium text-gray-500">Engagement</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ $overview['engagement_rate'] }}%</div>
                </x-ui.card>
            </div>

            {{-- Users Over Time chart --}}
            @if(count($usersOverTime) > 0)
                <div class="mt-6">
                    <x-ui.card>
                        <h3 class="mb-4 text-base font-semibold text-gray-900">Users Over Time</h3>
                        <x-charts.line-chart
                            :labels="collect($usersOverTime)->pluck('date')->map(fn($d) => \Carbon\Carbon::parse($d)->format('M d'))->toArray()"
                            :datasets="[
                                ['label' => 'Users', 'data' => collect($usersOverTime)->pluck('users')->toArray(), 'color' => '#8D5CF5'],
                                ['label' => 'New Users', 'data' => collect($usersOverTime)->pluck('new_users')->toArray(), 'color' => '#06b6d4'],
                                ['label' => 'Sessions', 'data' => collect($usersOverTime)->pluck('sessions')->toArray(), 'color' => '#10b981'],
                            ]"
                            height="300px"
                        />
                    </x-ui.card>
                </div>
            @endif

            {{-- Traffic Sources & Top Pages --}}
            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Traffic Sources --}}
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Traffic Sources</h3>
                    @if(count($trafficSources) > 0)
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
                        <div class="space-y-2">
                            @foreach($countries as $country)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium text-gray-700">{{ $country['country'] }}</span>
                                    <div class="flex items-center gap-3 text-gray-500">
                                        <span>{{ number_format($country['users']) }} users</span>
                                        <span>{{ number_format($country['sessions']) }} sessions</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No country data available.</p>
                    @endif

                    {{-- Cities subsection --}}
                    @if(count($cities) > 0)
                        <h4 class="mt-6 mb-3 text-sm font-semibold text-gray-700">Top Cities</h4>
                        <div class="space-y-2">
                            @foreach($cities as $city)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium text-gray-700">{{ $city['city'] }} <span class="text-xs text-gray-400">{{ $city['country'] }}</span></span>
                                    <span class="text-gray-500">{{ number_format($city['users']) }} users</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            </div>

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
