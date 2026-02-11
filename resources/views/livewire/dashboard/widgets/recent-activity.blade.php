<x-dashboard.widget-container
    :title="$this->getTitle()"
    :widget-id="$widget->id"
    :loading="!$isLoaded"
    skeleton-type="list"
    wire:init="loadWidget"
>
    @if($isLoaded && $this->data)
        @if($this->data['count'] === 0)
            <div class="flex h-32 items-center justify-center">
                <div class="text-center">
                    <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="mt-2 text-sm font-medium text-gray-900">No Activity</p>
                    <p class="text-xs text-gray-500">No recent events</p>
                </div>
            </div>
        @else
            <div class="relative max-h-96 space-y-3 overflow-y-auto">
                @foreach($this->data['activities'] as $activity)
                    <div class="group flex items-start gap-3 rounded-lg border border-gray-100 p-3 transition hover:border-gray-200 hover:bg-gray-50">
                        {{-- Activity Icon --}}
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $activity->severity === 'error' ? 'bg-red-100 text-red-600' : ($activity->severity === 'warning' ? 'bg-yellow-100 text-yellow-600' : 'bg-blue-100 text-blue-600') }}">
                            @switch($activity->type)
                                @case('backup_created')
                                @case('backup_completed')
                                @case('backup_failed')
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                    </svg>
                                    @break
                                @case('site_created')
                                @case('site_updated')
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                    </svg>
                                    @break
                                @case('uptime_check')
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                    </svg>
                                    @break
                                @default
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                            @endswitch
                        </div>

                        {{-- Activity Content --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">{{ $activity->title ?? 'Activity' }}</p>
                                    @if($activity->description)
                                        <p class="mt-0.5 text-xs text-gray-600 line-clamp-2">{{ $activity->description }}</p>
                                    @endif

                                    {{-- Site Link --}}
                                    @if($activity->site)
                                        <a
                                            href="{{ route('sites.show', $activity->site) }}"
                                            class="mt-1 inline-flex items-center gap-1 text-xs text-purple-600 hover:text-purple-700"
                                        >
                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                            </svg>
                                            {{ $activity->site->name }}
                                        </a>
                                    @endif
                                </div>

                                {{-- Timestamp --}}
                                <div class="shrink-0 text-xs text-gray-500" title="{{ $activity->created_at->format('M j, Y g:i A') }}">
                                    {{ $activity->created_at->diffForHumans() }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-dashboard.widget-container>
