<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Error Logs</h2>
            <p class="text-sm text-gray-500">{{ $site->name }}</p>
        </div>
        <x-ui.button wire:click="syncNow" wire:loading.attr="disabled">
            <x-icons.refresh-cw class="mr-1.5 h-4 w-4" wire:loading.class="animate-spin" wire:target="syncNow" />
            Sync Now
        </x-ui.button>
    </div>

    {{-- Flash messages --}}
    @if(session('error-log-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('error-log-success') }}</div>
    @endif

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
        {{-- Level filter --}}
        <div class="flex rounded-lg bg-gray-100 p-1">
            @foreach(['all' => 'All', 'fatal' => 'Fatal', 'error' => 'Error', 'warning' => 'Warning', 'notice' => 'Notice', 'deprecated' => 'Deprecated'] as $value => $label)
                <button
                    wire:click="$set('levelFilter', '{{ $value }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-white text-gray-900 shadow-sm' => $this->levelFilter === $value,
                        'text-gray-500 hover:text-gray-700' => $this->levelFilter !== $value,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Show resolved toggle --}}
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="showResolved" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
            Show resolved
        </label>

        <div class="flex-1"></div>

        {{-- Mark all resolved --}}
        @if($this->stats['fatal'] + $this->stats['error'] + $this->stats['warning'] > 0)
            <x-ui.button variant="secondary" wire:click="resolveAll" wire:confirm="Mark all errors as resolved?">
                Mark All Resolved
            </x-ui.button>
        @endif
    </div>

    {{-- Errors table --}}
    @if($this->errors->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                title="No errors found"
                description="Click Sync Now to fetch error logs from this site."
                icon="alert-triangle"
            />
        </x-ui.card>
    @else
        <x-ui.card :padding="false">
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Level</x-ui.th>
                    <x-ui.th>Message</x-ui.th>
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
                            <p class="max-w-lg truncate text-sm text-gray-900">{{ $error->message }}</p>
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
                            <td colspan="6" class="bg-gray-50 px-4 py-4">
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
