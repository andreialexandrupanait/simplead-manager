<div @if(($this->activeBackup && in_array($this->activeBackup->status, ['pending', 'in_progress'])) || ($this->activeRestore && in_array($this->activeRestore->restore_status, ['pending', 'in_progress']))) wire:poll.1s="refreshProgress" @endif>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Backups</h1>
        <p class="mt-1 text-sm text-gray-500">{{ $site->name }} — Backup management</p>
    </div>

    @if(session('backup-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('backup-success') }}</div>
    @endif

    @if(session('backup-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('backup-error') }}</div>
    @endif

    {{-- Storage Quota Warning --}}
    @if($this->storageQuotaInfo && $this->storageQuotaInfo['level'] !== 'ok')
        <div class="mb-4 rounded-lg p-3 {{ $this->storageQuotaInfo['level'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-yellow-50 border border-yellow-200' }}">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 {{ $this->storageQuotaInfo['level'] === 'error' ? 'text-red-500' : 'text-yellow-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <span class="text-sm font-medium {{ $this->storageQuotaInfo['level'] === 'error' ? 'text-red-800' : 'text-yellow-800' }}">
                        Storage {{ $this->storageQuotaInfo['level'] === 'error' ? 'almost full' : 'running low' }}: {{ $this->storageQuotaInfo['used'] }} / {{ $this->storageQuotaInfo['total'] }} ({{ $this->storageQuotaInfo['percent'] }}%)
                    </span>
                </div>
            </div>
            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                <div class="h-1.5 rounded-full {{ $this->storageQuotaInfo['level'] === 'error' ? 'bg-red-500' : 'bg-yellow-500' }}"
                     style="width: {{ min($this->storageQuotaInfo['percent'], 100) }}%"></div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Quick Actions --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Quick Actions</h3>
            <div class="space-y-3">
                <x-ui.button wire:click="backupDatabase" wire:loading.attr="disabled" class="w-full">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" /></svg>
                    <span wire:loading.remove wire:target="backupDatabase">Backup Database</span>
                    <span wire:loading wire:target="backupDatabase">Queuing...</span>
                </x-ui.button>
                <x-ui.button wire:click="backupFull" wire:loading.attr="disabled" variant="secondary" class="w-full">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8" /></svg>
                    <span wire:loading.remove wire:target="backupFull">Full Backup</span>
                    <span wire:loading wire:target="backupFull">Queuing...</span>
                </x-ui.button>
            </div>
            <p class="mt-3 text-xs text-gray-400">Estimated full backup size: ~{{ $this->estimatedBackupSize }}</p>
        </x-ui.card>

        {{-- Schedule --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Schedule</h3>
            @if($this->backupConfig?->is_enabled)
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Status</span>
                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Active</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Frequency</span>
                        <span class="text-gray-900 font-medium">{{ ucfirst($this->backupConfig->frequency) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Type</span>
                        <span class="text-gray-900 font-medium">{{ ucfirst($this->backupConfig->type) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Next Backup</span>
                        <span class="text-gray-900 font-medium">{{ $this->backupConfig->next_backup_at?->diffForHumans() ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Retention</span>
                        <span class="text-gray-900 font-medium">{{ $this->backupConfig->retention_value }} {{ $this->backupConfig->retention_type === 'count' ? 'backups' : 'days' }}</span>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500">No backup schedule configured.</p>
            @endif
            <div class="mt-4">
                <x-ui.button variant="secondary" size="sm" x-on:click="$dispatch('open-schedule-form')">
                    Configure Schedule
                </x-ui.button>
            </div>
        </x-ui.card>

        {{-- Storage Usage --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Storage Usage</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Total Size</span>
                    <span class="text-gray-900 font-medium">{{ $this->storageUsage }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Backup Count</span>
                    <span class="text-gray-900 font-medium">{{ $site->backups()->where('status', 'completed')->count() }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Last Backup</span>
                    <span class="text-gray-900 font-medium">{{ $site->last_backup_at?->diffForHumans() ?? 'Never' }}</span>
                </div>
                @if($this->backupConfig?->last_backup_status)
                    <div class="flex justify-between">
                        <span class="text-gray-500">Last Status</span>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $this->backupConfig->last_backup_status === 'completed' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                            {{ ucfirst($this->backupConfig->last_backup_status) }}
                        </span>
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>

    {{-- Backup Progress --}}
    @if($this->activeBackup)
        @php
            $ab = $this->activeBackup;
            $abStatus = $ab->status;
            $abPercent = $ab->progress_percent;
            $abStage = $ab->stage;
            $abMessage = $ab->progress_message;
            $abStarted = $ab->started_at;
        @endphp
        <div
            class="mb-6"
            x-data="{
                dismissed: false,
                timer: null,
                pct: @js($abPercent),
                status: @js($abStatus),
                message: @js($abMessage ?? ''),
                stage: @js($abStage ?? ''),
            }"
            x-effect="
                let newPct = @js($abPercent);
                let newStatus = @js($abStatus);
                let newMsg = @js($abMessage ?? '');
                let newStage = @js($abStage ?? '');
                if (newPct > pct || newStatus !== status) { pct = newPct; }
                status = newStatus;
                message = newMsg;
                stage = newStage;
                if ((status === 'completed' || status === 'failed') && !timer) {
                    if (status === 'completed') { pct = 100; }
                    timer = setTimeout(() => { dismissed = true; $wire.dismissProgress(); }, 5000);
                }
            "
            x-show="!dismissed"
            x-transition
        >
            <x-ui.card>
                <div class="space-y-3">
                    {{-- Header --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <template x-if="status === 'pending' || status === 'in_progress'">
                                <svg class="h-5 w-5 animate-spin text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </template>
                            <template x-if="status === 'completed'">
                                <svg class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </template>
                            <template x-if="status === 'failed'">
                                <svg class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </template>
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ ucfirst($ab->type) }} Backup
                                <span x-show="status === 'pending'">Queued</span>
                                <span x-show="status === 'in_progress'">in Progress</span>
                                <span x-show="status === 'completed'">Complete</span>
                                <span x-show="status === 'failed'">Failed</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span x-show="stage && (status === 'pending' || status === 'in_progress')" x-text="stage.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())"></span>
                            @if($abStarted)
                                <span>{{ $abStarted->diffForHumans(null, true) }} elapsed</span>
                            @endif
                        </div>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            class="h-2 rounded-full"
                            :class="{
                                'bg-green-500': status === 'completed',
                                'bg-red-500': status === 'failed',
                                'bg-purple-500': status === 'pending' || status === 'in_progress',
                            }"
                            :style="'width: ' + Math.max(pct, status === 'pending' ? 15 : 0) + '%; transition: width 0.7s ease-out'"
                        ></div>
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span x-text="pct + '%'"></span>
                        <span x-show="status === 'failed' && message" x-text="message" class="text-red-600"></span>
                        <span x-show="status !== 'failed' && message" x-text="message"></span>
                        @if($abStatus === 'completed' && $ab->duration_seconds)
                            <span>Completed in {{ $ab->duration_seconds }}s</span>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Restore Progress --}}
    @if($this->activeRestore)
        @php
            $ar = $this->activeRestore;
            $arStatus = $ar->restore_status;
            $arPercent = $ar->restore_progress_percent;
            $arStage = $ar->restore_stage;
            $arMessage = $ar->restore_progress_message;
        @endphp
        <div
            class="mb-6"
            x-data="{
                dismissed: false,
                timer: null,
                pct: @js($arPercent),
                status: @js($arStatus),
                message: @js($arMessage ?? ''),
                stage: @js($arStage ?? ''),
            }"
            x-effect="
                let newPct = @js($arPercent);
                let newStatus = @js($arStatus);
                let newMsg = @js($arMessage ?? '');
                let newStage = @js($arStage ?? '');
                if (newPct > pct || newStatus !== status) { pct = newPct; }
                status = newStatus;
                message = newMsg;
                stage = newStage;
                if ((status === 'completed' || status === 'failed') && !timer) {
                    if (status === 'completed') { pct = 100; }
                    timer = setTimeout(() => { dismissed = true; $wire.dismissRestoreProgress(); }, 5000);
                }
            "
            x-show="!dismissed"
            x-transition
        >
            <x-ui.card>
                <div class="space-y-3">
                    {{-- Header --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <template x-if="status === 'pending' || status === 'in_progress'">
                                <svg class="h-5 w-5 animate-spin text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </template>
                            <template x-if="status === 'completed'">
                                <svg class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </template>
                            <template x-if="status === 'failed'">
                                <svg class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </template>
                            <h3 class="text-sm font-semibold text-gray-900">
                                Restore
                                <span x-show="status === 'pending'">Queued</span>
                                <span x-show="status === 'in_progress'">in Progress</span>
                                <span x-show="status === 'completed'">Complete</span>
                                <span x-show="status === 'failed'">Failed</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span x-show="stage && (status === 'pending' || status === 'in_progress')" x-text="stage.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())"></span>
                        </div>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            class="h-2 rounded-full"
                            :class="{
                                'bg-green-500': status === 'completed',
                                'bg-red-500': status === 'failed',
                                'bg-purple-500': status === 'pending' || status === 'in_progress',
                            }"
                            :style="'width: ' + Math.max(pct, status === 'pending' ? 15 : 0) + '%; transition: width 0.7s ease-out'"
                        ></div>
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span x-text="pct + '%'"></span>
                        <span x-show="status === 'failed' && message" x-text="message" class="text-red-600"></span>
                        <span x-show="status !== 'failed' && message" x-text="message"></span>
                    </div>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Backup History --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">Backup History</h3>

        @if($backupHistory->isEmpty())
            <p class="text-sm text-gray-500">No backups yet. Create your first backup using the buttons above.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Size</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Diff</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Storage</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($backupHistory as $backup)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-3 text-sm text-gray-900">
                                    {{ $backup->created_at->format('M d, Y H:i') }}
                                    <div class="text-xs text-gray-400">{{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}</div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ ucfirst($backup->type) }}</td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->file_size_formatted }}</td>
                                <td class="px-3 py-3 text-sm">
                                    @if($backup->size_diff_formatted)
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $backup->size_diff >= 0 ? 'bg-yellow-50 text-yellow-700' : 'bg-green-50 text-green-700' }}">
                                            {{ $backup->size_diff_formatted }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->storageDestination?->name ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ match($backup->status_color) {
                                            'green' => 'bg-green-50 text-green-700',
                                            'purple' => 'bg-purple-50 text-purple-700',
                                            'yellow' => 'bg-yellow-50 text-yellow-700',
                                            'red' => 'bg-red-50 text-red-700',
                                            default => 'bg-gray-50 text-gray-700',
                                        } }}">
                                        {{ ucfirst($backup->status) }}
                                    </span>
                                    @if($backup->is_locked)
                                        <span class="ml-1 inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700">Locked</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm max-w-[150px]"
                                    x-data="{ editing: false, notes: @js($backup->notes ?? '') }"
                                >
                                    <div x-show="!editing" @click="editing = true" class="cursor-pointer truncate text-gray-500 hover:text-gray-700" :title="notes || 'Click to add notes'">
                                        <span x-show="notes" x-text="notes"></span>
                                        <span x-show="!notes" class="text-gray-400 italic">Add note</span>
                                    </div>
                                    <div x-show="editing" x-cloak>
                                        <input type="text" x-model="notes"
                                            @keydown.enter="$wire.updateNotes({{ $backup->id }}, notes); editing = false"
                                            @keydown.escape="editing = false"
                                            @click.away="$wire.updateNotes({{ $backup->id }}, notes); editing = false"
                                            x-ref="notesInput"
                                            x-init="$watch('editing', v => { if(v) $nextTick(() => $refs.notesInput.focus()) })"
                                            class="w-full rounded border-gray-300 px-2 py-1 text-xs focus:border-purple-500 focus:ring-purple-500"
                                            placeholder="Add a note..."
                                        >
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($backup->status === 'completed')
                                            <button wire:click="downloadBackup({{ $backup->id }})"
                                                class="rounded p-1 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                                title="Download">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                            </button>
                                            <button wire:click="$dispatch('open-restore-confirmation', { backupId: {{ $backup->id }} })"
                                                class="rounded p-1 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                                title="Restore">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                            </button>
                                            <button wire:click="toggleLock({{ $backup->id }})"
                                                class="rounded p-1 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                                title="{{ $backup->is_locked ? 'Unlock' : 'Lock' }}">
                                                @if($backup->is_locked)
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                                @else
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" /></svg>
                                                @endif
                                            </button>
                                        @endif
                                        <button wire:click="deleteBackup({{ $backup->id }})"
                                            wire:confirm="Are you sure you want to delete this backup? This cannot be undone."
                                            class="rounded p-1 text-gray-400 hover:text-red-600 hover:bg-red-50"
                                            title="Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $backupHistory->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Sub-components --}}
    <livewire:sites.detail.components.backup-schedule-form :site="$site" />
    <livewire:sites.detail.components.restore-confirmation :site="$site" />
</div>
