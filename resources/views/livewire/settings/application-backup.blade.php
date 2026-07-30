@php
    $activeBackup = $this->activeBackup;
    $backupRunning = $awaitingBackup || ($activeBackup && in_array($activeBackup->status, ['pending', 'in_progress'], true));
@endphp

{{-- Three sections of the Backup & Data tab: what happened last, what is
     scheduled, and everything that has run. They used to be a 3-up KPI
     dashboard, a collapsed accordion and a bare card, each spaced with its own
     mb-6 on top of the wrapper's space-y-6 — 48px gutters on a page whose rest
     uses 24px. The wrapper spaces us now. --}}
<div class="space-y-6" @if($activeBackup || $awaitingBackup) wire:poll.2s="refreshProgress" @endif>

    {{-- ── Status ──────────────────────────────────────────────────────── --}}
    <x-settings.section
        id="backup-status"
        :title="__('Backup Status')"
        :subtitle="__('The last run, the schedule that produced it, and what it all takes up.')"
    >
        <x-slot:actions>
            <x-ui.button
                size="sm"
                wire:click="openCreateModal"
                wire:loading.attr="disabled"
                wire:target="openCreateModal"
                :disabled="$backupRunning"
            >
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="openCreateModal" />
                <x-icons.plus class="h-4 w-4" wire:loading.remove wire:target="openCreateModal" />
                {{ __('Create Backup Now') }}
            </x-ui.button>
        </x-slot:actions>

        <div class="grid grid-cols-1 gap-x-6 gap-y-5 lg:grid-cols-3">
            {{-- Last backup --}}
            <div>
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Last Backup') }}</p>
                @if($this->lastBackup)
                    <x-ui.info-row :label="__('Status')">
                        <x-ui.badge :variant="$this->lastBackup->status_color">{{ ucfirst($this->lastBackup->status) }}</x-ui.badge>
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Date')">{{ $this->lastBackup->created_at->diffForHumans() }}</x-ui.info-row>
                    <x-ui.info-row :label="__('Size')">{{ $this->lastBackup->file_size_formatted }}</x-ui.info-row>
                    <x-ui.info-row :label="__('Duration')">{{ $this->lastBackup->duration_formatted ?? '—' }}</x-ui.info-row>
                @else
                    <x-ui.info-row :label="__('Status')">
                        <x-ui.badge variant="gray">{{ __('No backups yet.') }}</x-ui.badge>
                    </x-ui.info-row>
                @endif
            </div>

            {{-- Schedule --}}
            <div>
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Schedule') }}</p>
                @if($this->config->is_enabled)
                    <x-ui.info-row :label="__('Status')">
                        <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Frequency')">{{ ucfirst($this->config->frequency) }}</x-ui.info-row>
                    <x-ui.info-row :label="__('Next Backup')">{{ $this->config->next_backup_at?->diffForHumans() ?? '—' }}</x-ui.info-row>
                    <x-ui.info-row :label="__('Retention')">
                        {{ $this->config->retention_value }}
                        {{ $this->config->retention_type === 'count' ? __('backups') : __('days') }}
                    </x-ui.info-row>
                @else
                    <x-ui.info-row :label="__('Status')">
                        <x-ui.badge variant="gray">{{ __('Disabled') }}</x-ui.badge>
                    </x-ui.info-row>
                    <p class="mt-2 text-xs text-gray-500">{{ __('Enable scheduled backups in the configuration below.') }}</p>
                @endif
            </div>

            {{-- Storage --}}
            <div>
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Storage') }}</p>
                <x-ui.info-row :label="__('Total Size')">{{ $this->totalStorageUsed }}</x-ui.info-row>
                <x-ui.info-row :label="__('Backup Count')">{{ $this->completedBackupCount }}</x-ui.info-row>
                <x-ui.info-row :label="__('Destination')">
                    {{ $this->config->storageDestination?->name ?? __('Local') }}
                </x-ui.info-row>
            </div>
        </div>

        {{-- Progress: queued job with no record yet --}}
        @if($awaitingBackup && ! $activeBackup)
            <div
                class="mt-5 rounded-lg border border-gray-200 p-4"
                x-data="{
                    startTime: Date.now(),
                    elapsed: '0s',
                    tick() {
                        let secs = Math.floor((Date.now() - this.startTime) / 1000);
                        if (secs < 60) this.elapsed = secs + 's';
                        else this.elapsed = Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
                    }
                }"
                x-init="setInterval(() => tick(), 1000)"
            >
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-ui.spinner size="md" class="text-accent-600" />
                            <h3 class="text-sm font-semibold text-gray-900">{{ __('Preparing Backup...') }}</h3>
                        </div>
                        <div class="text-xs text-gray-500">
                            <span x-text="elapsed"></span> {{ __('elapsed') }}
                        </div>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
                        <div class="h-3 animate-pulse rounded-full bg-accent-500" style="width: 10%"></div>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span class="font-medium text-gray-700">0%</span>
                        <span>{{ __('Waiting for backup job to start...') }}</span>
                    </div>
                </div>
            </div>
        @elseif($activeBackup)
            @php
                $ab = $activeBackup;
                $abStatus = $ab->status;
                $abPercent = $ab->progress;
                $abStarted = $ab->started_at;
                $abLog = $this->backupLogEntries;
                $lastLogMessage = ! empty($abLog) ? end($abLog)['message'] ?? '' : '';
            @endphp
            <div
                class="mt-5 rounded-lg border border-gray-200 p-4"
                x-data="{
                    dismissed: false,
                    timer: null,
                    pct: @js($abPercent),
                    status: @js($abStatus),
                    startedAt: @js($abStarted?->timestamp),
                    elapsed: '',
                    eta: '',
                    intervalId: null,
                    fmtDuration(secs) {
                        if (secs < 60) return secs + 's';
                        if (secs < 3600) return Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
                        return Math.floor(secs / 3600) + 'h ' + Math.floor((secs % 3600) / 60) + 'm';
                    },
                    tick() {
                        if (!this.startedAt) return;
                        let secs = Math.max(0, Math.floor(Date.now() / 1000) - this.startedAt);
                        this.elapsed = this.fmtDuration(secs);
                        if (this.pct > 5 && this.pct < 100 && (this.status === 'in_progress' || this.status === 'pending')) {
                            let totalEst = Math.round(secs / (this.pct / 100));
                            let remaining = Math.max(0, totalEst - secs);
                            this.eta = '~' + this.fmtDuration(remaining) + ' {{ __('remaining') }}';
                        } else if (this.pct >= 100 || this.status === 'completed' || this.status === 'failed') {
                            this.eta = '';
                        } else {
                            this.eta = '{{ __('Estimating...') }}';
                        }
                    }
                }"
                x-init="tick(); intervalId = setInterval(() => tick(), 1000)"
                x-effect="
                    let newPct = @js($abPercent);
                    let newStatus = @js($abStatus);
                    if (newPct > pct || newStatus !== status) { pct = newPct; }
                    status = newStatus;
                    tick();
                    if ((status === 'completed' || status === 'failed') && !timer) {
                        if (status === 'completed') { pct = 100; }
                        clearInterval(intervalId);
                        timer = setTimeout(() => { dismissed = true; $wire.dismissProgress(); }, 5000);
                    }
                "
                x-show="!dismissed"
                x-transition
            >
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <template x-if="status === 'pending' || status === 'in_progress'">
                                <x-ui.spinner size="md" class="text-accent-600" />
                            </template>
                            <template x-if="status === 'completed'">
                                <x-icons.check-circle class="h-5 w-5 text-green-500" />
                            </template>
                            <template x-if="status === 'failed'">
                                <x-icons.x-circle class="h-5 w-5 text-red-500" />
                            </template>
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ __('Application Backup') }}
                                <span x-show="status === 'pending'">{{ __('Queued') }}</span>
                                <span x-show="status === 'in_progress'">{{ __('in Progress') }}</span>
                                <span x-show="status === 'completed'">{{ __('Complete') }}</span>
                                <span x-show="status === 'failed'">{{ __('Failed') }}</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span x-show="elapsed" x-text="elapsed + ' {{ __('elapsed') }}'"></span>
                            <span x-show="eta" x-text="eta" class="font-medium text-accent-600"></span>
                        </div>
                    </div>

                    <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            class="h-3 rounded-full"
                            :class="{
                                'bg-green-500': status === 'completed',
                                'bg-red-500': status === 'failed',
                                'bg-accent-500': status === 'pending' || status === 'in_progress',
                            }"
                            :style="'width: ' + Math.max(pct, status === 'pending' ? 15 : 0) + '%; transition: width 0.7s ease-out'"
                        ></div>
                    </div>

                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span class="font-medium text-gray-700" x-text="pct + '%'"></span>
                        @if($abStatus === 'failed' && $ab->error_message)
                            <span class="text-red-600">{{ Str::limit($ab->error_message, 100) }}</span>
                        @endif
                        @if($abStatus === 'completed' && $ab->duration_seconds)
                            <span>{{ __('Completed in') }} {{ $ab->duration_formatted }}</span>
                        @endif
                        @if(in_array($abStatus, ['pending', 'in_progress']) && $lastLogMessage)
                            <span class="max-w-sm truncate text-gray-500">{{ $lastLogMessage }}</span>
                        @endif
                    </div>

                    {{-- Live log tail. Deliberately a dark surface in both themes
                         (terminal output); the border keeps it legible as a
                         distinct panel on the dark card. --}}
                    @if(! empty($abLog) && in_array($abStatus, ['pending', 'in_progress']))
                        <div class="mt-1 max-h-32 overflow-y-auto rounded-lg border border-gray-700 bg-gray-900 p-3">
                            @foreach(array_slice($abLog, -5) as $entry)
                                <div class="font-mono text-xs leading-5">
                                    <span class="text-gray-400">{{ $entry['time'] ?? '' }}</span>
                                    <span class="{{ str_contains($entry['message'] ?? '', 'FAILED') ? 'text-red-400' : (str_contains($entry['message'] ?? '', 'completed') ? 'text-green-400' : 'text-gray-200') }}">
                                        {{ $entry['message'] ?? '' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </x-settings.section>

    {{-- ── Configuration ──────────────────────────────────────────────── --}}
    <x-settings.section
        id="backup-config"
        :title="__('Backup Configuration')"
        :subtitle="__('When the scheduled backup runs, what it contains and how long it is kept.')"
    >
        <form wire:submit="saveConfig" class="space-y-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Enable scheduled backups') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Runs automatically at the time set below. Manual backups work either way.') }}</p>
                </div>
                <x-ui.toggle :enabled="$isEnabled" wire:click="$toggle('isEnabled')" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-ui.form-group :label="__('Frequency')" for="backup-frequency" error="frequency">
                    <x-ui.select id="backup-frequency" wire:model.live="frequency">
                        <option value="daily">{{ __('Daily') }}</option>
                        <option value="weekly">{{ __('Weekly') }}</option>
                        <option value="monthly">{{ __('Monthly') }}</option>
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Time')" for="backup-time" error="time">
                    <x-ui.input id="backup-time" wire:model="time" type="time" />
                </x-ui.form-group>

                <div x-show="$wire.frequency === 'weekly'" x-cloak>
                    <x-ui.form-group :label="__('Day of Week')" for="backup-day-of-week" error="dayOfWeek">
                        <x-ui.select id="backup-day-of-week" wire:model="dayOfWeek">
                            <option value="0">{{ __('Sunday') }}</option>
                            <option value="1">{{ __('Monday') }}</option>
                            <option value="2">{{ __('Tuesday') }}</option>
                            <option value="3">{{ __('Wednesday') }}</option>
                            <option value="4">{{ __('Thursday') }}</option>
                            <option value="5">{{ __('Friday') }}</option>
                            <option value="6">{{ __('Saturday') }}</option>
                        </x-ui.select>
                    </x-ui.form-group>
                </div>

                <div x-show="$wire.frequency === 'monthly'" x-cloak>
                    <x-ui.form-group :label="__('Day of Month')" for="backup-day-of-month" error="dayOfMonth">
                        <x-ui.select id="backup-day-of-month" wire:model="dayOfMonth">
                            @for($d = 1; $d <= 28; $d++)
                                <option value="{{ $d }}">{{ $d }}</option>
                            @endfor
                        </x-ui.select>
                    </x-ui.form-group>
                </div>

                <x-ui.form-group :label="__('Timezone')" for="backup-timezone" error="timezone">
                    <x-ui.select id="backup-timezone" wire:model="timezone">
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Backup Type')" for="backup-type" error="type">
                    <x-ui.select id="backup-type" wire:model="type">
                        <option value="full">{{ __('Full (Database + .env + Storage)') }}</option>
                        <option value="database">{{ __('Database Only') }}</option>
                        <option value="config">{{ __('Configuration Only (.env)') }}</option>
                        <option value="storage">{{ __('Storage Files Only') }}</option>
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Storage Destination')" for="backup-destination" error="storageDestinationId">
                    <x-ui.select id="backup-destination" wire:model="storageDestinationId">
                        <option value="">{{ __('Default') }}</option>
                        @foreach($this->storageDestinations as $dest)
                            <option value="{{ $dest->id }}">{{ $dest->name }} ({{ ucfirst($dest->type) }})</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.form-group>

                {{-- $components is written to the config on save but had no
                     control anywhere on the page, so a scheduled backup's
                     contents were unpickable. --}}
                <x-ui.form-group :label="__('Included Components')" error="components">
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 pt-1">
                        <x-ui.checkbox wire:model="components" value="database" :label="__('Database')" />
                        <x-ui.checkbox wire:model="components" value="env" :label="__('.env file')" />
                        <x-ui.checkbox wire:model="components" value="storage" :label="__('Storage files')" />
                    </div>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Retention Policy')" for="backup-retention-type" error="retentionType">
                    <x-ui.select id="backup-retention-type" wire:model.live="retentionType">
                        <option value="count">{{ __('Keep last N backups') }}</option>
                        <option value="days">{{ __('Keep for N days') }}</option>
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group
                    :label="__('Retention Value')"
                    for="backup-retention-value"
                    error="retentionValue"
                    :hint="$retentionType === 'count' ? __('Number of backups to keep') : __('Days to retain backups')"
                >
                    <x-ui.input id="backup-retention-value" wire:model="retentionValue" type="number" min="1" max="365" />
                </x-ui.form-group>
            </div>

        </form>

        {{-- Outside the <form> on purpose: x-ui.save-bar's button carries
             wire:click and no type, so inside a form it would both submit and
             call saveConfig — two round trips per click. Enter still submits. --}}
        <x-ui.save-bar
            target="saveConfig"
            :message="__('Changes apply from the next scheduled run.')"
            :label="__('Save Configuration')"
        />
    </x-settings.section>

    {{-- ── History ────────────────────────────────────────────────────── --}}
    <x-settings.section
        id="backup-history"
        :title="__('Backup History')"
        :subtitle="__('Every application backup, newest first.')"
    >
        <x-slot:actions>
            <x-ui.select wire:model.live="statusFilter" class="w-40" aria-label="{{ __('Filter by status') }}">
                <option value="all">{{ __('All Statuses') }}</option>
                <option value="completed">{{ __('Completed') }}</option>
                <option value="in_progress">{{ __('In Progress') }}</option>
                <option value="failed">{{ __('Failed') }}</option>
            </x-ui.select>
        </x-slot:actions>

        @if($backups->isEmpty())
            <x-ui.empty-state
                icon="database"
                :title="__('No backups yet')"
                :description="__('Create your first backup with the button in Backup Status above, or enable the schedule.')"
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th>{{ __('Date') }}</x-ui.th>
                            <x-ui.th>{{ __('Type') }}</x-ui.th>
                            <x-ui.th>{{ __('Components') }}</x-ui.th>
                            <x-ui.th>{{ __('Size') }}</x-ui.th>
                            <x-ui.th>{{ __('Duration') }}</x-ui.th>
                            <x-ui.th>{{ __('Storage') }}</x-ui.th>
                            <x-ui.th class="text-right">{{ __('Actions') }}</x-ui.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($backups as $backup)
                            @php
                                $tDownload = "downloadBackup({$backup->id})";
                                $tRestore = "openRestoreModal({$backup->id})";
                                $tEnv = "viewEnv({$backup->id})";
                                $tLock = "toggleLock({$backup->id})";
                                $tLog = "viewLog({$backup->id})";
                                $tDelete = "deleteBackup({$backup->id})";
                            @endphp
                            <tr wire:key="backup-{{ $backup->id }}" class="hover:bg-gray-50">
                                <x-ui.td>
                                    <x-ui.badge :variant="$backup->status_color">{{ ucfirst($backup->status) }}</x-ui.badge>
                                    @if($backup->is_locked)
                                        <x-ui.badge variant="blue" class="ml-1">{{ __('Locked') }}</x-ui.badge>
                                    @endif
                                </x-ui.td>
                                <x-ui.td class="text-gray-900">
                                    {{ $backup->created_at->format('M d, Y H:i') }}
                                    <div class="text-xs text-gray-500">{{ ucfirst($backup->trigger) }}</div>
                                </x-ui.td>
                                <x-ui.td>{{ ucfirst($backup->type) }}</x-ui.td>
                                <x-ui.td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($backup->components ?? [] as $comp)
                                            <x-ui.badge variant="gray">{{ $comp }}</x-ui.badge>
                                        @endforeach
                                    </div>
                                </x-ui.td>
                                <x-ui.td>{{ $backup->file_size_formatted }}</x-ui.td>
                                <x-ui.td>{{ $backup->duration_formatted ?? '—' }}</x-ui.td>
                                <x-ui.td>{{ $backup->storageDestination?->name ?? __('Local') }}</x-ui.td>
                                <x-ui.td class="text-right">
                                    <div class="flex items-center justify-end gap-0.5">
                                        @if($backup->status === 'completed')
                                            <x-ui.button
                                                variant="ghost" size="xs"
                                                wire:click="downloadBackup({{ $backup->id }})"
                                                wire:target="{{ $tDownload }}"
                                                wire:loading.attr="disabled"
                                                :title="__('Download')"
                                            >
                                                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tDownload }}" />
                                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.remove wire:target="{{ $tDownload }}"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                                <span class="sr-only">{{ __('Download') }}</span>
                                            </x-ui.button>

                                            @if(in_array('database', $backup->components ?? []))
                                                <x-ui.button
                                                    variant="ghost" size="xs"
                                                    wire:click="openRestoreModal({{ $backup->id }})"
                                                    wire:target="{{ $tRestore }}"
                                                    wire:loading.attr="disabled"
                                                    :title="__('Restore Database')"
                                                >
                                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tRestore }}" />
                                                    <x-icons.refresh-cw class="h-4 w-4" wire:loading.remove wire:target="{{ $tRestore }}" />
                                                    <span class="sr-only">{{ __('Restore Database') }}</span>
                                                </x-ui.button>
                                            @endif

                                            @if(in_array('env', $backup->components ?? []))
                                                <x-ui.button
                                                    variant="ghost" size="xs"
                                                    wire:click="viewEnv({{ $backup->id }})"
                                                    wire:target="{{ $tEnv }}"
                                                    wire:loading.attr="disabled"
                                                    :title="__('View .env')"
                                                >
                                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tEnv }}" />
                                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.remove wire:target="{{ $tEnv }}"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                                    <span class="sr-only">{{ __('View .env') }}</span>
                                                </x-ui.button>
                                            @endif

                                            <x-ui.button
                                                variant="ghost" size="xs"
                                                wire:click="toggleLock({{ $backup->id }})"
                                                wire:target="{{ $tLock }}"
                                                wire:loading.attr="disabled"
                                                :title="$backup->is_locked ? __('Unlock') : __('Lock')"
                                            >
                                                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tLock }}" />
                                                <span wire:loading.remove wire:target="{{ $tLock }}">
                                                    @if($backup->is_locked)
                                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                                    @else
                                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" /></svg>
                                                    @endif
                                                </span>
                                                <span class="sr-only">{{ $backup->is_locked ? __('Unlock') : __('Lock') }}</span>
                                            </x-ui.button>
                                        @endif

                                        <x-ui.button
                                            variant="ghost" size="xs"
                                            wire:click="viewLog({{ $backup->id }})"
                                            wire:target="{{ $tLog }}"
                                            wire:loading.attr="disabled"
                                            :title="__('View Log')"
                                        >
                                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tLog }}" />
                                            <x-icons.file-text class="h-4 w-4" wire:loading.remove wire:target="{{ $tLog }}" />
                                            <span class="sr-only">{{ __('View Log') }}</span>
                                        </x-ui.button>

                                        <x-ui.button
                                            variant="ghost" size="xs"
                                            wire:click="deleteBackup({{ $backup->id }})"
                                            wire:target="{{ $tDelete }}"
                                            wire:loading.attr="disabled"
                                            wire:confirm="{{ __('Are you sure you want to delete this backup? This cannot be undone.') }}"
                                            :title="__('Delete')"
                                        >
                                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tDelete }}" />
                                            <x-icons.trash class="h-4 w-4 text-red-500" wire:loading.remove wire:target="{{ $tDelete }}" />
                                            <span class="sr-only">{{ __('Delete') }}</span>
                                        </x-ui.button>
                                    </div>
                                </x-ui.td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $backups->links() }}
            </div>
        @endif
    </x-settings.section>

    {{-- ── Modals ─────────────────────────────────────────────────────── --}}
    <x-ui.modal name="create-backup" maxWidth="md">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Create Application Backup') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('Choose what to include in this backup.') }}</p>

        <div class="mt-4 space-y-4">
            <x-ui.form-group :label="__('Backup Type')" for="create-backup-type" error="createType">
                <x-ui.select id="create-backup-type" wire:model.live="createType">
                    <option value="full">{{ __('Full (Database + .env + Storage)') }}</option>
                    <option value="database">{{ __('Database Only') }}</option>
                    <option value="config">{{ __('Configuration Only (.env)') }}</option>
                    <option value="storage">{{ __('Storage Files Only') }}</option>
                </x-ui.select>
            </x-ui.form-group>

            <x-ui.form-group :label="__('Additional Components')">
                <div class="space-y-2 pt-1">
                    <x-ui.checkbox wire:model="createIncludeLogs" :label="__('Include log files')" />
                    <x-ui.checkbox wire:model="createIncludeCodebase" :label="__('Include codebase (excluding vendor, node_modules, .git)')" />
                </div>
            </x-ui.form-group>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-create-backup')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button wire:click="createBackup" wire:target="createBackup" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="createBackup" />
                {{ __('Start Backup') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal name="restore-confirm" maxWidth="md">
        <h2 class="text-lg font-semibold text-red-600">{{ __('Restore Database') }}</h2>
        <x-ui.alert type="error" class="mt-2">
            <p class="font-medium">{{ __('Warning: This will replace your current database!') }}</p>
            <p class="mt-1">{{ __('This action will overwrite all current data with the backup contents. This cannot be undone. Make sure you have a recent backup before proceeding.') }}</p>
        </x-ui.alert>

        <div class="mt-4">
            <x-ui.checkbox
                wire:model="restoreConfirmed"
                class="text-red-600 focus:ring-red-500"
                :label="__('I understand this will overwrite my current database')"
            />
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-restore-confirm')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button variant="danger" wire:click="restoreDatabase" wire:target="restoreDatabase" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="restoreDatabase" />
                {{ __('Restore Database') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal name="view-env" maxWidth="lg">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('.env File Contents') }}</h2>
            <x-ui.button
                type="button" variant="ghost" size="sm"
                x-on:click="navigator.clipboard.writeText($refs.envContent.value); $dispatch('notify', { type: 'success', message: '{{ __('Copied to clipboard') }}' })"
            >
                <x-icons.copy class="h-4 w-4" />
                {{ __('Copy') }}
            </x-ui.button>
        </div>
        <textarea
            x-ref="envContent"
            readonly
            rows="20"
            aria-label="{{ __('.env File Contents') }}"
            class="mt-3 block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-800"
        >{{ $envContent }}</textarea>
        <div class="mt-4 flex justify-end">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-view-env')">
                {{ __('Close') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal name="restore-verification" maxWidth="md">
        <div class="flex items-center gap-2">
            @if(($restoreVerification['status'] ?? '') === 'ok')
                <x-icons.check-circle class="h-6 w-6 text-green-500" />
                <h2 class="text-lg font-semibold text-green-700">{{ __('Restore Verified') }}</h2>
            @elseif(($restoreVerification['status'] ?? '') === 'warning')
                <x-icons.alert-triangle class="h-6 w-6 text-yellow-500" />
                <h2 class="text-lg font-semibold text-yellow-700">{{ __('Restore Completed with Differences') }}</h2>
            @else
                <x-icons.info class="h-6 w-6 text-blue-500" />
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Restore Completed') }}</h2>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            @if(($restoreVerification['status'] ?? '') === 'no_baseline')
                <p class="text-sm text-gray-600">{{ $restoreVerification['message'] ?? __('No baseline data available for comparison.') }}</p>
            @else
                <div class="grid grid-cols-3 gap-3">
                    <x-ui.stat-box :label="__('Tables checked')">{{ $restoreVerification['tables_checked'] ?? 0 }}</x-ui.stat-box>
                    <x-ui.stat-box :label="__('Matched')">
                        <span class="text-green-700">{{ $restoreVerification['tables_matched'] ?? 0 }}</span>
                    </x-ui.stat-box>
                    <x-ui.stat-box :label="__('Different')">
                        <span class="{{ ($restoreVerification['tables_different'] ?? 0) > 0 ? 'text-yellow-700' : '' }}">
                            {{ $restoreVerification['tables_different'] ?? 0 }}
                        </span>
                    </x-ui.stat-box>
                </div>

                @if(! empty($restoreVerification['details']))
                    <x-ui.alert type="warning">
                        <h4 class="mb-2 text-xs font-semibold">{{ __('Tables with differences:') }}</h4>
                        <div class="space-y-1">
                            @foreach($restoreVerification['details'] as $detail)
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-mono">{{ $detail['table'] }}</span>
                                    <span>
                                        @if($detail['status'] === 'missing')
                                            {{ __('table missing') }}
                                        @else
                                            {{ __('expected') }} {{ $detail['expected'] }} {{ __('rows, got') }} {{ $detail['actual'] }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs">{{ __('Minor differences in tables like activity_logs or app_backups are expected, as the restore itself creates new records.') }}</p>
                    </x-ui.alert>
                @endif
            @endif
        </div>

        <div class="mt-5 flex justify-end">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-restore-verification')">
                {{ __('Close') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal name="view-log" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Backup Log') }}</h2>
        {{-- Terminal surface on purpose: dark in both themes, with a border so
             it still reads as a panel on the dark modal. --}}
        <div class="mt-3 max-h-96 overflow-y-auto rounded-lg border border-gray-700 bg-gray-900 p-4">
            @forelse($logEntries as $entry)
                <div class="font-mono text-xs leading-5">
                    <span class="text-gray-400">{{ $entry['time'] ?? '' }}</span>
                    <span class="{{ str_contains($entry['message'] ?? '', 'FAILED') ? 'text-red-400' : (str_contains($entry['message'] ?? '', 'completed') ? 'text-green-400' : 'text-gray-200') }}">
                        {{ $entry['message'] ?? '' }}
                    </span>
                </div>
            @empty
                <p class="text-xs text-gray-400">{{ __('No log entries available.') }}</p>
            @endforelse
        </div>
        <div class="mt-4 flex justify-end">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-view-log')">
                {{ __('Close') }}
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
