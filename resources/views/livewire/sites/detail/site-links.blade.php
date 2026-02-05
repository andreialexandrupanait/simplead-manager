<div class="min-w-0" @if($isScanning) wire:poll.3s="checkScanProgress" @endif>
    {{-- Header actions --}}
    <div class="mb-6 flex justify-end">
        <div class="flex items-center gap-3">
            @if($this->monitor)
                <x-ui.button variant="secondary" wire:click="openSettings">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Settings
                </x-ui.button>
            @endif
            <x-ui.button wire:click="scanNow"
                    wire:loading.attr="disabled"
                    @if($isScanning) disabled @endif>
                <span wire:loading.remove wire:target="scanNow">
                    <x-icons.link class="h-4 w-4" />
                </span>
                <svg wire:loading wire:target="scanNow" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                Scan Now
            </x-ui.button>
        </div>
    </div>

    {{-- Flash message --}}
    @if(session('message'))
        <x-ui.alert class="mb-6">{{ session('message') }}</x-ui.alert>
    @endif

    {{-- Scan Progress Banner --}}
    @if($isScanning && $this->activeScan)
        <div class="mb-6 rounded-lg border border-purple-200 bg-purple-50 p-4">
            <div class="flex items-center gap-3">
                <svg class="h-5 w-5 animate-spin text-purple-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <div class="flex-1">
                    <p class="text-sm font-medium text-purple-900">
                        Scanning links... {{ $this->activeScan->progress_percent }}%
                    </p>
                    @if($this->activeScan->progress_message)
                        <p class="text-xs text-purple-700">{{ $this->activeScan->progress_message }}</p>
                    @endif
                </div>
            </div>
            <div class="mt-2 h-2 w-full rounded-full bg-purple-200">
                <div class="h-2 rounded-full bg-purple-600 transition-all duration-500"
                     style="width: {{ $this->activeScan->progress_percent }}%"></div>
            </div>
        </div>
    @endif

    {{-- No scan yet: empty state --}}
    @if(!$this->latestScan && !$isScanning)
        <x-ui.card>
            <x-ui.empty-state
                title="No link scans yet"
                description="Run your first scan to check all links on this site for broken URLs, redirects, and other issues."
                icon="link"
            >
                <button wire:click="scanNow"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700">
                    <x-icons.link class="h-4 w-4" />
                    Start First Scan
                </button>
            </x-ui.empty-state>
        </x-ui.card>
    @endif

    {{-- Stats summary --}}
    @if($this->latestScan)
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.card>
                <div class="text-sm font-medium text-gray-500">Total Links</div>
                <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($this->stats['total']) }}</div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-sm font-medium text-gray-500">Broken</div>
                <div class="mt-1 text-2xl font-bold {{ $this->stats['broken'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                    {{ number_format($this->stats['broken']) }}
                </div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-sm font-medium text-gray-500">Redirects</div>
                <div class="mt-1 text-2xl font-bold {{ $this->stats['redirects'] > 0 ? 'text-yellow-600' : 'text-gray-900' }}">
                    {{ number_format($this->stats['redirects']) }}
                </div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-sm font-medium text-gray-500">Timeouts</div>
                <div class="mt-1 text-2xl font-bold {{ $this->stats['timeouts'] > 0 ? 'text-orange-600' : 'text-gray-900' }}">
                    {{ number_format($this->stats['timeouts']) }}
                </div>
            </x-ui.card>
        </div>

        {{-- Tab filters --}}
        <div class="mb-4 flex items-center gap-1 border-b">
            @php
                $tabs = [
                    'all' => ['label' => 'All', 'count' => $this->stats['total']],
                    'broken' => ['label' => 'Broken', 'count' => $this->stats['broken']],
                    'redirect' => ['label' => 'Redirects', 'count' => $this->stats['redirects']],
                    'timeout' => ['label' => 'Timeouts', 'count' => $this->stats['timeouts']],
                    'ok' => ['label' => 'OK', 'count' => $this->stats['ok']],
                ];
            @endphp
            @foreach($tabs as $key => $tab)
                <button wire:click="setStatusFilter('{{ $key }}')"
                        class="px-4 py-2.5 text-sm font-medium transition {{ $statusFilter === $key ? 'border-b-2 border-purple-600 text-purple-600' : 'text-gray-500 hover:text-gray-700' }}">
                    {{ $tab['label'] }}
                    <span class="ml-1 rounded-full {{ $statusFilter === $key ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600' }} px-2 py-0.5 text-xs font-medium">
                        {{ $tab['count'] }}
                    </span>
                </button>
            @endforeach
        </div>

        {{-- Search and type filter --}}
        <div class="mb-4 flex items-center gap-3">
            <div class="relative flex-1">
                <x-icons.search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <x-ui.input wire:model.live.debounce.300ms="search"
                       type="text"
                       placeholder="Search by URL, anchor text, or source page..."
                       class="pl-10" />
            </div>
            <x-ui.select wire:model.live="typeFilter">
                <option value="all">All Types</option>
                <option value="internal">Internal</option>
                <option value="external">External</option>
            </x-ui.select>
        </div>

        {{-- Links table --}}
        <x-ui.card class="mb-6 overflow-hidden !p-0">
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th>URL</x-ui.th>
                    <x-ui.th>Found On</x-ui.th>
                    <x-ui.th>Response</x-ui.th>
                    <x-ui.th class="w-20">Actions</x-ui.th>
                </x-slot:head>

                @forelse($this->links as $link)
                    <tr class="hover:bg-gray-50">
                        <x-ui.td>
                            @php
                                $badgeVariant = match($link->status_color) {
                                    'green' => 'green',
                                    'red' => 'red',
                                    'yellow' => 'yellow',
                                    'orange' => 'yellow',
                                    default => 'gray',
                                };
                            @endphp
                            <x-ui.badge :variant="$badgeVariant">{{ $link->status_label }}</x-ui.badge>
                        </x-ui.td>
                        <x-ui.td>
                            <div class="max-w-xs">
                                <a href="{{ $link->url }}" target="_blank" class="text-sm text-purple-600 hover:text-purple-700 truncate block" title="{{ $link->url }}">
                                    {{ \Illuminate\Support\Str::limit($link->url, 60) }}
                                </a>
                                @if($link->anchor_text)
                                    <p class="text-xs text-gray-400 truncate" title="{{ $link->anchor_text }}">{{ \Illuminate\Support\Str::limit($link->anchor_text, 50) }}</p>
                                @endif
                                @if($link->final_url && $link->final_url !== $link->url)
                                    <p class="text-xs text-yellow-600 truncate" title="{{ $link->final_url }}">
                                        &rarr; {{ \Illuminate\Support\Str::limit($link->final_url, 50) }}
                                    </p>
                                @endif
                            </div>
                        </x-ui.td>
                        <x-ui.td>
                            @if($link->source_url)
                                <a href="{{ $link->source_url }}" target="_blank" class="text-xs text-gray-500 hover:text-gray-700 truncate block max-w-[200px]" title="{{ $link->source_url }}">
                                    {{ \Illuminate\Support\Str::limit($link->source_title ?: $link->source_url, 40) }}
                                </a>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            @if($link->response_time_ms !== null)
                                <span class="text-sm text-gray-600">{{ $link->response_time_ms }}ms</span>
                            @elseif($link->error_message)
                                <span class="text-xs text-red-500" title="{{ $link->error_message }}">Error</span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            <button wire:click="dismissLink({{ $link->id }})"
                                    class="text-xs text-gray-400 hover:text-gray-600"
                                    title="Dismiss">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                </svg>
                            </button>
                        </x-ui.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                            No links found matching your filters.
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        {{-- Pagination --}}
        <div class="mb-8">
            {{ $this->links->links() }}
        </div>

        {{-- Scan History --}}
        @if($this->scanHistory->isNotEmpty())
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Scan History</h2>
            <x-ui.card class="overflow-hidden !p-0">
                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th>Date</x-ui.th>
                        <x-ui.th>Links</x-ui.th>
                        <x-ui.th>Broken</x-ui.th>
                        <x-ui.th>Redirects</x-ui.th>
                        <x-ui.th>Duration</x-ui.th>
                        <x-ui.th>Status</x-ui.th>
                    </x-slot:head>

                    @foreach($this->scanHistory as $scan)
                        <tr class="hover:bg-gray-50">
                            <x-ui.td>
                                <span class="text-sm text-gray-900">{{ $scan->created_at->format('M d, Y H:i') }}</span>
                            </x-ui.td>
                            <x-ui.td>
                                <span class="text-sm text-gray-600">{{ number_format($scan->total_links) }}</span>
                            </x-ui.td>
                            <x-ui.td>
                                <span class="text-sm {{ $scan->broken_links > 0 ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                                    {{ $scan->broken_links }}
                                </span>
                            </x-ui.td>
                            <x-ui.td>
                                <span class="text-sm text-gray-600">{{ $scan->redirects }}</span>
                            </x-ui.td>
                            <x-ui.td>
                                @if($scan->duration_seconds)
                                    <span class="text-sm text-gray-600">
                                        @if($scan->duration_seconds >= 60)
                                            {{ intval($scan->duration_seconds / 60) }}m {{ $scan->duration_seconds % 60 }}s
                                        @else
                                            {{ $scan->duration_seconds }}s
                                        @endif
                                    </span>
                                @else
                                    <span class="text-sm text-gray-400">—</span>
                                @endif
                            </x-ui.td>
                            <x-ui.td>
                                <x-ui.badge :variant="$scan->status === 'completed' ? 'green' : 'red'">
                                    {{ ucfirst($scan->status) }}
                                </x-ui.badge>
                            </x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif

    {{-- Settings Modal --}}
    <x-ui.modal name="link-settings" title="Link Checker Settings">
        <form wire:submit="saveSettings" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Frequency</label>
                    <x-ui.select wire:model="settingsFrequency" class="mt-1">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="manual">Manual</option>
                    </x-ui.select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Scan Time</label>
                    <x-ui.input wire:model="settingsScanTime" type="time" class="mt-1" />
                </div>
            </div>

            @if($settingsFrequency === 'weekly')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Day of Week</label>
                    <x-ui.select wire:model="settingsDayOfWeek" class="mt-1">
                        <option value="0">Sunday</option>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                    </x-ui.select>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Max Pages</label>
                    <x-ui.input wire:model="settingsMaxPages" type="number" min="1" max="10000" class="mt-1" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Max Depth</label>
                    <x-ui.input wire:model="settingsMaxDepth" type="number" min="1" max="20" class="mt-1" />
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Timeout (seconds)</label>
                <x-ui.input wire:model="settingsTimeout" type="number" min="5" max="120" class="mt-1" />
            </div>

            <div class="flex items-center gap-6">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input wire:model="settingsCheckExternal" type="checkbox" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    Check external links
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input wire:model="settingsCheckImages" type="checkbox" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    Check images
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Exclude Paths (one per line)</label>
                <textarea wire:model="settingsExcludePaths" rows="3" placeholder="/wp-admin/&#10;/wp-json/" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Exclude Domains (one per line)</label>
                <textarea wire:model="settingsExcludeDomains" rows="3" placeholder="fonts.googleapis.com&#10;analytics.google.com" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
            </div>

            <div class="border-t pt-4">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input wire:model="settingsAlertOnBroken" type="checkbox" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    Alert when broken links found
                </label>
                @if($settingsAlertOnBroken)
                    <div class="mt-2">
                        <label class="block text-sm font-medium text-gray-700">Alert threshold (minimum broken links)</label>
                        <x-ui.input wire:model="settingsAlertThreshold" type="number" min="1" class="mt-1 w-32" />
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-3 border-t pt-4">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-link-settings')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit">
                    Save Settings
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
