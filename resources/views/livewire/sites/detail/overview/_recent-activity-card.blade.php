@php
    $activities = $this->recentActivity;
@endphp

<x-ui.card :padding="false">
    {{-- Card Header --}}
    <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-900/40">
            <svg aria-hidden="true" class="h-4 w-4 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Recent Activity</h3>
        <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">Last 7 days</span>
    </div>

    @if($activities->isEmpty())
        <div class="px-4 py-8 text-center">
            <p class="text-sm text-gray-400 dark:text-gray-500">No activity in the last 7 days.</p>
        </div>
    @else
        <div class="divide-y divide-gray-50 dark:divide-gray-700/60">
            @foreach($activities as $activity)
                @php
                    $dotColor = match($activity->severity) {
                        'critical' => 'bg-red-500',
                        'warning'  => 'bg-yellow-400',
                        'success'  => 'bg-green-500',
                        default    => 'bg-gray-400 dark:bg-gray-500',
                    };
                    $textColor = match($activity->severity) {
                        'critical' => 'text-red-600 dark:text-red-400',
                        'warning'  => 'text-yellow-600 dark:text-yellow-400',
                        'success'  => 'text-green-600 dark:text-green-400',
                        default    => 'text-gray-500 dark:text-gray-400',
                    };
                @endphp
                <div class="flex items-start gap-3 px-4 py-3">
                    {{-- Severity dot --}}
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dotColor }}"></span>

                    {{-- Content --}}
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $activity->title }}</p>
                        @if($activity->description)
                            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $activity->description }}">
                                {{ Str::limit($activity->description, 80) }}
                            </p>
                        @endif
                    </div>

                    {{-- Relative time --}}
                    <span class="shrink-0 text-xs {{ $textColor }}" title="{{ $activity->created_at->format('Y-m-d H:i') }}">
                        {{ $activity->created_at->diffForHumans(short: true) }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</x-ui.card>
