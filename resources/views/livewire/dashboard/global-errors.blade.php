<div>
    {{-- Header --}}
    <x-ui.page-header title="Errors" subtitle="Monitor PHP errors, warnings, and notices across all sites" />

    {{-- Stats bar --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.card class="!p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-50">
                    <x-icons.alert-triangle class="h-5 w-5 text-red-600" />
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->stats['fatal'] }}</p>
                    <p class="text-xs text-gray-500">Fatal Errors</p>
                </div>
            </div>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-50">
                    <x-icons.alert-triangle class="h-5 w-5 text-orange-600" />
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->stats['error'] }}</p>
                    <p class="text-xs text-gray-500">Errors</p>
                </div>
            </div>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-yellow-50">
                    <x-icons.alert-triangle class="h-5 w-5 text-yellow-600" />
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->stats['warning'] }}</p>
                    <p class="text-xs text-gray-500">Warnings</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        {{-- Site filter --}}
        @php
            $siteActive = $this->siteFilter !== 'all';
            $siteLabel = 'Site';
            if ($siteActive) {
                $selectedSite = $this->sites->firstWhere('id', $this->siteFilter);
                $siteLabel = $selectedSite ? $selectedSite->name : 'Site';
            }
        @endphp
        <x-ui.dropdown align="left" width="56">
            <x-slot:trigger>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $siteActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    <span class="max-w-[8rem] truncate">{{ $siteLabel }}</span>
                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </x-slot:trigger>
            <button wire:click="$set('siteFilter', 'all')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ !$siteActive ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                All Sites
                @if(!$siteActive)
                    <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @endif
            </button>
            @foreach($this->sites as $site)
                <button wire:click="$set('siteFilter', '{{ $site->id }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->siteFilter == $site->id ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                    {{ $site->name }}
                    @if($this->siteFilter == $site->id)
                        <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </button>
            @endforeach
        </x-ui.dropdown>

        {{-- Level filter --}}
        @php
            $levelActive = $this->levelFilter !== 'all';
            $levelLabels = ['all' => 'Level', 'fatal' => 'Fatal', 'error' => 'Error', 'warning' => 'Warning', 'notice' => 'Notice', 'deprecated' => 'Deprecated'];
            $levelLabel = $levelActive ? $levelLabels[$this->levelFilter] : 'Level';
        @endphp
        <x-ui.dropdown align="left" width="48">
            <x-slot:trigger>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $levelActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    {{ $levelLabel }}
                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </x-slot:trigger>
            @foreach(['all' => 'All Levels', 'fatal' => 'Fatal', 'error' => 'Error', 'warning' => 'Warning', 'notice' => 'Notice', 'deprecated' => 'Deprecated'] as $value => $label)
                <button wire:click="$set('levelFilter', '{{ $value }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->levelFilter === $value ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                    {{ $label }}
                    @if($this->levelFilter === $value)
                        <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </button>
            @endforeach
        </x-ui.dropdown>

        {{-- Show resolved toggle --}}
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="showResolved" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
            Show resolved
        </label>

        {{-- Search --}}
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="Search errors..."
            class="ml-auto w-64"
        />

        {{-- Mark all resolved --}}
        @if($this->stats['fatal'] + $this->stats['error'] + $this->stats['warning'] > 0)
            <x-ui.button variant="secondary" wire:click="resolveAll" wire:confirm="Mark all matching errors as resolved?">
                Mark All Resolved
            </x-ui.button>
        @endif
    </div>

    {{-- Errors table --}}
    @if($this->errors->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                title="No errors found"
                description="Error logs from your WordPress sites will appear here."
                icon="alert-triangle"
            />
        </x-ui.card>
    @else
        <x-ui.card :padding="false">
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Level</x-ui.th>
                    <x-ui.th>Message</x-ui.th>
                    <x-ui.th>Site</x-ui.th>
                    <x-ui.th>File</x-ui.th>
                    <x-ui.th>Count</x-ui.th>
                    <x-ui.th>Last Seen</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach($this->errors as $error)
                    <tr wire:click="toggleExpand({{ $error->id }})" class="cursor-pointer hover:bg-gray-50 {{ $error->is_resolved ? 'opacity-50' : '' }}">
                        <x-ui.td>
                            <x-ui.badge :variant="$error->level_color">
                                {{ ucfirst($error->level) }}
                            </x-ui.badge>
                        </x-ui.td>
                        <x-ui.td>
                            <p class="max-w-md truncate text-sm text-gray-900">{{ $error->message }}</p>
                        </x-ui.td>
                        <x-ui.td>
                            <a href="{{ route('sites.errors', $error->site) }}" class="text-sm font-medium text-purple-600 hover:text-purple-800" wire:click.stop>
                                {{ $error->site->name }}
                            </a>
                        </x-ui.td>
                        <x-ui.td>
                            @if($error->file_path)
                                <span class="text-xs font-mono text-gray-500">{{ basename($error->file_path) }}:{{ $error->line_number }}</span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-sm font-medium text-gray-900">{{ number_format($error->count) }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-xs text-gray-500">{{ $error->last_seen_at?->diffForHumans() ?? '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            @unless($error->is_resolved)
                                <button
                                    wire:click.stop="resolveError({{ $error->id }})"
                                    class="text-xs font-medium text-green-600 hover:text-green-800"
                                >
                                    Resolve
                                </button>
                            @else
                                <span class="text-xs text-gray-400">Resolved</span>
                            @endunless
                        </x-ui.td>
                    </tr>

                    {{-- Expanded details --}}
                    @if($expandedId === $error->id)
                        <tr>
                            <td colspan="7" class="bg-gray-50 px-4 py-4">
                                <div class="space-y-3">
                                    <div>
                                        <h4 class="text-xs font-semibold uppercase text-gray-500 mb-1">Full Message</h4>
                                        <p class="text-sm text-gray-900 whitespace-pre-wrap">{{ $error->message }}</p>
                                    </div>
                                    @if($error->file_path)
                                        <div>
                                            <h4 class="text-xs font-semibold uppercase text-gray-500 mb-1">File</h4>
                                            <p class="text-sm font-mono text-gray-700">{{ $error->file_path }}:{{ $error->line_number }}</p>
                                        </div>
                                    @endif
                                    @if($error->stack_trace)
                                        <div>
                                            <h4 class="text-xs font-semibold uppercase text-gray-500 mb-1">Stack Trace</h4>
                                            <pre class="max-h-48 overflow-auto rounded bg-gray-900 p-3 text-xs text-green-400">{{ $error->stack_trace }}</pre>
                                        </div>
                                    @endif
                                    @if($error->context)
                                        <div>
                                            <h4 class="text-xs font-semibold uppercase text-gray-500 mb-1">Context</h4>
                                            <pre class="max-h-32 overflow-auto rounded bg-gray-100 p-3 text-xs text-gray-700">{{ json_encode($error->context, JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                    @endif
                                    <div class="flex items-center gap-4 text-xs text-gray-500">
                                        <span>First seen: {{ $error->first_seen_at?->format('M d, Y H:i') ?? '—' }}</span>
                                        <span>Occurrences: {{ number_format($error->count) }}</span>
                                        @if($error->is_resolved)
                                            <span>Resolved: {{ $error->resolved_at?->format('M d, Y H:i') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <div class="mt-6">
            {{ $this->errors->links() }}
        </div>
    @endif
</div>
