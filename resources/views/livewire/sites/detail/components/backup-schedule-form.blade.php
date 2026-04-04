<x-ui.modal name="schedule-form" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">Backup Schedule</h2>
        <p class="mt-1 text-sm text-gray-500">Configure automated backups for this site.</p>

        @if($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3">
                <ul class="list-disc list-inside text-sm text-red-700">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 space-y-4">
            {{-- Enable toggle --}}
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="is_enabled" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                <span class="text-sm font-medium text-gray-700">Enable scheduled backups</span>
            </label>

            {{-- Backup Type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Backup Type</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model="type" value="full" class="text-purple-600 focus:ring-purple-500">
                        <span class="text-sm text-gray-700">Full (Database + Files)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model="type" value="database" class="text-purple-600 focus:ring-purple-500">
                        <span class="text-sm text-gray-700">Database Only</span>
                    </label>
                </div>
            </div>

            {{-- Frequency --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">Frequency</label>
                <x-ui.select wire:model.live="frequency" class="mt-1">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </x-ui.select>
            </div>

            {{-- Day of week (weekly) --}}
            @if($frequency === 'weekly')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Day of Week</label>
                    <x-ui.select wire:model="day_of_week" class="mt-1">
                        <option value="0">Sunday</option>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                    </x-ui.select>
                </div>
            @endif

            {{-- Day of month (monthly) --}}
            @if($frequency === 'monthly')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Day of Month</label>
                    <x-ui.select wire:model="day_of_month" class="mt-1">
                        @for($i = 1; $i <= 28; $i++)
                            <option value="{{ $i }}">{{ $i }}</option>
                        @endfor
                    </x-ui.select>
                </div>
            @endif

            {{-- Time and Timezone --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Time</label>
                    <x-ui.input wire:model="time" type="time" class="mt-1" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Timezone</label>
                    <x-ui.select wire:model="timezone" class="mt-1">
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </div>

            {{-- Storage Destination --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">Storage Destination</label>
                <x-ui.select wire:model="storage_destination_id" class="mt-1">
                    <option value="">Default</option>
                    @foreach($this->storageDestinations as $dest)
                        <option value="{{ $dest->id }}">{{ $dest->name }} ({{ ucfirst($dest->type) }})</option>
                    @endforeach
                </x-ui.select>
            </div>

            {{-- Retention --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Retention Type</label>
                    <x-ui.select wire:model="retention_type" class="mt-1">
                        <option value="count">Keep N backups</option>
                        <option value="days">Keep for N days</option>
                    </x-ui.select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Retention Value</label>
                    <x-ui.input wire:model="retention_value" type="number" min="1" max="365" class="mt-1" />
                </div>
            </div>

            {{-- Incremental Backups (only for full type) --}}
            @if($type === 'full')
                <div class="rounded-lg border border-gray-200 p-4 space-y-3">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="enable_incremental" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="text-sm font-medium text-gray-700">Enable Incremental Backups</span>
                    </label>
                    <p class="text-xs text-gray-500">When enabled, daily backups will be incremental (only changed files), with a full backup on the selected day.</p>

                    @if($enable_incremental)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Full Backup Day</label>
                            <x-ui.select wire:model="full_backup_day_of_week" class="mt-1">
                                <option value="0">Sunday</option>
                                <option value="1">Monday</option>
                                <option value="2">Tuesday</option>
                                <option value="3">Wednesday</option>
                                <option value="4">Thursday</option>
                                <option value="5">Friday</option>
                                <option value="6">Saturday</option>
                            </x-ui.select>
                            <p class="mt-1 text-xs text-gray-400">Other days will run incremental backups (only changed files + full database).</p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Backup before updates --}}
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="backup_before_updates" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                <span class="text-sm text-gray-700">Create backup before applying updates</span>
            </label>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-schedule-form')">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit">
                Save Schedule
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
