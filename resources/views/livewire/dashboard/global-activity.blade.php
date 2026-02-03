<div>
    {{-- Filter bar --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        {{-- Type filter --}}
        <div class="flex rounded-lg bg-gray-100 p-1">
            @foreach(['all' => 'All', 'uptime' => 'Uptime', 'backup' => 'Backup', 'update' => 'Update', 'performance' => 'Performance', 'links' => 'Links', 'report' => 'Report'] as $value => $label)
                <button
                    wire:click="$set('typeFilter', '{{ $value }}')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $this->typeFilter === $value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Severity filter --}}
        <div class="flex rounded-lg bg-gray-100 p-1">
            @foreach(['all' => 'All', 'critical' => 'Critical', 'warning' => 'Warning', 'success' => 'Success', 'info' => 'Info'] as $value => $label)
                <button
                    wire:click="$set('severityFilter', '{{ $value }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-white text-gray-900 shadow-sm' => $this->severityFilter === $value,
                        'text-gray-500 hover:text-gray-700' => $this->severityFilter !== $value,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Search --}}
        <div class="flex-1">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search activity..."
                class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
            >
        </div>
    </div>

    {{-- Activity list --}}
    @if($this->activities->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                title="No activity found"
                description="Activity will appear here as events occur across your sites."
                icon="inbox"
            />
        </x-ui.card>
    @else
        <div class="space-y-2">
            @foreach($this->activities as $activity)
                <x-ui.card>
                    <div class="flex items-start gap-3">
                        {{-- Icon --}}
                        @if($activity->icon)
                            <div class="mt-0.5 flex-shrink-0">
                                <x-dynamic-component
                                    :component="'icons.' . $activity->icon"
                                    @class([
                                        'h-5 w-5',
                                        'text-red-500' => $activity->severity === 'critical',
                                        'text-yellow-500' => $activity->severity === 'warning',
                                        'text-green-500' => $activity->severity === 'success',
                                        'text-gray-400' => $activity->severity === 'info',
                                    ])
                                />
                            </div>
                        @endif

                        {{-- Content --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-gray-900">{{ $activity->title }}</p>
                                <x-ui.badge :variant="match($activity->severity) { 'critical' => 'red', 'warning' => 'yellow', 'success' => 'green', default => 'gray' }">
                                    {{ ucfirst($activity->severity) }}
                                </x-ui.badge>
                            </div>
                            @if($activity->description)
                                <p class="mt-0.5 text-sm text-gray-600">{{ $activity->description }}</p>
                            @endif
                            <div class="mt-1 flex items-center gap-2 text-xs text-gray-400">
                                @if($activity->site)
                                    <a href="{{ route('sites.overview', $activity->site) }}" class="font-medium text-purple-600 hover:text-purple-800">
                                        {{ $activity->site->name }}
                                    </a>
                                    <span>&middot;</span>
                                @endif
                                <span>{{ $activity->created_at->diffForHumans() }}</span>
                                <span>&middot;</span>
                                <span>{{ $activity->created_at->format('M d, Y H:i') }}</span>
                            </div>
                        </div>

                        {{-- View link --}}
                        @if($activity->url)
                            <a href="{{ $activity->url }}" class="flex-shrink-0 text-sm font-medium text-purple-600 hover:text-purple-800">
                                View
                            </a>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $this->activities->links() }}
        </div>
    @endif
</div>
