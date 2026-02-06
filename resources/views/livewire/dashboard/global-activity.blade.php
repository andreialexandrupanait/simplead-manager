<div>
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Activity</h1>
        <p class="mt-1 text-sm text-gray-500">Track all automated actions and events across your sites</p>
    </div>

    {{-- Filter bar --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        {{-- Type filter --}}
        @php
            $typeActive = $this->typeFilter !== 'all';
            $typeLabels = ['all' => 'Type', 'uptime' => 'Uptime', 'backup' => 'Backup', 'update' => 'Update', 'performance' => 'Performance', 'links' => 'Links', 'report' => 'Report'];
            $typeLabel = $typeActive ? $typeLabels[$this->typeFilter] : 'Type';
        @endphp
        <x-ui.dropdown align="left" width="48">
            <x-slot:trigger>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $typeActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    {{ $typeLabel }}
                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </x-slot:trigger>
            @foreach(['all' => 'All Types', 'uptime' => 'Uptime', 'backup' => 'Backup', 'update' => 'Update', 'performance' => 'Performance', 'links' => 'Links', 'report' => 'Report'] as $value => $label)
                <button wire:click="$set('typeFilter', '{{ $value }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->typeFilter === $value ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                    {{ $label }}
                    @if($this->typeFilter === $value)
                        <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </button>
            @endforeach
        </x-ui.dropdown>

        {{-- Severity filter --}}
        @php
            $severityActive = $this->severityFilter !== 'all';
            $severityLabels = ['all' => 'Severity', 'critical' => 'Critical', 'warning' => 'Warning', 'success' => 'Success', 'info' => 'Info'];
            $severityLabel = $severityActive ? $severityLabels[$this->severityFilter] : 'Severity';
        @endphp
        <x-ui.dropdown align="left" width="48">
            <x-slot:trigger>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $severityActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    {{ $severityLabel }}
                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </x-slot:trigger>
            @foreach(['all' => 'All Severities', 'critical' => 'Critical', 'warning' => 'Warning', 'success' => 'Success', 'info' => 'Info'] as $value => $label)
                <button wire:click="$set('severityFilter', '{{ $value }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->severityFilter === $value ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                    {{ $label }}
                    @if($this->severityFilter === $value)
                        <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </button>
            @endforeach
        </x-ui.dropdown>

        {{-- Search --}}
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search activity..."
            class="ml-auto w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
        >
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
