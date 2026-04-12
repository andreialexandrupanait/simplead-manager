@props(['site'])

@php $activeSchedule = $site->reportSchedules->first(); @endphp

@if($activeSchedule)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">Reports</span>
        <x-ui.badge variant="green">Active</x-ui.badge>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500 dark:text-gray-400">Frequency</span>
            <span class="font-medium text-gray-900 dark:text-gray-100">{{ ucfirst($activeSchedule->frequency ?? 'N/A') }}</span>
        </div>
        <div class="flex items-center justify-between">
            <span class="text-gray-500 dark:text-gray-400">Total schedules</span>
            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->reportSchedules->count() }}</span>
        </div>
        @if($activeSchedule->next_run_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Next report</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $activeSchedule->next_run_at->format('M j, g:ia') }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 dark:border-gray-700 pt-3">
        <button wire:click="generateQuickReport({{ $site->id }})" class="rounded-md bg-accent-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-accent-700">Generate Report</button>
        <a href="{{ route('sites.reports', $site) }}" class="text-xs font-medium text-accent-600 hover:text-accent-800">View Reports</a>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">No reports scheduled</p>
    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 dark:border-gray-700 pt-3">
        <button wire:click="generateQuickReport({{ $site->id }})" class="rounded-md bg-accent-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-accent-700">Generate Report</button>
        <a href="{{ route('sites.reports', $site) }}" class="text-xs font-medium text-accent-600 hover:text-accent-800">Set Up Schedule</a>
    </div>
@endif
