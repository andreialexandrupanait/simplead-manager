@props(['site'])

@php $activeSchedule = $site->reportSchedules->first(); @endphp

@if($activeSchedule)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Reports</span>
        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Active</span>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Frequency</span>
            <span class="font-medium text-gray-900">{{ ucfirst($activeSchedule->frequency ?? 'N/A') }}</span>
        </div>
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Total schedules</span>
            <span class="font-medium text-gray-900">{{ $site->reportSchedules->count() }}</span>
        </div>
        @if($activeSchedule->next_run_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Next report</span>
                <span class="font-medium text-gray-900">{{ $activeSchedule->next_run_at->format('M j, g:ia') }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
        <button wire:click="generateQuickReport({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Generate Report</button>
        <a href="{{ route('sites.reports', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Reports</a>
    </div>
@else
    <p class="text-sm text-gray-500">No reports scheduled</p>
    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
        <button wire:click="generateQuickReport({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Generate Report</button>
        <a href="{{ route('sites.reports', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">Set Up Schedule</a>
    </div>
@endif
