<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="Core File Integrity" subtitle="Compare WordPress core files against official checksums from wordpress.org" />
        <x-ui.button wire:click="runCheck" wire:loading.attr="disabled">
            <svg class="mr-1.5 h-4 w-4 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="runCheck" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Run Check
        </x-ui.button>
    </div>

    {{-- Flash Messages --}}
    @if(session('integrity-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('integrity-success') }}</div>
    @endif

    @if($this->latestCheck)
        @php $check = $this->latestCheck; @endphp

        {{-- Status Overview --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 mb-6">
            <x-ui.card class="!p-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg {{ $check->status === 'clean' ? 'bg-green-100' : ($check->status === 'modified' ? 'bg-red-100' : 'bg-yellow-100') }}">
                        @if($check->status === 'clean')
                            <svg class="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        @elseif($check->status === 'modified')
                            <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        @else
                            <svg class="h-5 w-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        @endif
                    </div>
                    <div>
                        <p class="text-sm font-medium {{ $check->status === 'clean' ? 'text-green-700' : ($check->status === 'modified' ? 'text-red-700' : 'text-yellow-700') }}">
                            {{ $check->status_label }}
                        </p>
                        <p class="text-xs text-gray-500">Status</p>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card class="!p-4">
                <p class="text-2xl font-bold text-gray-900">{{ $check->wp_version ?? '—' }}</p>
                <p class="text-xs text-gray-500">WordPress Version</p>
            </x-ui.card>

            <x-ui.card class="!p-4">
                <p class="text-2xl font-bold {{ $check->modified_count > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $check->modified_count }}</p>
                <p class="text-xs text-gray-500">Modified Files</p>
            </x-ui.card>

            <x-ui.card class="!p-4">
                <p class="text-2xl font-bold {{ $check->missing_count > 0 ? 'text-yellow-600' : 'text-gray-900' }}">{{ $check->missing_count }}</p>
                <p class="text-xs text-gray-500">Missing Files</p>
            </x-ui.card>

            <x-ui.card class="!p-4">
                <p class="text-2xl font-bold {{ $check->unknown_count > 0 ? 'text-yellow-600' : 'text-gray-900' }}">{{ $check->unknown_count }}</p>
                <p class="text-xs text-gray-500">Unknown Files</p>
            </x-ui.card>
        </div>

        {{-- Error Message --}}
        @if($check->error_message)
            <div class="mb-6 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                <div class="flex items-start gap-2">
                    <svg class="h-5 w-5 text-red-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <p class="font-medium">Check Error</p>
                        <p class="mt-1">{{ $check->error_message }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- File Details (when issues found) --}}
        @if($check->status === 'modified')
            {{-- Modified Files --}}
            @if($check->modified_files && count($check->modified_files) > 0)
                <x-ui.card class="mb-6">
                    <button wire:click="toggleSection('modified')" class="flex w-full items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="h-2.5 w-2.5 rounded-full bg-red-500"></div>
                            <h3 class="text-sm font-semibold text-gray-900">Modified Files ({{ count($check->modified_files) }})</h3>
                        </div>
                        <svg class="h-5 w-5 text-gray-400 transition-transform {{ $expandedSection === 'modified' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    @if($expandedSection === 'modified')
                        <div class="mt-4 divide-y divide-gray-100">
                            @foreach($check->modified_files as $file)
                                <div class="py-3">
                                    <div class="flex items-center gap-2">
                                        <svg class="h-4 w-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span class="font-mono text-sm text-gray-900">{{ $file['path'] }}</span>
                                    </div>
                                    <div class="mt-1 ml-6 grid grid-cols-1 gap-1 sm:grid-cols-2">
                                        <div class="text-xs">
                                            <span class="text-gray-500">Expected:</span>
                                            <code class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-gray-700">{{ Str::limit($file['expected_hash'], 20) }}</code>
                                        </div>
                                        <div class="text-xs">
                                            <span class="text-gray-500">Actual:</span>
                                            <code class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-red-700">{{ Str::limit($file['actual_hash'], 20) }}</code>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Missing Files --}}
            @if($check->missing_files && count($check->missing_files) > 0)
                <x-ui.card class="mb-6">
                    <button wire:click="toggleSection('missing')" class="flex w-full items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="h-2.5 w-2.5 rounded-full bg-yellow-500"></div>
                            <h3 class="text-sm font-semibold text-gray-900">Missing Files ({{ count($check->missing_files) }})</h3>
                        </div>
                        <svg class="h-5 w-5 text-gray-400 transition-transform {{ $expandedSection === 'missing' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    @if($expandedSection === 'missing')
                        <div class="mt-4 space-y-1">
                            @foreach($check->missing_files as $path)
                                <div class="flex items-center gap-2 rounded-lg bg-yellow-50 px-3 py-2">
                                    <svg class="h-4 w-4 text-yellow-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span class="font-mono text-xs text-yellow-800">{{ $path }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Unknown Files --}}
            @if($check->unknown_files && count($check->unknown_files) > 0)
                <x-ui.card class="mb-6">
                    <button wire:click="toggleSection('unknown')" class="flex w-full items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="h-2.5 w-2.5 rounded-full bg-yellow-500"></div>
                            <h3 class="text-sm font-semibold text-gray-900">Unknown Files ({{ count($check->unknown_files) }})</h3>
                        </div>
                        <svg class="h-5 w-5 text-gray-400 transition-transform {{ $expandedSection === 'unknown' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    @if($expandedSection === 'unknown')
                        <div class="mt-4 space-y-1">
                            @foreach($check->unknown_files as $path)
                                <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
                                    <svg class="h-4 w-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span class="font-mono text-xs text-gray-700">{{ $path }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endif
        @endif

        {{-- Clean State --}}
        @if($check->status === 'clean')
            <x-ui.card class="mb-6">
                <div class="flex items-center gap-3 p-2">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-green-700">All Core Files Are Clean</p>
                        <p class="text-xs text-gray-500">All {{ number_format($check->total_files) }} WordPress core files match the official checksums for version {{ $check->wp_version }}.</p>
                    </div>
                </div>
            </x-ui.card>
        @endif

        {{-- Check History --}}
        @if($this->checkHistory->count() > 1)
            <x-ui.card>
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Check History</h3>
                <div class="divide-y divide-gray-100">
                    @foreach($this->checkHistory as $historyCheck)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-2 w-2 rounded-full {{ $historyCheck->status === 'clean' ? 'bg-green-500' : ($historyCheck->status === 'modified' ? 'bg-red-500' : 'bg-yellow-500') }}"></div>
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ $historyCheck->status_label }}</span>
                                    @if($historyCheck->wp_version)
                                        <span class="ml-2 text-xs text-gray-500">WP {{ $historyCheck->wp_version }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                @if($historyCheck->status === 'modified')
                                    <span class="text-xs text-gray-500">
                                        {{ $historyCheck->modified_count }}M / {{ $historyCheck->missing_count }}Mi / {{ $historyCheck->unknown_count }}U
                                    </span>
                                @endif
                                <p class="text-xs text-gray-400">{{ $historyCheck->checked_at?->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        {{-- Last Checked Footer --}}
        <div class="mt-4 text-center">
            <p class="text-xs text-gray-400">Last checked: {{ $check->checked_at?->format('M j, Y \a\t g:i A') ?? 'Never' }}</p>
        </div>
    @else
        {{-- No checks yet --}}
        <x-ui.card>
            <x-ui.empty-state
                title="No integrity checks yet"
                description="Run a core file integrity check to compare your WordPress installation against official checksums from wordpress.org."
                icon="shield"
            />
            <div class="mt-4 flex justify-center">
                <x-ui.button wire:click="runCheck" wire:loading.attr="disabled">
                    <svg class="mr-1.5 h-4 w-4 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="runCheck" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Run First Check
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
