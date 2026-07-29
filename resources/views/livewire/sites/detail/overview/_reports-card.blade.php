<x-ui.module-card title="Reports" icon="file-text" tone="dark">
    @if($this->activeReportSchedules->count() > 0)
        <x-ui.info-row label="Active Schedules">{{ $this->activeReportSchedules->count() }}</x-ui.info-row>

        @foreach($this->activeReportSchedules->take(2) as $schedule)
            <x-ui.info-row label="{{ ucfirst($schedule->frequency) }}">
                <span class="text-gray-500">Next: {{ $schedule->next_run_at?->diffForHumans() ?? 'N/A' }}</span>
            </x-ui.info-row>
        @endforeach

        @if($this->activeReportSchedules->count() > 2)
            <p class="px-1 text-xs text-gray-400">+{{ $this->activeReportSchedules->count() - 2 }} more</p>
        @endif

        @if($this->lastReport)
            <p class="mt-2 text-xs text-gray-400">Last sent {{ $this->lastReport->created_at->diffForHumans() }}</p>
        @endif

        <a href="{{ route('sites.reports', $site) }}" class="mact">Details →</a>
    @else
        <div class="py-2 text-center">
            <p class="text-sm text-gray-500">No scheduled reports</p>
            <a href="{{ route('sites.reports', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                Create Report →
            </a>
        </div>
    @endif
</x-ui.module-card>
