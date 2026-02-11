<x-dashboard.widget-container
    :title="$this->getTitle()"
    :widget-id="$widget->id"
    :loading="!$isLoaded"
    wire:init="loadWidget"
>
    @if($isLoaded && $this->data)
        <div class="grid gap-2 sm:grid-cols-2">
            {{-- Add New Site --}}
            <a
                href="{{ route('sites.create') }}"
                class="group flex items-center gap-3 rounded-lg border border-gray-200 p-3 transition hover:border-purple-300 hover:bg-purple-50"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-100 text-purple-600 transition group-hover:bg-purple-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">Add New Site</div>
                    <div class="text-xs text-gray-500">Create site</div>
                </div>
            </a>

            {{-- Bulk Sync All Sites --}}
            <button
                wire:click="performAction('bulk_sync')"
                class="group flex items-center gap-3 rounded-lg border border-gray-200 p-3 text-left transition hover:border-blue-300 hover:bg-blue-50"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 transition group-hover:bg-blue-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">Bulk Sync</div>
                    <div class="text-xs text-gray-500">Sync all sites</div>
                </div>
            </button>

            {{-- Run All Backups --}}
            <button
                wire:click="performAction('run_backups')"
                class="group flex items-center gap-3 rounded-lg border border-gray-200 p-3 text-left transition hover:border-green-300 hover:bg-green-50"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-green-100 text-green-600 transition group-hover:bg-green-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">Run Backups</div>
                    <div class="text-xs text-gray-500">Backup all sites</div>
                </div>
            </button>

            {{-- Generate Report --}}
            <button
                wire:click="performAction('generate_report')"
                class="group flex items-center gap-3 rounded-lg border border-gray-200 p-3 text-left transition hover:border-orange-300 hover:bg-orange-50"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-orange-100 text-orange-600 transition group-hover:bg-orange-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">Generate Report</div>
                    <div class="text-xs text-gray-500">Create report</div>
                </div>
            </button>

            {{-- Check All Uptime --}}
            <button
                wire:click="performAction('check_uptime')"
                class="group flex items-center gap-3 rounded-lg border border-gray-200 p-3 text-left transition hover:border-indigo-300 hover:bg-indigo-50"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 transition group-hover:bg-indigo-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">Check Uptime</div>
                    <div class="text-xs text-gray-500">Monitor all sites</div>
                </div>
            </button>

            {{-- View Analytics --}}
            <a
                href="{{ route('dashboard') }}"
                class="group flex items-center gap-3 rounded-lg border border-gray-200 p-3 transition hover:border-cyan-300 hover:bg-cyan-50"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-cyan-100 text-cyan-600 transition group-hover:bg-cyan-200">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900">View Analytics</div>
                    <div class="text-xs text-gray-500">Full dashboard</div>
                </div>
            </a>
        </div>
    @endif
</x-dashboard.widget-container>
