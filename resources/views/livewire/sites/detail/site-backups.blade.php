<div {!! ($this->trackingBackupId || $this->trackingRestoreBackupId) ? 'wire:poll.3s="pollProgress"' : '' !!}>
    {{-- Header --}}
    <x-ui.page-header title="{{ __('Backups') }}" subtitle="{{ __('Create, schedule, and restore site backups') }}" />

    <x-ui.flash-alert type="success" key="backup-success" />
    <x-ui.flash-alert type="error" key="backup-error" />

    {{-- Storage Quota Warning --}}
    @if($this->storageQuotaInfo && $this->storageQuotaInfo['level'] !== 'ok')
        <div class="mb-4 rounded-lg p-3 {{ $this->storageQuotaInfo['level'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-yellow-50 border border-yellow-200' }}">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg aria-hidden="true" class="h-4 w-4 {{ $this->storageQuotaInfo['level'] === 'error' ? 'text-red-500' : 'text-yellow-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <span class="text-sm font-medium {{ $this->storageQuotaInfo['level'] === 'error' ? 'text-red-800' : 'text-yellow-800' }}">
                        {{ __('Storage') }} {{ $this->storageQuotaInfo['level'] === 'error' ? __('almost full') : __('running low') }}: {{ $this->storageQuotaInfo['used'] }} / {{ $this->storageQuotaInfo['total'] }} ({{ $this->storageQuotaInfo['percent'] }}%)
                    </span>
                </div>
            </div>
            <x-ui.progress-bar
                :percent="$this->storageQuotaInfo['percent']"
                :color="$this->storageQuotaInfo['level'] === 'error' ? 'red' : 'yellow'"
                size="sm"
                class="mt-2"
            />
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
        {{-- Quick Actions --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Quick Actions') }}</h3>
            <div class="space-y-3">
                <x-ui.button wire:click="backupDatabase" wire:loading.attr="disabled" class="w-full">
                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" /></svg>
                    <span wire:loading.remove wire:target="backupDatabase">{{ __('Backup Database') }}</span>
                    <span wire:loading wire:target="backupDatabase">{{ __('Queuing...') }}</span>
                </x-ui.button>
                <x-ui.button wire:click="backupFull" wire:loading.attr="disabled" variant="secondary" class="w-full">
                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8" /></svg>
                    <span wire:loading.remove wire:target="backupFull">{{ __('Full Backup') }}</span>
                    <span wire:loading wire:target="backupFull">{{ __('Queuing...') }}</span>
                </x-ui.button>
                @if($this->hasFullBackupWithManifest)
                    <x-ui.button wire:click="backupIncremental" wire:loading.attr="disabled" variant="secondary" class="w-full">
                        <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" /></svg>
                        <span wire:loading.remove wire:target="backupIncremental">{{ __('Incremental Backup') }}</span>
                        <span wire:loading wire:target="backupIncremental">{{ __('Queuing...') }}</span>
                    </x-ui.button>
                @else
                    <p class="text-xs text-gray-400 italic">{{ __('Run a full backup first to enable incremental backups.') }}</p>
                @endif
            </div>
            <p class="mt-3 text-xs text-gray-400">{{ __('Estimated full backup size') }}: ~{{ $this->estimatedBackupSize }}</p>
        </x-ui.card>

        {{-- Schedule --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Schedule') }}</h3>
            @if($this->backupConfig?->is_enabled)
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Status') }}</span>
                        <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Frequency') }}</span>
                        <span class="text-gray-900 font-medium">{{ ucfirst($this->backupConfig->frequency) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Type') }}</span>
                        <span class="text-gray-900 font-medium">{{ ucfirst($this->backupConfig->type) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Next Backup') }}</span>
                        <span class="text-gray-900 font-medium">{{ $this->backupConfig->next_backup_at?->diffForHumans() ?? '—' }}</span>
                    </div>
                    @if($this->backupConfig->incremental_frequency)
                        <div class="flex justify-between">
                            <span class="text-gray-500">{{ __('Incremental') }}</span>
                            <x-ui.badge variant="purple">{{ __('Enabled') }}</x-ui.badge>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Retention') }}</span>
                        <span class="text-gray-900 font-medium">{{ $this->backupConfig->retention_value }} {{ $this->backupConfig->retention_type === 'count' ? __('chains') : __('days') }}</span>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500">{{ __('No backup schedule configured.') }}</p>
            @endif
            <div class="mt-4">
                <x-ui.button variant="secondary" size="sm" x-on:click="$dispatch('open-schedule-form')">
                    {{ __('Configure Schedule') }}
                </x-ui.button>
            </div>
        </x-ui.card>

        {{-- Storage Usage --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Storage Usage') }}</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">{{ __('Total Size') }}</span>
                    <span class="text-gray-900 font-medium">{{ $this->storageUsage }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">{{ __('Backup Count') }}</span>
                    <span class="text-gray-900 font-medium">{{ $site->backups()->where('status', 'completed')->count() }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">{{ __('Last Backup') }}</span>
                    <span class="text-gray-900 font-medium">{{ $site->last_backup_at?->diffForHumans() ?? __('Never') }}</span>
                </div>
                @if($this->backupConfig?->last_backup_status)
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Last Status') }}</span>
                        <x-ui.badge :variant="$this->backupConfig->last_backup_status === 'completed' ? 'green' : 'red'">
                            {{ ucfirst($this->backupConfig->last_backup_status) }}
                        </x-ui.badge>
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
                    timer = setTimeout(() => { $wire.dismissProgress(); }, status === 'completed' ? 5000 : 30000);
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
                                <x-ui.spinner size="md" class="text-accent-600" />
                            </template>
                            <template x-if="status === 'completed'">
                                <svg aria-hidden="true" class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </template>
                            <template x-if="status === 'failed'">
                                <svg aria-hidden="true" class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </template>
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ ucfirst($ab->type) }} Backup
                                <span x-show="status === 'pending'">{{ __('Queued') }}</span>
                                <span x-show="status === 'in_progress'">{{ __('in Progress') }}</span>
                                <span x-show="status === 'completed'">{{ __('Complete') }}</span>
                                <span x-show="status === 'failed'">{{ __('Failed') }}</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span x-show="stage && (status === 'pending' || status === 'in_progress')" x-text="stage.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())"></span>
                            @if($abStarted)
                                <span>{{ $abStarted->diffForHumans(null, true) }} {{ __('elapsed') }}</span>
                            @endif
                            <template x-if="status === 'pending' || status === 'in_progress'">
                                <button wire:click="cancelBackup" wire:confirm="{{ __('Are you sure you want to cancel this backup?') }}" class="text-red-400 hover:text-red-600 font-medium" title="{{ __('Cancel backup') }}">
                                    {{ __('Cancel') }}
                                </button>
                            </template>
                            <template x-if="status === 'completed' || status === 'failed'">
                                <button @click="dismissed = true; $wire.dismissProgress()" class="text-gray-400 hover:text-gray-600" title="Dismiss">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            class="h-2 rounded-full"
                            :class="{
                                'bg-green-500': status === 'completed',
                                'bg-red-500': status === 'failed',
                                'bg-accent-500': status === 'pending' || status === 'in_progress',
                            }"
                            :style="'width: ' + Math.max(pct, status === 'pending' ? 15 : 0) + '%; transition: width 0.7s ease-out'"
                        ></div>
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span x-show="status !== 'failed'" x-text="pct + '%'"></span>
                        <span x-show="status !== 'failed' && message" x-text="message"></span>
                        @if($abStatus === 'completed' && $ab->duration_seconds)
                            <span>{{ __('Completed in :seconds s', ['seconds' => $ab->duration_seconds]) }}</span>
                        @endif
                    </div>
                    {{-- Error detail for failed backups --}}
                    <div x-show="status === 'failed'" x-cloak class="rounded-md bg-red-50 border border-red-200 p-2.5 text-xs text-red-700" x-text="message"></div>

                    {{-- Activity Log --}}
                    @if(count($this->progressLog) > 0)
                        <div x-data="{ logOpen: status === 'pending' || status === 'in_progress' }">
                            <button @click="logOpen = !logOpen" class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-700 transition">
                                <svg aria-hidden="true" class="h-3 w-3 transition-transform" :class="logOpen && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                                <span x-text="logOpen ? 'Activity Log' : 'Show log ({{ count($this->progressLog) }} entries)'"></span>
                            </button>
                            <div x-show="logOpen" x-collapse>
                                <div
                                    class="mt-2 max-h-40 overflow-y-auto rounded-lg bg-gray-900 p-3 font-mono text-xs leading-relaxed"
                                    x-ref="backupLogPanel"
                                    x-effect="if (logOpen) { $nextTick(() => { $refs.backupLogPanel.scrollTop = $refs.backupLogPanel.scrollHeight }) }"
                                >
                                    @foreach($this->progressLog as $entry)
                                        <div class="{{ str_starts_with($entry['message'] ?? '', 'FAILED:') ? 'text-red-400' : 'text-green-400' }}">
                                            <span class="text-gray-500">[{{ $entry['time'] }}]</span> {{ $entry['message'] }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
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
                                <x-ui.spinner size="md" class="text-accent-600" />
                            </template>
                            <template x-if="status === 'completed'">
                                <svg aria-hidden="true" class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </template>
                            <template x-if="status === 'failed'">
                                <svg aria-hidden="true" class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </template>
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ __('Restore') }}
                                <span x-show="status === 'pending'">{{ __('Queued') }}</span>
                                <span x-show="status === 'in_progress'">{{ __('in Progress') }}</span>
                                <span x-show="status === 'completed'">{{ __('Complete') }}</span>
                                <span x-show="status === 'failed'">{{ __('Failed') }}</span>
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
                                'bg-accent-500': status === 'pending' || status === 'in_progress',
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

                    {{-- Activity Log --}}
                    @if(count($this->restoreProgressLog) > 0)
                        <div x-data="{ logOpen: status === 'pending' || status === 'in_progress' }">
                            <button @click="logOpen = !logOpen" class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-700 transition">
                                <svg aria-hidden="true" class="h-3 w-3 transition-transform" :class="logOpen && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                                <span x-text="logOpen ? 'Activity Log' : 'Show log ({{ count($this->restoreProgressLog) }} entries)'"></span>
                            </button>
                            <div x-show="logOpen" x-collapse>
                                <div
                                    class="mt-2 max-h-40 overflow-y-auto rounded-lg bg-gray-900 p-3 font-mono text-xs leading-relaxed"
                                    x-ref="restoreLogPanel"
                                    x-effect="if (logOpen) { $nextTick(() => { $refs.restoreLogPanel.scrollTop = $refs.restoreLogPanel.scrollHeight }) }"
                                >
                                    @foreach($this->restoreProgressLog as $entry)
                                        <div class="{{ str_starts_with($entry['message'] ?? '', 'FAILED:') ? 'text-red-400' : 'text-green-400' }}">
                                            <span class="text-gray-500">[{{ $entry['time'] }}]</span> {{ $entry['message'] }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Backup History --}}
    <x-ui.card class="overflow-hidden !p-0"
        x-data="{
            selected: [],
            get allSelected() {
                return this.selected.length === {{ $backupHistory->count() }} && this.selected.length > 0;
            },
            toggleAll() {
                if (this.allSelected) {
                    this.selected = [];
                } else {
                    this.selected = [{{ $backupHistory->pluck('id')->implode(',') }}];
                }
            }
        }"
    >
        <div class="flex items-center justify-between px-5 pt-4 pb-3">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Backup History') }}</h3>
        </div>

        {{-- Bulk action bar --}}
        <div x-show="selected.length > 0" x-cloak class="hidden md:flex items-center gap-3 border-b border-gray-200 bg-accent-50/50 px-5 py-2.5">
            <span class="text-sm font-medium text-accent-700" x-text="selected.length + ' selected'"></span>
            <button
                @click="if (confirm('Delete ' + selected.length + ' backup(s)? This cannot be undone.')) { $wire.bulkDelete(selected).then(() => selected = []) }"
                class="inline-flex items-center rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition"
            >
                <x-icons.x class="mr-1 h-3.5 w-3.5" />
                {{ __('Delete Selected') }}
            </button>
        </div>

        @if($backupHistory->isEmpty())
            <p class="text-sm text-gray-500 text-center py-8 px-5">{{ __('No backups yet. Create your first backup using the buttons above.') }}</p>
        @else
            {{-- Mobile cards --}}
            <div class="md:hidden divide-y divide-gray-100 px-4 py-2">
                @foreach($backupHistory as $backup)
                    <div class="py-3">
                        <div class="rounded-lg border border-gray-200 p-3"
                             x-data="{ editing: false, notes: @js($backup->notes ?? '') }">
                            {{-- Top row: type + status badges --}}
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ ucfirst($backup->type) }} Backup</span>
                                    @if($backup->type === 'incremental' && $backup->files_changed_count !== null)
                                        <span class="ml-1 text-xs text-gray-400">
                                            ({{ $backup->files_changed_count }} changed{{ $backup->files_deleted_count ? ", {$backup->files_deleted_count} deleted" : '' }})
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1 flex-shrink-0">
                                    <x-ui.badge :variant="$backup->status_color">{{ $backup->status->label() }}</x-ui.badge>
                                    @if($backup->is_locked)
                                        <x-ui.badge variant="purple">Locked</x-ui.badge>
                                    @endif
                                </div>
                            </div>

                            {{-- Date + trigger --}}
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $backup->created_at->format('M d, Y H:i') }}
                                <span class="mx-1 text-gray-300">&middot;</span>
                                {{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}
                            </div>

                            {{-- Size + storage + optional diff --}}
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                <span>{{ $backup->file_size_formatted }}</span>
                                @if($backup->storageDestination)
                                    <span class="text-gray-300">&middot;</span>
                                    <span>{{ $backup->storageDestination->name }}</span>
                                @endif
                                @if($backup->size_diff_formatted && $backup->size_diff !== 0)
                                    <x-ui.badge :variant="$backup->size_diff >= 0 ? 'yellow' : 'green'">{{ $backup->size_diff_formatted }}</x-ui.badge>
                                @endif
                                @if($backup->type === 'incremental' && $backup->parentBackup)
                                    <span class="text-gray-300">&middot;</span>
                                    <span>Based on {{ $backup->parentBackup->created_at->format('M d') }}</span>
                                @endif
                            </div>

                            {{-- Restore / error badges --}}
                            @if($backup->status === \App\Enums\BackupStatus::Failed && $backup->error_message)
                                <div class="mt-1">
                                    <button @click="$dispatch('show-error-detail', { title: 'Backup Error', message: @js($backup->error_message) })"
                                        class="inline-flex items-center gap-1 text-xs text-red-500 hover:text-red-700">
                                        <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        View error
                                    </button>
                                </div>
                            @endif
                            @if($backup->restore_status === \App\Enums\BackupStatus::Failed)
                                <div class="mt-1 flex items-center gap-1">
                                    <x-ui.badge variant="red">Restore failed</x-ui.badge>
                                    @if($backup->restore_error_message)
                                        <button @click="$dispatch('show-error-detail', { title: 'Restore Error', message: @js($backup->restore_error_message) })"
                                            class="text-red-500 hover:text-red-700">
                                            <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </button>
                                    @endif
                                </div>
                            @elseif($backup->last_restored_at)
                                <div class="mt-1">
                                    <x-ui.badge variant="blue" title="Restored {{ $backup->last_restored_at->diffForHumans() }}">Restored</x-ui.badge>
                                </div>
                            @endif

                            {{-- Action buttons --}}
                            <div class="mt-2.5 flex items-center gap-1">
                                @if($backup->status === \App\Enums\BackupStatus::Completed)
                                    <button wire:click="downloadBackup({{ $backup->id }})"
                                        class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 hover:text-accent-600 hover:border-accent-300 transition"
                                        title="{{ __('Download') }}">
                                        <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                        {{ __('Download') }}
                                    </button>
                                    @if($backup->localExportInProgress())
                                        <span class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-500 cursor-wait" title="{{ __('Preparing Local by Flywheel export…') }}">
                                            <svg aria-hidden="true" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                            {{ __('Local…') }}
                                        </span>
                                    @elseif($backup->localExportReady())
                                        <button wire:click="downloadBackupForLocal({{ $backup->id }})"
                                            class="inline-flex items-center gap-1 rounded border border-accent-200 bg-accent-50 px-2 py-1 text-xs text-accent-700 hover:border-accent-300 transition"
                                            title="{{ __('Download for Local by Flywheel') }}">
                                            <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25M3 5.25v9.75A1.5 1.5 0 004.5 16.5h15a1.5 1.5 0 001.5-1.5V5.25M3 5.25A1.5 1.5 0 014.5 3.75h15A1.5 1.5 0 0121 5.25M3 5.25h18" /></svg>
                                            {{ __('Local zip') }}
                                        </button>
                                    @else
                                        <button wire:click="exportBackupForLocal({{ $backup->id }})"
                                            class="inline-flex items-center gap-1 rounded border {{ $backup->local_export_status === 'failed' ? 'border-red-200 text-red-600 hover:border-red-300' : 'border-gray-200 text-gray-600 hover:text-accent-600 hover:border-accent-300' }} bg-white px-2 py-1 text-xs transition"
                                            title="{{ $backup->local_export_status === 'failed' ? __('Local export failed — click to retry').($backup->local_export_error ? ': '.\Illuminate\Support\Str::limit($backup->local_export_error, 120) : '') : __('Export for Local by Flywheel') }}">
                                            <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25M3 5.25v9.75A1.5 1.5 0 004.5 16.5h15a1.5 1.5 0 001.5-1.5V5.25M3 5.25A1.5 1.5 0 014.5 3.75h15A1.5 1.5 0 0121 5.25M3 5.25h18" /></svg>
                                            {{ $backup->local_export_status === 'failed' ? __('Retry Local') : __('For Local') }}
                                        </button>
                                    @endif
                                    <button wire:click="$dispatch('open-restore-confirmation', { backupId: {{ $backup->id }} })"
                                        class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 hover:text-accent-600 hover:border-accent-300 transition"
                                        title="{{ __('Restore') }}">
                                        <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                        {{ __('Restore') }}
                                    </button>
                                    <button wire:click="toggleLock({{ $backup->id }})"
                                        class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 hover:text-accent-600 hover:border-accent-300 transition"
                                        title="{{ $backup->is_locked ? __('Unlock') : __('Lock') }}">
                                        @if($backup->is_locked)
                                            <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                            {{ __('Unlock') }}
                                        @else
                                            <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" /></svg>
                                            {{ __('Lock') }}
                                        @endif
                                    </button>
                                @endif
                                <button wire:click="deleteBackup({{ $backup->id }})"
                                    wire:confirm="{{ __('Are you sure you want to delete this backup? This cannot be undone.') }}"
                                    class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs text-red-500 hover:text-red-700 hover:border-red-300 transition"
                                    title="{{ __('Delete') }}">
                                    <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    {{ __('Delete') }}
                                </button>
                            </div>

                            {{-- Inline notes --}}
                            <div class="mt-2 text-xs">
                                <div x-show="!editing" @click="editing = true"
                                    class="cursor-pointer text-gray-500 hover:text-gray-700"
                                    :title="notes || '{{ __('Click to add notes') }}'">
                                    <span class="text-gray-400">{{ __('Notes') }}: </span>
                                    <span x-show="notes" x-text="notes"></span>
                                    <span x-show="!notes" class="text-gray-400 italic">{{ __('Add note') }}</span>
                                </div>
                                <div x-show="editing" x-cloak>
                                    <input type="text" x-model="notes"
                                        @keydown.enter="$wire.updateNotes({{ $backup->id }}, notes); editing = false"
                                        @keydown.escape="editing = false"
                                        @click.outside="$wire.updateNotes({{ $backup->id }}, notes); editing = false"
                                        x-ref="notesInputMobile{{ $backup->id }}"
                                        x-init="$watch('editing', v => { if(v) $nextTick(() => $refs['notesInputMobile{{ $backup->id }}'].focus()) })"
                                        class="w-full rounded border-gray-300 px-2 py-1 text-xs focus:border-accent-500 focus:ring-accent-500"
                                        placeholder="{{ __('Add a note...') }}"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="w-10 px-3 py-2">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll()"
                                       class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                            </th>
                            <x-ui.sortable-th column="created_at" :sortBy="$sortBy" :sortDir="$sortDir">Date</x-ui.sortable-th>
                            <x-ui.sortable-th column="type" :sortBy="$sortBy" :sortDir="$sortDir">Type</x-ui.sortable-th>
                            <x-ui.sortable-th column="file_size" :sortBy="$sortBy" :sortDir="$sortDir">Size</x-ui.sortable-th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Diff') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Storage') }}</th>
                            <x-ui.sortable-th column="status" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Status') }}</x-ui.sortable-th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Notes') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($backupHistory as $backup)
                            <tr class="hover:bg-gray-50" :class="selected.includes({{ $backup->id }}) && 'bg-accent-50/50'">
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $backup->id }}" x-model.number="selected"
                                           class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-900">
                                    {{ $backup->created_at->format('M d, Y H:i') }}
                                    <div class="text-xs text-gray-400">{{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}</div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">
                                    {{ ucfirst($backup->type) }}
                                    @if($backup->type === 'incremental')
                                        <div class="text-xs text-gray-400">
                                            @if($backup->files_changed_count !== null)
                                                {{ $backup->files_changed_count }} changed{{ $backup->files_deleted_count ? ", {$backup->files_deleted_count} deleted" : '' }}
                                            @endif
                                        </div>
                                        @if($backup->parentBackup)
                                            <div class="text-xs text-gray-400">Based on {{ $backup->parentBackup->created_at->format('M d') }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->file_size_formatted }}</td>
                                <td class="px-3 py-3 text-sm">
                                    @if($backup->size_diff_formatted)
                                        <x-ui.badge :variant="$backup->size_diff >= 0 ? 'yellow' : 'green'">{{ $backup->size_diff_formatted }}</x-ui.badge>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->storageDestination?->name ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <x-ui.badge :variant="$backup->status_color">{{ $backup->status->label() }}</x-ui.badge>
                                        @if($backup->is_locked)
                                            <x-ui.badge variant="purple">Locked</x-ui.badge>
                                        @endif
                                        @if($backup->status === \App\Enums\BackupStatus::Failed && $backup->error_message)
                                            <button @click="$dispatch('show-error-detail', { title: 'Backup Error', message: @js($backup->error_message) })"
                                                class="text-red-500 hover:text-red-700" title="View error details">
                                                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            </button>
                                        @endif
                                        @if($backup->restore_status === \App\Enums\BackupStatus::Failed)
                                            <x-ui.badge variant="red">Restore failed</x-ui.badge>
                                            @if($backup->restore_error_message)
                                                <button @click="$dispatch('show-error-detail', { title: 'Restore Error', message: @js($backup->restore_error_message) })"
                                                    class="text-red-500 hover:text-red-700" title="View restore error">
                                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                </button>
                                            @endif
                                        @elseif($backup->last_restored_at)
                                            <x-ui.badge variant="blue" title="Restored {{ $backup->last_restored_at->diffForHumans() }}">Restored</x-ui.badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-sm max-w-[150px]"
                                    x-data="{ editing: false, notes: @js($backup->notes ?? '') }"
                                >
                                    <div x-show="!editing" @click="editing = true" class="cursor-pointer truncate text-gray-500 hover:text-gray-700" :title="notes || '{{ __('Click to add notes') }}'">
                                        <span x-show="notes" x-text="notes"></span>
                                        <span x-show="!notes" class="text-gray-400 italic">{{ __('Add note') }}</span>
                                    </div>
                                    <div x-show="editing" x-cloak>
                                        <input type="text" x-model="notes"
                                            @keydown.enter="$wire.updateNotes({{ $backup->id }}, notes); editing = false"
                                            @keydown.escape="editing = false"
                                            @click.outside="$wire.updateNotes({{ $backup->id }}, notes); editing = false"
                                            x-ref="notesInput"
                                            x-init="$watch('editing', v => { if(v) $nextTick(() => $refs.notesInput.focus()) })"
                                            class="w-full rounded border-gray-300 px-2 py-1 text-xs focus:border-accent-500 focus:ring-accent-500"
                                            placeholder="{{ __('Add a note...') }}"
                                        >
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($backup->status === \App\Enums\BackupStatus::Completed)
                                            <button wire:click="downloadBackup({{ $backup->id }})"
                                                class="rounded p-1 text-gray-400 hover:text-accent-600 hover:bg-accent-50"
                                                title="{{ __('Download') }}">
                                                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                            </button>
                                            @if($backup->localExportInProgress())
                                                <span class="p-1 text-gray-400 cursor-wait" title="{{ __('Preparing Local by Flywheel export…') }}">
                                                    <svg aria-hidden="true" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                                </span>
                                            @elseif($backup->localExportReady())
                                                <button wire:click="downloadBackupForLocal({{ $backup->id }})"
                                                    class="rounded p-1 text-accent-600 hover:text-accent-700 hover:bg-accent-50"
                                                    title="{{ __('Download for Local by Flywheel') }}">
                                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25M3 5.25v9.75A1.5 1.5 0 004.5 16.5h15a1.5 1.5 0 001.5-1.5V5.25M3 5.25A1.5 1.5 0 014.5 3.75h15A1.5 1.5 0 0121 5.25M3 5.25h18" /></svg>
                                                </button>
                                            @else
                                                <button wire:click="exportBackupForLocal({{ $backup->id }})"
                                                    class="rounded p-1 {{ $backup->local_export_status === 'failed' ? 'text-red-500 hover:text-red-700 hover:bg-red-50' : 'text-gray-400 hover:text-accent-600 hover:bg-accent-50' }}"
                                                    title="{{ $backup->local_export_status === 'failed' ? __('Local export failed — click to retry').($backup->local_export_error ? ': '.\Illuminate\Support\Str::limit($backup->local_export_error, 120) : '') : __('Export for Local by Flywheel') }}">
                                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25M3 5.25v9.75A1.5 1.5 0 004.5 16.5h15a1.5 1.5 0 001.5-1.5V5.25M3 5.25A1.5 1.5 0 014.5 3.75h15A1.5 1.5 0 0121 5.25M3 5.25h18" /></svg>
                                                </button>
                                            @endif
                                            <button wire:click="$dispatch('open-restore-confirmation', { backupId: {{ $backup->id }} })"
                                                class="rounded p-1 text-gray-400 hover:text-accent-600 hover:bg-accent-50"
                                                title="{{ __('Restore') }}">
                                                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                            </button>
                                            <button wire:click="toggleLock({{ $backup->id }})"
                                                class="rounded p-1 text-gray-400 hover:text-accent-600 hover:bg-accent-50"
                                                title="{{ $backup->is_locked ? __('Unlock') : __('Lock') }}">
                                                @if($backup->is_locked)
                                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                                @else
                                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" /></svg>
                                                @endif
                                            </button>
                                        @endif
                                        <button wire:click="deleteBackup({{ $backup->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this backup? This cannot be undone.') }}"
                                            class="rounded p-1 text-gray-400 hover:text-red-600 hover:bg-red-50"
                                            title="{{ __('Delete') }}">
                                            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
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

    {{-- Shared error detail modal --}}
    <div x-data="{ errorTitle: '', errorMessage: '' }"
         x-on:show-error-detail.window="errorTitle = $event.detail.title; errorMessage = $event.detail.message; $dispatch('open-modal-error-detail')">
        <x-ui.modal name="error-detail" maxWidth="2xl">
            <h3 class="text-base font-semibold text-red-900 mb-3" x-text="errorTitle"></h3>
            <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                <p class="text-sm text-red-700 whitespace-pre-wrap break-words select-text" x-text="errorMessage"></p>
            </div>
            <div class="mt-4 flex justify-end">
                <x-ui.button variant="secondary" size="sm" x-on:click="$dispatch('close-modal-error-detail')">
                    {{ __('Close') }}
                </x-ui.button>
            </div>
        </x-ui.modal>
    </div>
</div>
