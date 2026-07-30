@php
    $activities = $this->recentActivity;
@endphp

<x-ui.module-card title="Recent Activity" icon="activity" tone="dark">
    <x-slot:actions>
        <span class="text-xs text-gray-400">Last 7 days</span>
    </x-slot:actions>

    @if($activities->isEmpty())
        <div class="py-6 text-center">
            <p class="text-sm text-gray-400">No activity in the last 7 days.</p>
        </div>
    @else
        <div class="-mt-1 divide-y divide-gray-100 dark:divide-gray-700/60">
            @foreach($activities as $activity)
                @php
                    $dotColor = match($activity->severity?->value) {
                        'critical' => 'bg-red-500',
                        'warning'  => 'bg-yellow-400',
                        'success'  => 'bg-green-500',
                        default    => 'bg-gray-400 dark:bg-gray-500',
                    };
                    $textColor = match($activity->severity?->value) {
                        'critical' => 'text-red-600 dark:text-red-400',
                        'warning'  => 'text-yellow-600 dark:text-yellow-400',
                        'success'  => 'text-green-600 dark:text-green-400',
                        default    => 'text-gray-500 dark:text-gray-400',
                    };
                @endphp
                <div class="flex items-start gap-3 py-2.5">
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
</x-ui.module-card>
