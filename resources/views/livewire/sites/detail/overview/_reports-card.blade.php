<x-ui.card>
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-100">
                <svg class="h-5 w-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Reports</h3>
        </div>
        <a href="{{ route('sites.reports', $site) }}" class="text-sm text-purple-600 hover:text-purple-700">
            View Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        @php
            $activeSchedules = $site->reportSchedules()->where('is_active', true)->get();
        @endphp

        @if($activeSchedules->count() > 0)
            {{-- Schedule Count --}}
            <div class="mb-4 text-center">
                <div class="text-4xl font-bold text-orange-600">{{ $activeSchedules->count() }}</div>
                <div class="mt-1 text-sm text-gray-500">
                    Active {{ Str::plural('Schedule', $activeSchedules->count()) }}
                </div>
            </div>

            {{-- Scheduled Reports List --}}
            <div class="space-y-2 border-t border-gray-100 pt-4">
                @foreach($activeSchedules->take(3) as $schedule)
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                    <div class="flex-1">
                        <div class="text-sm font-medium text-gray-900">
                            {{ ucfirst($schedule->frequency) }} Report
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            Next: {{ $schedule->next_run_at?->diffForHumans() ?? 'Not scheduled' }}
                        </div>
                    </div>
                    <div class="ml-3">
                        <span class="inline-flex items-center rounded-full bg-orange-100 px-2 py-1 text-xs font-medium text-orange-700">
                            {{ ucfirst($schedule->frequency) }}
                        </span>
                    </div>
                </div>
                @endforeach

                @if($activeSchedules->count() > 3)
                <div class="pt-2 text-center">
                    <a href="{{ route('sites.reports', $site) }}" class="text-xs text-purple-600 hover:text-purple-700">
                        +{{ $activeSchedules->count() - 3 }} more schedules
                    </a>
                </div>
                @endif
            </div>

            {{-- Last Report Sent --}}
            @php
                $lastReport = $site->reports()->latest('created_at')->first();
            @endphp

            @if($lastReport)
            <div class="mt-4 border-t border-gray-100 pt-4 text-xs text-gray-500">
                Last report sent {{ $lastReport->created_at->diffForHumans() }}
            </div>
            @endif
        @else
            <x-ui.empty-state
                title="No scheduled reports"
                description="Create automated reports to share site performance with clients."
            >
                <x-slot:actions>
                    <x-ui.button href="{{ route('sites.reports', $site) }}" color="orange">
                        Create Report
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @endif
    </div>
</x-ui.card>
