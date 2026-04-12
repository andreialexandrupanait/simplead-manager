<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-100">
                <svg aria-hidden="true" class="h-4 w-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Reports</h3>
        </div>
        <a href="{{ route('sites.reports', $site) }}" class="text-xs text-accent-600 hover:text-accent-700">
            Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        @if($this->activeReportSchedules->count() > 0)
            <div class="flex-1 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Active Schedules</span>
                    <span class="text-sm font-medium text-gray-900">{{ $this->activeReportSchedules->count() }}</span>
                </div>

                @foreach($this->activeReportSchedules->take(2) as $schedule)
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-gray-500">{{ ucfirst($schedule->frequency) }}</span>
                        <span class="text-gray-500">Next: {{ $schedule->next_run_at?->diffForHumans() ?? 'N/A' }}</span>
                    </div>
                @endforeach

                @if($this->activeReportSchedules->count() > 2)
                    <p class="text-xs text-gray-400">+{{ $this->activeReportSchedules->count() - 2 }} more</p>
                @endif
            </div>

            @if($this->lastReport)
            <div class="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-400">
                Last sent {{ $this->lastReport->created_at->diffForHumans() }}
            </div>
            @endif
        @else
            <div class="py-2 text-center">
                <p class="text-sm text-gray-500">No scheduled reports</p>
                <a href="{{ route('sites.reports', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                    Create Report →
                </a>
            </div>
        @endif
    </div>
</x-ui.card>
