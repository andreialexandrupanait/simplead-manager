<x-dashboard.widget-container
    :title="$this->getTitle()"
    :widget-id="$widget->id"
    :loading="!$isLoaded"
    skeleton-type="stats"
    wire:init="loadWidget"
>
    @if($isLoaded && $this->data)
        <div class="grid grid-cols-2 gap-4">
            {{-- Backups Today --}}
            <a
                href="{{ route('backups.index') }}"
                class="group relative overflow-hidden rounded-lg border border-gray-200 bg-gradient-to-br from-green-50 to-white p-4 transition hover:border-green-300 hover:shadow-md"
            >
                <div class="relative z-10">
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Backups Today</div>
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-100">
                            <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">{{ $this->data['backups_today'] }}</div>
                    <div class="mt-1 text-xs text-green-600">Completed</div>
                </div>
            </a>

            {{-- Failed Backups --}}
            <a
                href="{{ route('backups.index') }}"
                class="group relative overflow-hidden rounded-lg border border-gray-200 bg-gradient-to-br from-red-50 to-white p-4 transition hover:border-red-300 hover:shadow-md"
            >
                <div class="relative z-10">
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Failed Backups</div>
                        <div class="flex h-8 w-8 items-center justify-center rounded-full {{ $this->data['failed_backups'] > 0 ? 'bg-red-100' : 'bg-gray-100' }}">
                            <svg class="h-4 w-4 {{ $this->data['failed_backups'] > 0 ? 'text-red-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-3xl font-bold {{ $this->data['failed_backups'] > 0 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ $this->data['failed_backups'] }}
                    </div>
                    <div class="mt-1 text-xs text-red-600">Last 24 hours</div>
                </div>
            </a>

            {{-- Total Storage --}}
            <div class="relative overflow-hidden rounded-lg border border-gray-200 bg-gradient-to-br from-blue-50 to-white p-4">
                <div class="relative z-10">
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">Total Storage</div>
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                            <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">{{ $this->data['total_storage_gb'] }}</div>
                    <div class="mt-1 text-xs text-blue-600">GB Used</div>
                </div>
            </div>

            {{-- Sites Without Backup --}}
            <a
                href="{{ route('backups.index') }}"
                class="group relative overflow-hidden rounded-lg border border-gray-200 bg-gradient-to-br from-orange-50 to-white p-4 transition hover:border-orange-300 hover:shadow-md"
            >
                <div class="relative z-10">
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-medium text-gray-500">No Recent Backup</div>
                        <div class="flex h-8 w-8 items-center justify-center rounded-full {{ $this->data['sites_without_backup'] > 0 ? 'bg-orange-100' : 'bg-gray-100' }}">
                            <svg class="h-4 w-4 {{ $this->data['sites_without_backup'] > 0 ? 'text-orange-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-2 text-3xl font-bold {{ $this->data['sites_without_backup'] > 0 ? 'text-orange-600' : 'text-gray-900' }}">
                        {{ $this->data['sites_without_backup'] }}
                    </div>
                    <div class="mt-1 text-xs text-orange-600">Sites (7+ days)</div>
                </div>
            </a>
        </div>

        {{-- Quick Action Link --}}
        <div class="mt-4">
            <a
                href="{{ route('backups.index') }}"
                class="flex w-full items-center justify-center gap-2 rounded-lg border border-purple-600 bg-purple-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-purple-700"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                </svg>
                Manage All Backups
            </a>
        </div>
    @endif
</x-dashboard.widget-container>
