<x-dashboard.widget-container
    :title="$this->getTitle()"
    :widget-id="$widget->id"
    :loading="!$isLoaded"
    skeleton-type="list"
    wire:init="loadWidget"
>
    <x-slot name="actions">
        @if($isLoaded && $this->data && !empty($this->data['alerts']))
            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                {{ $this->data['total_count'] }}
            </span>
        @endif
    </x-slot>

    @if($isLoaded && $this->data)
        @if(empty($this->data['alerts']))
            <div class="flex h-32 items-center justify-center">
                <div class="text-center">
                    <svg class="mx-auto h-10 w-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="mt-2 text-sm font-medium text-gray-900">All Clear!</p>
                    <p class="text-xs text-gray-500">No alerts at this time</p>
                </div>
            </div>
        @else
            <div class="space-y-3">
                @foreach($this->data['alerts'] as $alert)
                    <div class="group rounded-lg border transition hover:border-gray-300 hover:shadow-sm {{ $alert['severity'] === 'critical' ? 'border-red-200 bg-red-50' : 'border-yellow-200 bg-yellow-50' }}">
                        <div class="flex items-start gap-3 p-3">
                            {{-- Severity Icon --}}
                            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $alert['severity'] === 'critical' ? 'bg-red-100' : 'bg-yellow-100' }}">
                                @switch($alert['icon'] ?? 'bell')
                                    @case('activity')
                                        <svg class="h-4 w-4 {{ $alert['severity'] === 'critical' ? 'text-red-600' : 'text-yellow-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                        </svg>
                                        @break
                                    @case('shield')
                                        <svg class="h-4 w-4 {{ $alert['severity'] === 'critical' ? 'text-red-600' : 'text-yellow-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        @break
                                    @case('globe')
                                        <svg class="h-4 w-4 {{ $alert['severity'] === 'critical' ? 'text-red-600' : 'text-yellow-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        @break
                                    @default
                                        <svg class="h-4 w-4 {{ $alert['severity'] === 'critical' ? 'text-red-600' : 'text-yellow-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                        </svg>
                                @endswitch
                            </div>

                            {{-- Alert Content --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-semibold {{ $alert['severity'] === 'critical' ? 'text-red-900' : 'text-yellow-900' }}">
                                            {{ $alert['title'] }}
                                        </h4>
                                        <p class="mt-0.5 text-xs {{ $alert['severity'] === 'critical' ? 'text-red-700' : 'text-yellow-700' }} line-clamp-1">
                                            {{ $alert['description'] }}
                                        </p>
                                    </div>

                                    {{-- View Button --}}
                                    @if(isset($alert['url']))
                                        <a
                                            href="{{ $alert['url'] }}"
                                            class="shrink-0 rounded px-2 py-1 text-xs font-medium transition {{ $alert['severity'] === 'critical' ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200' }}"
                                        >
                                            View
                                        </a>
                                    @endif
                                </div>

                                {{-- Timestamp --}}
                                @if(isset($alert['timestamp']))
                                    <div class="mt-1 text-xs {{ $alert['severity'] === 'critical' ? 'text-red-600' : 'text-yellow-600' }}">
                                        {{ \Carbon\Carbon::parse($alert['timestamp'])->diffForHumans() }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Summary Footer --}}
            @if($this->data['total_count'] > 0)
                <div class="mt-4 flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                    <div class="flex items-center gap-4 text-xs">
                        <div class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-red-500"></span>
                            <span class="font-medium text-gray-700">{{ $this->data['critical_count'] }} Critical</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-yellow-500"></span>
                            <span class="font-medium text-gray-700">{{ $this->data['warning_count'] }} Warning</span>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    @endif
</x-dashboard.widget-container>
