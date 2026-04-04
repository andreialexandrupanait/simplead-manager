<div>
    <x-ui.flash-alert type="success" key="cf-success" />
    <x-ui.flash-alert type="error" key="cf-error" />

    @if(!$this->siteCloudflare)
        {{-- Not connected --}}
        <x-ui.page-header title="Cloudflare" subtitle="{{ __('Connect this site to a Cloudflare zone to manage DNS, cache, and analytics') }}" />

        <x-ui.card>
            @if($this->connections->isEmpty())
                <x-ui.empty-state
                    title="{{ __('No Cloudflare connections') }}"
                    description="{{ __('Add a Cloudflare API token in Settings > Integrations first.') }}"
                    icon="cloud"
                />
            @else
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Cloudflare Connection') }}</label>
                        <x-ui.select wire:model.live="selectedConnectionId">
                            <option value="">{{ __('Select a connection...') }}</option>
                            @foreach($this->connections as $conn)
                                <option value="{{ $conn->id }}">{{ $conn->account_email ?: 'Connection #' . $conn->id }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    @if($selectedConnectionId)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Zone') }}</label>
                            <x-ui.select wire:model="selectedZoneId">
                                <option value="">{{ __('Select a zone...') }}</option>
                                @foreach($this->availableZones as $zone)
                                    <option value="{{ $zone['id'] }}">{{ $zone['name'] }} ({{ $zone['status'] }})</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <div class="flex justify-end">
                            <x-ui.button wire:click="connectToZone" wire:loading.attr="disabled">
                                {{ __('Connect Zone') }}
                            </x-ui.button>
                        </div>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @else
        {{-- Connected --}}
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $this->siteCloudflare->zone_name }}</h2>
                <p class="text-sm text-gray-500">
                    {{ __('Plan:') }} {{ $this->siteCloudflare->plan_label ?? 'N/A' }}
                    &middot; {{ __('Status:') }} {{ ucfirst($this->siteCloudflare->status) }}
                    @if($this->siteCloudflare->ssl_mode)
                        &middot; SSL: {{ strtoupper(str_replace('_', ' ', $this->siteCloudflare->ssl_mode)) }}
                    @endif
                    @if($this->siteCloudflare->is_paused)
                        &middot; <span class="text-yellow-600">{{ __('Paused') }}</span>
                    @endif
                </p>
            </div>
            <x-ui.button variant="secondary" wire:click="disconnectZone" wire:confirm="{{ __('Are you sure you want to disconnect this Cloudflare zone?') }}">
                {{ __('Disconnect') }}
            </x-ui.button>
        </div>

        {{-- Tabs --}}
        <div class="mb-6 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                @foreach(['overview' => __('Overview'), 'cache' => __('Cache'), 'analytics' => __('Analytics')] as $key => $label)
                    <button wire:click="$set('tab', '{{ $key }}')"
                        class="whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium transition {{ $tab === $key ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Overview Tab --}}
        @if($tab === 'overview')
            <x-ui.card>
                <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('DNS Records') }}</h3>
                @if(empty($this->dnsRecords))
                    <p class="text-sm text-gray-500">{{ __('No DNS records found.') }}</p>
                @else
                    {{-- Mobile cards --}}
                    <div class="md:hidden space-y-2">
                        @foreach($this->dnsRecords as $record)
                            <div class="rounded-lg border border-gray-200 p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge variant="gray">{{ $record['type'] }}</x-ui.badge>
                                        @if(in_array($record['type'], ['A', 'AAAA', 'CNAME']))
                                            <span class="{{ $record['proxied'] ? 'text-orange-500' : 'text-gray-400' }}">
                                                <svg class="h-4 w-4" fill="{{ $record['proxied'] ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                            </span>
                                        @endif
                                    </div>
                                    <span class="text-xs text-gray-500">TTL: {{ $record['ttl'] == 1 ? __('Auto') : $record['ttl'] . 's' }}</span>
                                </div>
                                <div class="mt-1.5 font-mono text-xs text-gray-900">{{ $record['name'] }}</div>
                                <div class="mt-0.5 font-mono text-xs text-gray-500 truncate">{{ $record['content'] }}</div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Desktop table --}}
                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                                    <th class="pb-2 pr-4">{{ __('Type') }}</th>
                                    <th class="pb-2 pr-4">{{ __('Name') }}</th>
                                    <th class="pb-2 pr-4">{{ __('Content') }}</th>
                                    <th class="pb-2 pr-4">TTL</th>
                                    <th class="pb-2 pr-4">{{ __('Proxy') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($this->dnsRecords as $record)
                                    <tr>
                                        <td class="py-2 pr-4">
                                            <x-ui.badge variant="gray">{{ $record['type'] }}</x-ui.badge>
                                        </td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $record['name'] }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs max-w-xs truncate">{{ $record['content'] }}</td>
                                        <td class="py-2 pr-4 text-xs text-gray-500">{{ $record['ttl'] == 1 ? __('Auto') : $record['ttl'] . 's' }}</td>
                                        <td class="py-2 pr-4">
                                            @if(in_array($record['type'], ['A', 'AAAA', 'CNAME']))
                                                <span class="text-xs {{ $record['proxied'] ? 'text-orange-500' : 'text-gray-400' }}">
                                                    <svg class="h-4 w-4" fill="{{ $record['proxied'] ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-gray-400">{{ __('Manage DNS records in the Cloudflare dashboard.') }}</p>
                @endif
            </x-ui.card>
        @endif

        {{-- Cache Tab --}}
        @if($tab === 'cache')
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('Purge Everything') }}</h3>
                    <p class="text-sm text-gray-500 mb-4">{{ __("Remove all cached files from Cloudflare's edge servers. This may temporarily slow down your site.") }}</p>
                    <x-ui.button variant="danger" wire:click="purgeEverything" wire:confirm="{{ __('Purge all cached files? This cannot be undone.') }}" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="purgeEverything">{{ __('Purge Everything') }}</span>
                        <span wire:loading wire:target="purgeEverything">{{ __('Purging...') }}</span>
                    </x-ui.button>
                </x-ui.card>

                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('Purge by URL') }}</h3>
                    <p class="text-sm text-gray-500 mb-2">{{ __('Enter one URL per line to purge specific pages.') }}</p>
                    <textarea wire:model="purgeUrls" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="https://example.com/page-1&#10;https://example.com/page-2"></textarea>
                    <div class="mt-3 flex justify-end">
                        <x-ui.button wire:click="purgeByUrls" size="sm" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="purgeByUrls">{{ __('Purge URLs') }}</span>
                            <span wire:loading wire:target="purgeByUrls">{{ __('Purging...') }}</span>
                        </x-ui.button>
                    </div>
                </x-ui.card>
            </div>

            <x-ui.card class="mt-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('Purge History') }}</h3>
                @if($this->cachePurges->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('No cache purges recorded.') }}</p>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach($this->cachePurges as $purge)
                            <div class="flex items-center justify-between py-3">
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ ucfirst($purge->type) }}</span>
                                    @if($purge->targets)
                                        <span class="text-xs text-gray-500 ml-1">({{ count($purge->targets) }} {{ Str::plural('item', count($purge->targets)) }})</span>
                                    @endif
                                    <div class="text-xs text-gray-500">
                                        {{ __('By') }} {{ $purge->purgedBy?->name ?? __('System') }}
                                    </div>
                                </div>
                                <span class="text-xs text-gray-500">{{ $purge->purged_at?->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif

        {{-- Analytics Tab --}}
        @if($tab === 'analytics')
            <div class="mb-6 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">{{ __('Zone Analytics') }}</h3>
                <x-ui.select wire:model.live="analyticsPeriod">
                    <option value="-30">{{ __('Last 30 minutes') }}</option>
                    <option value="-360">{{ __('Last 6 hours') }}</option>
                    <option value="-1440">{{ __('Last 24 hours') }}</option>
                    <option value="-10080">{{ __('Last 7 days') }}</option>
                    <option value="-43200">{{ __('Last 30 days') }}</option>
                </x-ui.select>
            </div>

            @if(!empty($this->analytics))
                @php
                    $totals = $this->analytics['totals'] ?? [];
                    $timeseries = $this->analytics['timeseries'] ?? [];
                    $requests = $totals['requests'] ?? [];
                    $bandwidth = $totals['bandwidth'] ?? [];
                    $threats = $totals['threats'] ?? [];
                    $pageviews = $totals['pageviews'] ?? [];
                    $uniques = $totals['uniques'] ?? [];
                    $totalReqs = $requests['all'] ?? 0;
                    $cachedReqs = $requests['cached'] ?? 0;
                    $uncachedReqs = $totalReqs - $cachedReqs;
                    $cachePercent = $totalReqs > 0 ? round(($cachedReqs / $totalReqs) * 100, 1) : 0;
                    $totalBw = $bandwidth['all'] ?? 0;
                    $cachedBw = $bandwidth['cached'] ?? 0;
                    $uncachedBw = $totalBw - $cachedBw;
                    $bwCachePercent = $totalBw > 0 ? round(($cachedBw / $totalBw) * 100, 1) : 0;
                @endphp

                {{-- Stats Cards --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100">
                                <svg class="h-5 w-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            </div>
                        </div>
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ number_format($totalReqs) }}</p>
                        <p class="text-xs text-gray-500">{{ __('Total Requests') }}</p>
                        <p class="text-xs text-green-600 mt-1">{{ number_format($cachedReqs) }} {{ __('cached') }} ({{ $cachePercent }}%)</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100">
                                <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            </div>
                        </div>
                        @php
                            $bwFormatted = $totalBw > 1073741824 ? round($totalBw / 1073741824, 2) . ' GB' : round($totalBw / 1048576, 2) . ' MB';
                            $cachedBwFormatted = $cachedBw > 1073741824 ? round($cachedBw / 1073741824, 2) . ' GB' : round($cachedBw / 1048576, 2) . ' MB';
                        @endphp
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ $bwFormatted }}</p>
                        <p class="text-xs text-gray-500">{{ __('Bandwidth') }}</p>
                        <p class="text-xs text-green-600 mt-1">{{ $cachedBwFormatted }} {{ __('saved') }} ({{ $bwCachePercent }}%)</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-100">
                                <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </div>
                        </div>
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ number_format($threats['all'] ?? 0) }}</p>
                        <p class="text-xs text-gray-500">{{ __('Threats Blocked') }}</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100">
                                <svg class="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                        </div>
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ number_format($uniques['all'] ?? $pageviews['all'] ?? 0) }}</p>
                        <p class="text-xs text-gray-500">{{ __('Unique Visitors') }}</p>
                        @if(!empty($pageviews['all']))
                            <p class="text-xs text-gray-400 mt-1">{{ number_format($pageviews['all']) }} {{ __('page views') }}</p>
                        @endif
                    </x-ui.card>
                </div>

                {{-- Requests Over Time Chart --}}
                @if(!empty($timeseries))
                    <x-ui.card class="mb-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('Requests Over Time') }}</h3>
                        <div x-data="{
                            timeseries: @js(collect($timeseries)->map(fn($t) => [
                                'since' => $t['since'] ?? '',
                                'requests' => $t['requests']['all'] ?? 0,
                                'cached' => $t['requests']['cached'] ?? 0,
                            ])->values()->toArray()),
                            get maxVal() {
                                return Math.max(...this.timeseries.map(t => t.requests), 1);
                            },
                            formatNum(n) {
                                if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
                                if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
                                return n.toString();
                            },
                            formatTime(iso) {
                                if (!iso) return '';
                                let d = new Date(iso);
                                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' +
                                       d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
                            }
                        }" class="relative">
                            {{-- Chart --}}
                            <div class="flex items-end gap-px" style="height: 200px;">
                                <template x-for="(point, index) in timeseries" :key="index">
                                    <div class="relative flex-1 flex flex-col items-stretch justify-end group" style="min-width: 2px;">
                                        {{-- Tooltip --}}
                                        <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover:block z-10 pointer-events-none">
                                            <div class="rounded-lg bg-gray-900 px-3 py-2 text-xs text-white shadow-lg whitespace-nowrap">
                                                <div class="font-medium" x-text="formatTime(point.since)"></div>
                                                <div class="mt-1 flex items-center gap-1.5">
                                                    <span class="h-2 w-2 rounded-full bg-purple-400"></span>
                                                    <span>{{ __('Total:') }} <span x-text="formatNum(point.requests)"></span></span>
                                                </div>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="h-2 w-2 rounded-full bg-green-400"></span>
                                                    <span>{{ __('Cached:') }} <span x-text="formatNum(point.cached)"></span></span>
                                                </div>
                                            </div>
                                        </div>
                                        {{-- Bar (uncached background + cached overlay) --}}
                                        <div class="w-full rounded-t bg-purple-200 transition-all group-hover:bg-purple-300"
                                             :style="'height: ' + Math.max((point.requests / maxVal) * 100, 1) + '%'">
                                            <div class="w-full rounded-t bg-purple-500 transition-all"
                                                 :style="'height: ' + (point.requests > 0 ? (point.cached / point.requests) * 100 : 0) + '%'">
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            {{-- Legend --}}
                            <div class="mt-3 flex items-center justify-center gap-4 text-xs text-gray-500">
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-sm bg-purple-500"></span>
                                    {{ __('Cached') }}
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-sm bg-purple-200"></span>
                                    {{ __('Uncached') }}
                                </div>
                            </div>
                        </div>
                    </x-ui.card>
                @endif

                {{-- Cached vs Uncached Breakdown --}}
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-6">
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('Request Cache Ratio') }}</h3>
                        <div x-data="{ cached: {{ $cachedReqs }}, uncached: {{ $uncachedReqs }}, total: {{ $totalReqs }} }">
                            {{-- Donut-style bar --}}
                            <div class="relative h-4 w-full overflow-hidden rounded-full bg-gray-100">
                                @if($totalReqs > 0)
                                    <div class="absolute inset-y-0 left-0 rounded-full bg-green-500 transition-all" style="width: {{ $cachePercent }}%"></div>
                                @endif
                            </div>
                            <div class="mt-3 flex items-center justify-between text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-green-500"></span>
                                    <span class="text-gray-600">{{ __('Cached') }}</span>
                                    <span class="font-medium text-gray-900">{{ number_format($cachedReqs) }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-gray-300"></span>
                                    <span class="text-gray-600">{{ __('Uncached') }}</span>
                                    <span class="font-medium text-gray-900">{{ number_format($uncachedReqs) }}</span>
                                </div>
                            </div>
                            <p class="mt-2 text-center text-lg font-bold text-green-600">{{ $cachePercent }}% <span class="text-xs font-normal text-gray-500">{{ __('cache hit rate') }}</span></p>
                        </div>
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('Bandwidth Cache Ratio') }}</h3>
                        <div>
                            <div class="relative h-4 w-full overflow-hidden rounded-full bg-gray-100">
                                @if($totalBw > 0)
                                    <div class="absolute inset-y-0 left-0 rounded-full bg-blue-500 transition-all" style="width: {{ $bwCachePercent }}%"></div>
                                @endif
                            </div>
                            <div class="mt-3 flex items-center justify-between text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-blue-500"></span>
                                    <span class="text-gray-600">{{ __('Cached') }}</span>
                                    <span class="font-medium text-gray-900">{{ $cachedBwFormatted }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-gray-300"></span>
                                    <span class="text-gray-600">{{ __('Uncached') }}</span>
                                    @php $uncachedBwFormatted = $uncachedBw > 1073741824 ? round($uncachedBw / 1073741824, 2) . ' GB' : round($uncachedBw / 1048576, 2) . ' MB'; @endphp
                                    <span class="font-medium text-gray-900">{{ $uncachedBwFormatted }}</span>
                                </div>
                            </div>
                            <p class="mt-2 text-center text-lg font-bold text-blue-600">{{ $bwCachePercent }}% <span class="text-xs font-normal text-gray-500">{{ __('bandwidth saved') }}</span></p>
                        </div>
                    </x-ui.card>
                </div>

                {{-- Country breakdown --}}
                @if(!empty($requests['country'] ?? []))
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('Top 10 Countries by Requests') }}</h3>
                        @php
                            $countries = collect($requests['country'])->sortByDesc(fn ($v) => $v)->take(10);
                            $maxCount = $countries->first() ?: 1;
                            $totalCountryReqs = collect($requests['country'])->sum();
                        @endphp
                        <div class="space-y-3">
                            @foreach($countries as $country => $count)
                                @php $pct = $totalCountryReqs > 0 ? round(($count / $totalCountryReqs) * 100, 1) : 0; @endphp
                                <div class="flex items-center gap-3">
                                    <span class="w-8 text-sm font-medium text-gray-700">{{ $country }}</span>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 h-3 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full rounded-full bg-purple-500 transition-all" style="width: {{ round(($count / $maxCount) * 100) }}%"></div>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="text-sm font-medium text-gray-900 w-20 text-right">{{ number_format($count) }}</span>
                                                <span class="text-xs text-gray-400 w-12 text-right">{{ $pct }}%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                {{-- Content Type & Status Codes (from timeseries) --}}
                @if(!empty($totals['requests']['http_status'] ?? []))
                    <x-ui.card class="mt-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ __('HTTP Status Codes') }}</h3>
                        @php
                            $httpStatuses = collect($totals['requests']['http_status'])->sortByDesc(fn ($v) => $v)->take(10);
                            $maxStatus = $httpStatuses->first() ?: 1;
                        @endphp
                        <div class="space-y-2">
                            @foreach($httpStatuses as $code => $count)
                                @php
                                    $statusColor = match(true) {
                                        $code >= 200 && $code < 300 => 'bg-green-500',
                                        $code >= 300 && $code < 400 => 'bg-blue-500',
                                        $code >= 400 && $code < 500 => 'bg-yellow-500',
                                        $code >= 500 => 'bg-red-500',
                                        default => 'bg-gray-500',
                                    };
                                @endphp
                                <div class="flex items-center gap-3">
                                    <span class="w-10 text-xs font-mono font-medium text-gray-600">{{ $code }}</span>
                                    <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $statusColor }}" style="width: {{ round(($count / $maxStatus) * 100) }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 w-16 text-right">{{ number_format($count) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            @else
                <x-ui.card>
                    <x-ui.empty-state
                        title="{{ __('No analytics data') }}"
                        description="{{ __('Analytics data is not available for this period.') }}"
                        icon="bar-chart-2"
                    />
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
