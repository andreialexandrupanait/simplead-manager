<div @if($this->activeBackup || $awaitingBackup) wire:poll.2s="refreshProgress" @endif>
    @include('livewire.settings.partials.settings-tabs')

    {{-- Status Overview --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Last Backup --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Last Backup</h3>
            @if($this->lastBackup)
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Status</span>
                        <x-ui.badge :variant="$this->lastBackup->status_color">{{ ucfirst($this->lastBackup->status) }}</x-ui.badge>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Date</span>
                        <span class="text-gray-900 font-medium">{{ $this->lastBackup->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Size</span>
                        <span class="text-gray-900 font-medium">{{ $this->lastBackup->file_size_formatted }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Duration</span>
                        <span class="text-gray-900 font-medium">{{ $this->lastBackup->duration_formatted ?? '—' }}</span>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500">No backups yet.</p>
            @endif
        </x-ui.card>

        {{-- Next Scheduled --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Schedule</h3>
            @if($this->config->is_enabled)
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Status</span>
                        <x-ui.badge variant="green">Active</x-ui.badge>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Frequency</span>
                        <span class="text-gray-900 font-medium">{{ ucfirst($this->config->frequency) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Next Backup</span>
                        <span class="text-gray-900 font-medium">{{ $this->config->next_backup_at?->diffForHumans() ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Retention</span>
                        <span class="text-gray-900 font-medium">{{ $this->config->retention_value }} {{ $this->config->retention_type === 'count' ? 'backups' : 'days' }}</span>
                    </div>
                </div>
            @else
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Status</span>
                        <x-ui.badge variant="gray">Disabled</x-ui.badge>
                    </div>
                    <p class="text-gray-500 text-xs mt-2">Enable scheduled backups in the configuration below.</p>
                </div>
            @endif
        </x-ui.card>

        {{-- Storage Usage --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Storage</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Total Size</span>
                    <span class="text-gray-900 font-medium">{{ $this->totalStorageUsed }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Backup Count</span>
                    <span class="text-gray-900 font-medium">{{ $this->completedBackupCount }}</span>
                </div>
            </div>
            <div class="mt-4">
                <x-ui.button
                    wire:click="openCreateModal"
                    class="w-full"
                    :disabled="$awaitingBackup || ($this->activeBackup && in_array($this->activeBackup->status, ['pending', 'in_progress']))"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    Create Backup Now
                </x-ui.button>
            </div>
        </x-ui.card>
    </div>

    {{-- Backup Progress --}}
    @if($awaitingBackup && !$this->activeBackup)
        {{-- Awaiting: job dispatched but record not yet created --}}
        <div class="mb-6" x-data="{
            startTime: Date.now(),
            elapsed: '0s',
            tick() {
                let secs = Math.floor((Date.now() - this.startTime) / 1000);
                if (secs < 60) this.elapsed = secs + 's';
                else this.elapsed = Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
            }
        }" x-init="setInterval(() => tick(), 1000)">
            <x-ui.card>
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-ui.spinner size="md" class="text-purple-600" />
                            <h3 class="text-sm font-semibold text-gray-900">Preparing Backup...</h3>
                        </div>
                        <div class="text-xs text-gray-500">
                            <span x-text="elapsed"></span> elapsed
                        </div>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
                        <div class="h-3 rounded-full bg-purple-500 animate-pulse" style="width: 10%"></div>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span class="font-medium text-gray-700">0%</span>
                        <span>Waiting for backup job to start...</span>
                    </div>
                </div>
            </x-ui.card>
        </div>
    @elseif($this->activeBackup)
        @php
            $ab = $this->activeBackup;
            $abStatus = $ab->status;
            $abPercent = $ab->progress;
            $abStarted = $ab->started_at;
            $abLog = $this->backupLogEntries;
            $lastLogMessage = !empty($abLog) ? end($abLog)['message'] ?? '' : '';
        @endphp
        <div
            class="mb-6"
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
                        this.eta = '~' + this.fmtDuration(remaining) + ' remaining';
                    } else if (this.pct >= 100 || this.status === 'completed' || this.status === 'failed') {
                        this.eta = '';
                    } else {
                        this.eta = 'Estimating...';
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
            <x-ui.card>
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <template x-if="status === 'pending' || status === 'in_progress'">
                                <x-ui.spinner size="md" class="text-purple-600" />
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
                                Application Backup
                                <span x-show="status === 'pending'">Queued</span>
                                <span x-show="status === 'in_progress'">in Progress</span>
                                <span x-show="status === 'completed'">Complete</span>
                                <span x-show="status === 'failed'">Failed</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span x-show="elapsed" x-text="elapsed + ' elapsed'"></span>
                            <span x-show="eta" x-text="eta" class="text-purple-600 font-medium"></span>
                        </div>
                    </div>

                    <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            class="h-3 rounded-full"
                            :class="{
                                'bg-green-500': status === 'completed',
                                'bg-red-500': status === 'failed',
                                'bg-purple-500': status === 'pending' || status === 'in_progress',
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
                            <span>Completed in {{ $ab->duration_formatted }}</span>
                        @endif
                        @if(in_array($abStatus, ['pending', 'in_progress']) && $lastLogMessage)
                            <span class="text-gray-500 truncate max-w-sm">{{ $lastLogMessage }}</span>
                        @endif
                    </div>

                    {{-- Live log entries --}}
                    @if(!empty($abLog) && in_array($abStatus, ['pending', 'in_progress']))
                        <div class="mt-1 rounded-lg bg-gray-900 p-3 max-h-32 overflow-y-auto">
                            @foreach(array_slice($abLog, -5) as $entry)
                                <div class="font-mono text-xs leading-5">
                                    <span class="text-gray-500">{{ $entry['time'] ?? '' }}</span>
                                    <span class="{{ str_contains($entry['message'] ?? '', 'FAILED') ? 'text-red-400' : (str_contains($entry['message'] ?? '', 'completed') ? 'text-green-400' : 'text-gray-300') }}">
                                        {{ $entry['message'] ?? '' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Configuration --}}
    <div class="mb-6" x-data="{ open: false }">
        <x-ui.card>
            <div class="flex items-center justify-between cursor-pointer" @click="open = !open">
                <h3 class="text-base font-semibold text-gray-900">Backup Configuration</h3>
                <svg class="h-5 w-5 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>

            <div x-show="open" x-cloak x-transition class="mt-4">
                <form wire:submit="saveConfig" class="space-y-4">
                    {{-- Enable toggle --}}
                    <x-ui.checkbox wire:model.live="isEnabled" label="Enable scheduled backups" />

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {{-- Frequency --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Frequency</label>
                            <x-ui.select wire:model.live="frequency" class="mt-1">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </x-ui.select>
                        </div>

                        {{-- Time --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Time</label>
                            <x-ui.input wire:model="time" type="time" class="mt-1" />
                            @error('time') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Day of week (weekly) --}}
                        <div x-show="$wire.frequency === 'weekly'">
                            <label class="block text-sm font-medium text-gray-700">Day of Week</label>
                            <x-ui.select wire:model="dayOfWeek" class="mt-1">
                                <option value="0">Sunday</option>
                                <option value="1">Monday</option>
                                <option value="2">Tuesday</option>
                                <option value="3">Wednesday</option>
                                <option value="4">Thursday</option>
                                <option value="5">Friday</option>
                                <option value="6">Saturday</option>
                            </x-ui.select>
                        </div>

                        {{-- Day of month (monthly) --}}
                        <div x-show="$wire.frequency === 'monthly'">
                            <label class="block text-sm font-medium text-gray-700">Day of Month</label>
                            <x-ui.select wire:model="dayOfMonth" class="mt-1">
                                @for($d = 1; $d <= 28; $d++)
                                    <option value="{{ $d }}">{{ $d }}</option>
                                @endfor
                            </x-ui.select>
                        </div>

                        {{-- Timezone --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Timezone</label>
                            <x-ui.select wire:model="timezone" class="mt-1">
                                @foreach(timezone_identifiers_list() as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        {{-- Backup type --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Backup Type</label>
                            <x-ui.select wire:model="type" class="mt-1">
                                <option value="full">Full (Database + .env + Storage)</option>
                                <option value="database">Database Only</option>
                                <option value="config">Configuration Only (.env)</option>
                                <option value="storage">Storage Files Only</option>
                            </x-ui.select>
                        </div>

                        {{-- Storage destination --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Storage Destination</label>
                            <x-ui.select wire:model="storageDestinationId" class="mt-1">
                                <option value="">Default</option>
                                @foreach($this->storageDestinations as $dest)
                                    <option value="{{ $dest->id }}">{{ $dest->name }} ({{ ucfirst($dest->type) }})</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        {{-- Retention type --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Retention Policy</label>
                            <x-ui.select wire:model="retentionType" class="mt-1">
                                <option value="count">Keep last N backups</option>
                                <option value="days">Keep for N days</option>
                            </x-ui.select>
                        </div>

                        {{-- Retention value --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Retention Value</label>
                            <x-ui.input wire:model="retentionValue" type="number" min="1" max="365" class="mt-1" />
                            <p class="mt-1 text-xs text-gray-400">{{ $retentionType === 'count' ? 'Number of backups to keep' : 'Days to retain backups' }}</p>
                            @error('retentionValue') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Encryption --}}
                    <div class="border-t pt-4">
                        <div class="mb-3">
                            <x-ui.checkbox wire:model.live="encryptBackup" label="Encrypt backups (AES-256-CBC)" />
                        </div>

                        @if($encryptBackup)
                            <div class="max-w-sm">
                                <label class="block text-sm font-medium text-gray-700">Encryption Password</label>
                                <x-ui.input wire:model="encryptionPassword" type="password" placeholder="Minimum 8 characters" class="mt-1" />
                                <p class="mt-1 text-xs text-gray-400">Store this password safely. Without it, encrypted backups cannot be restored.</p>
                                @error('encryptionPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end pt-2">
                        <x-ui.button type="submit">Save Configuration</x-ui.button>
                    </div>
                </form>
            </div>
        </x-ui.card>
    </div>

    {{-- Backup History --}}
    <x-ui.card>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-gray-900">Backup History</h3>
            <x-ui.select wire:model.live="statusFilter" class="w-40">
                <option value="all">All Statuses</option>
                <option value="completed">Completed</option>
                <option value="in_progress">In Progress</option>
                <option value="failed">Failed</option>
            </x-ui.select>
        </div>

        @if($backups->isEmpty())
            <p class="text-sm text-gray-500 py-4 text-center">No backups yet. Create your first backup using the button above.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Components</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Size</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Storage</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($backups as $backup)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-3">
                                    <x-ui.badge :variant="$backup->status_color">{{ ucfirst($backup->status) }}</x-ui.badge>
                                    @if($backup->is_locked)
                                        <x-ui.badge variant="purple" class="ml-1">Locked</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-900">
                                    {{ $backup->created_at->format('M d, Y H:i') }}
                                    <div class="text-xs text-gray-400">{{ ucfirst($backup->trigger) }}</div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ ucfirst($backup->type) }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($backup->components ?? [] as $comp)
                                            <x-ui.badge variant="gray">{{ $comp }}</x-ui.badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->file_size_formatted }}</td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->duration_formatted ?? '—' }}</td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->storageDestination?->name ?? 'Local' }}</td>
                                <td class="px-3 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($backup->status === 'completed')
                                            {{-- Download --}}
                                            <button wire:click="downloadBackup({{ $backup->id }})"
                                                class="rounded p-1 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                                title="Download">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                            </button>
                                            {{-- Restore Database --}}
                                            @if(in_array('database', $backup->components ?? []))
                                                <button wire:click="openRestoreModal({{ $backup->id }})"
                                                    class="rounded p-1 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                                    title="Restore Database">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                                </button>
                                            @endif
                                            {{-- View .env --}}
                                            @if(in_array('env', $backup->components ?? []))
                                                <button wire:click="viewEnv({{ $backup->id }})"
                                                    class="rounded p-1 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                                    title="View .env">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                                </button>
                                            @endif
                                            {{-- Lock/Unlock --}}
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
                                        {{-- View Log --}}
                                        <button wire:click="viewLog({{ $backup->id }})"
                                            class="rounded p-1 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                            title="View Log">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                        </button>
                                        {{-- Delete --}}
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
                {{ $backups->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Create Backup Modal --}}
    <x-ui.modal name="create-backup" maxWidth="md">
        <h2 class="text-lg font-semibold text-gray-900">Create Application Backup</h2>
        <p class="mt-1 text-sm text-gray-500">Choose what to include in this backup.</p>

        <div class="mt-4 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Backup Type</label>
                <x-ui.select wire:model.live="createType" class="mt-1">
                    <option value="full">Full (Database + .env + Storage)</option>
                    <option value="database">Database Only</option>
                    <option value="config">Configuration Only (.env)</option>
                    <option value="storage">Storage Files Only</option>
                </x-ui.select>
            </div>

            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-700">Additional Components</label>
                <x-ui.checkbox wire:model="createIncludeLogs" label="Include log files" />
                <x-ui.checkbox wire:model="createIncludeCodebase" label="Include codebase (excluding vendor, node_modules, .git)" />
            </div>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-create-backup')">
                Cancel
            </x-ui.button>
            <x-ui.button wire:click="createBackup" x-on:click="$dispatch('close-modal-create-backup')">
                Start Backup
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Restore Confirmation Modal --}}
    <x-ui.modal name="restore-confirm" maxWidth="md">
        <h2 class="text-lg font-semibold text-red-600">Restore Database</h2>
        <div class="mt-2 rounded-lg bg-red-50 border border-red-200 p-3">
            <p class="text-sm text-red-800 font-medium">Warning: This will replace your current database!</p>
            <p class="text-sm text-red-700 mt-1">This action will overwrite all current data with the backup contents. This cannot be undone. Make sure you have a recent backup before proceeding.</p>
        </div>

        <div class="mt-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="restoreConfirmed" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                <span class="text-sm text-gray-700">I understand this will overwrite my current database</span>
            </label>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-restore-confirm')">
                Cancel
            </x-ui.button>
            <x-ui.button variant="danger" wire:click="restoreDatabase" x-on:click="$dispatch('close-modal-restore-confirm')">
                Restore Database
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- View .env Modal --}}
    <x-ui.modal name="view-env" maxWidth="lg">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">.env File Contents</h2>
            <button
                @click="navigator.clipboard.writeText($refs.envContent.value); $dispatch('notify', { type: 'success', message: 'Copied to clipboard' })"
                class="rounded-lg px-3 py-1.5 text-xs font-medium text-purple-600 hover:bg-purple-50"
            >
                Copy
            </button>
        </div>
        <textarea
            x-ref="envContent"
            readonly
            rows="20"
            class="mt-3 block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-800"
        >{{ $envContent }}</textarea>
        <div class="mt-4 flex justify-end">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-view-env')">
                Close
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Restore Verification Modal --}}
    <x-ui.modal name="restore-verification" maxWidth="md">
        <div class="flex items-center gap-2">
            @if(($restoreVerification['status'] ?? '') === 'ok')
                <svg class="h-6 w-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <h2 class="text-lg font-semibold text-green-700">Restore Verified</h2>
            @elseif(($restoreVerification['status'] ?? '') === 'warning')
                <svg class="h-6 w-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                <h2 class="text-lg font-semibold text-yellow-700">Restore Completed with Differences</h2>
            @else
                <svg class="h-6 w-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <h2 class="text-lg font-semibold text-gray-900">Restore Completed</h2>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            @if(($restoreVerification['status'] ?? '') === 'no_baseline')
                <p class="text-sm text-gray-600">{{ $restoreVerification['message'] ?? 'No baseline data available for comparison.' }}</p>
            @else
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="text-lg font-bold text-gray-900">{{ $restoreVerification['tables_checked'] ?? 0 }}</div>
                        <div class="text-xs text-gray-500">Tables checked</div>
                    </div>
                    <div class="rounded-lg bg-green-50 p-3">
                        <div class="text-lg font-bold text-green-700">{{ $restoreVerification['tables_matched'] ?? 0 }}</div>
                        <div class="text-xs text-green-600">Matched</div>
                    </div>
                    <div class="rounded-lg {{ ($restoreVerification['tables_different'] ?? 0) > 0 ? 'bg-yellow-50' : 'bg-gray-50' }} p-3">
                        <div class="text-lg font-bold {{ ($restoreVerification['tables_different'] ?? 0) > 0 ? 'text-yellow-700' : 'text-gray-900' }}">{{ $restoreVerification['tables_different'] ?? 0 }}</div>
                        <div class="text-xs {{ ($restoreVerification['tables_different'] ?? 0) > 0 ? 'text-yellow-600' : 'text-gray-500' }}">Different</div>
                    </div>
                </div>

                @if(!empty($restoreVerification['details']))
                    <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                        <h4 class="text-xs font-semibold text-yellow-800 mb-2">Tables with differences:</h4>
                        <div class="space-y-1">
                            @foreach($restoreVerification['details'] as $detail)
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-mono text-yellow-900">{{ $detail['table'] }}</span>
                                    <span class="text-yellow-700">
                                        @if($detail['status'] === 'missing')
                                            table missing
                                        @else
                                            expected {{ $detail['expected'] }} rows, got {{ $detail['actual'] }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-yellow-700">Minor differences in tables like activity_logs or app_backups are expected, as the restore itself creates new records.</p>
                    </div>
                @endif
            @endif
        </div>

        <div class="mt-5 flex justify-end">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-restore-verification')">
                Close
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- View Log Modal --}}
    <x-ui.modal name="view-log" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900">Backup Log</h2>
        <div class="mt-3 max-h-96 overflow-y-auto rounded-lg border border-gray-200 bg-gray-900 p-4">
            @forelse($logEntries as $entry)
                <div class="font-mono text-xs leading-5">
                    <span class="text-gray-500">{{ $entry['time'] ?? '' }}</span>
                    <span class="{{ str_contains($entry['message'] ?? '', 'FAILED') ? 'text-red-400' : (str_contains($entry['message'] ?? '', 'completed') ? 'text-green-400' : 'text-gray-300') }}">
                        {{ $entry['message'] ?? '' }}
                    </span>
                </div>
            @empty
                <p class="text-gray-500 text-xs">No log entries available.</p>
            @endforelse
        </div>
        <div class="mt-4 flex justify-end">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-view-log')">
                Close
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
