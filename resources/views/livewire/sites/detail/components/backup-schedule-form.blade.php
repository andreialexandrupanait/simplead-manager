<x-ui.modal name="schedule-form" maxWidth="5xl">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Backup Schedule') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('Configure automated backups for this site.') }}</p>

        @if($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3">
                <ul class="list-disc list-inside text-sm text-red-700">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Two columns, because the alternative is a column of eleven controls
             that scrolls past its own Save button. Left is when and where the
             backup runs; right is what goes into it and how long it is kept. --}}
        {{-- Three columns: the schedule on the left, then what is left out of
             the files, then what is left out of the database. The two exclusion
             pickers are the tall things on this form and they sit side by side
             rather than stacked, which is what kept the whole modal scrolling. --}}
        <div class="mt-6 grid grid-cols-1 gap-x-6 gap-y-4 lg:grid-cols-3">
            <div class="space-y-4">
            {{-- Enable toggle --}}
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="is_enabled" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                <span class="text-sm font-medium text-gray-700">{{ __('Enable scheduled backups') }}</span>
            </label>

            {{-- Backup Type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Backup Type') }}</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model="type" value="full" class="text-accent-600 focus:ring-accent-500">
                        <span class="text-sm text-gray-700">{{ __('Full (Database + Files)') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model="type" value="database" class="text-accent-600 focus:ring-accent-500">
                        <span class="text-sm text-gray-700">{{ __('Database Only') }}</span>
                    </label>
                </div>
            </div>

            {{-- Frequency --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Frequency') }}</label>
                <x-ui.select wire:model.live="frequency" class="mt-1">
                    <option value="daily">{{ __('Daily') }}</option>
                    <option value="weekly">{{ __('Weekly') }}</option>
                    <option value="monthly">{{ __('Monthly') }}</option>
                </x-ui.select>
            </div>

            {{-- Day of week (weekly) --}}
            @if($frequency === 'weekly')
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Day of Week') }}</label>
                    <x-ui.select wire:model="day_of_week" class="mt-1">
                        <option value="0">{{ __('Sunday') }}</option>
                        <option value="1">{{ __('Monday') }}</option>
                        <option value="2">{{ __('Tuesday') }}</option>
                        <option value="3">{{ __('Wednesday') }}</option>
                        <option value="4">{{ __('Thursday') }}</option>
                        <option value="5">{{ __('Friday') }}</option>
                        <option value="6">{{ __('Saturday') }}</option>
                    </x-ui.select>
                </div>
            @endif

            {{-- Day of month (monthly) --}}
            @if($frequency === 'monthly')
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Day of Month') }}</label>
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
                    <label class="block text-sm font-medium text-gray-700">{{ __('Time') }}</label>
                    <x-ui.input wire:model="time" type="time" class="mt-1" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Timezone') }}</label>
                    <x-ui.select wire:model="timezone" class="mt-1">
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </div>

            {{-- Storage Destination (primary) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Primary Storage') }}</label>
                <x-ui.select wire:model="storage_destination_id" class="mt-1">
                    <option value="">{{ __('Default') }}</option>
                    @foreach($this->storageDestinations as $dest)
                        <option value="{{ $dest->id }}">{{ $dest->name }} ({{ ucfirst($dest->type) }})</option>
                    @endforeach
                </x-ui.select>
            </div>

            </div>

            {{-- Retention --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Retention Type') }}</label>
                    <x-ui.select wire:model="retention_type" class="mt-1">
                        <option value="count">{{ __('Keep N backups') }}</option>
                        <option value="days">{{ __('Keep for N days') }}</option>
                    </x-ui.select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Retention Value') }}</label>
                    <x-ui.input wire:model="retention_value" type="number" min="1" max="365" class="mt-1" />
                </div>
            </div>

            {{-- Incremental Backups (only for full type) --}}
            @if($type === 'full')
                <div class="rounded-lg border border-gray-200 p-4 space-y-3">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="enable_incremental" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                        <span class="text-sm font-medium text-gray-700">{{ __('Enable Incremental Backups') }}</span>
                    </label>
                    <p class="text-xs text-gray-500">{{ __('When enabled, daily backups will be incremental (only changed files), with a full backup on the selected day.') }}</p>

                    @if($enable_incremental)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('Full Backup Day') }}</label>
                            <x-ui.select wire:model="full_backup_day_of_week" class="mt-1">
                                <option value="0">{{ __('Sunday') }}</option>
                                <option value="1">{{ __('Monday') }}</option>
                                <option value="2">{{ __('Tuesday') }}</option>
                                <option value="3">{{ __('Wednesday') }}</option>
                                <option value="4">{{ __('Thursday') }}</option>
                                <option value="5">{{ __('Friday') }}</option>
                                <option value="6">{{ __('Saturday') }}</option>
                            </x-ui.select>
                            <p class="mt-1 text-xs text-gray-400">{{ __('Other days will run incremental backups (only changed files + full database).') }}</p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Backup before updates --}}
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="backup_before_updates" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                <span class="text-sm text-gray-700">{{ __('Create backup before applying updates') }}</span>
            </label>
            </div>

            <div class="space-y-4">
            {{-- What never gets backed up.
                 Chips rather than a textarea: an exclusion list is a set of
                 things, and a set is easier to read, and to take one item out of,
                 than a block of text you have to find the right line in. --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Skip these files and folders') }}</label>

                <div class="mt-1 min-h-[2.5rem] rounded-lg border border-gray-300 bg-white p-2">
                    @forelse($this->excludedPaths() as $path)
                        <span class="mr-1 mb-1 inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs text-gray-700">
                            <x-icons.file-text class="h-3 w-3 text-gray-400" aria-hidden="true" />
                            {{ $path }}
                            <button type="button" wire:click="removePath(@js($path))" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove :path', ['path' => $path]) }}">×</button>
                        </span>
                    @empty
                        <span class="text-xs text-gray-500">{{ __('Everything is backed up.') }}</span>
                    @endforelse
                </div>

                <div class="mt-2 flex items-center gap-3">
                    <x-ui.button type="button" size="xs" variant="secondary" wire:click="openPicker" wire:loading.attr="disabled" wire:target="openPicker">
                        <span wire:loading.remove wire:target="openPicker">{{ __('Choose files and folders') }}</span>
                        <span wire:loading wire:target="openPicker">{{ __('Reading…') }}</span>
                    </x-ui.button>
                    <p class="text-xs text-gray-500">{{ __('A folder skips everything under it.') }}</p>
                </div>

                @if($pickerOpen)
                    <div class="mt-2 rounded-lg border border-gray-200">
                        <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-3 py-2">
                            <span class="text-xs font-medium text-gray-700">{{ __('Files in the last backup') }}</span>
                            <button type="button" wire:click="closePicker" class="text-xs text-gray-500 hover:text-gray-700">{{ __('Close') }}</button>
                        </div>

                        @if($pickerError)
                            <p class="px-3 py-3 text-xs text-gray-600">{{ $pickerError }}</p>
                        @else
                            {{-- Server-rendered, one open level at a time. The first
                                 version kept the whole tree in a Livewire property —
                                 three and a half megabytes of component state on every
                                 request, which is over the payload limit, so the second
                                 click returned a 500. --}}
                            <div class="max-h-80 overflow-y-auto py-1">
                                @foreach($this->pickerRows() as $row)
                                    <div class="flex items-center gap-2 px-2 py-1 hover:bg-gray-50"
                                         style="padding-left: {{ 0.5 + $row['depth'] * 1.25 }}rem"
                                         wire:key="pick-{{ $row['path'] }}">
                                        @if($row['type'] === 'dir')
                                            <button type="button" wire:click="toggleFolder(@js($row['path']))"
                                                class="shrink-0 text-gray-400 hover:text-gray-600"
                                                aria-label="{{ $row['open'] ? __('Collapse') : __('Expand') }}">
                                                <svg class="h-3.5 w-3.5 transition-transform {{ $row['open'] ? 'rotate-90' : '' }}"
                                                     fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </button>
                                        @else
                                            <span class="w-3.5 shrink-0"></span>
                                        @endif

                                        <input type="checkbox"
                                               class="h-3.5 w-3.5 shrink-0 rounded border-gray-300 text-accent-600 focus:ring-accent-500"
                                               wire:click="togglePath(@js($row['path']))"
                                               @checked(in_array($row['path'], $this->excludedPaths(), true))>

                                        @if($row['type'] === 'dir')
                                            <x-icons.layers class="h-3.5 w-3.5 shrink-0 text-accent-500" aria-hidden="true" />
                                        @else
                                            <x-icons.file-text class="h-3.5 w-3.5 shrink-0 text-gray-400" aria-hidden="true" />
                                        @endif

                                        <span class="truncate text-xs {{ $row['type'] === 'dir' ? 'font-medium text-gray-800' : 'text-gray-600' }}"
                                              title="{{ $row['path'] }}">{{ $row['name'] }}</span>
                                        <span class="ml-auto shrink-0 text-[11px] tabular-nums text-gray-500">{{ $row['size'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            </div>

            <div class="space-y-4">
            {{-- Database tables --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Skip these database tables') }}</label>

                <div class="mt-1 min-h-[2.5rem] rounded-lg border border-gray-300 bg-white p-2">
                    @forelse($this->excludedTables() as $table)
                        <span class="mr-1 mb-1 inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs text-gray-700">
                            <x-icons.database class="h-3 w-3 text-gray-400" aria-hidden="true" />
                            {{ $table }}
                            <button type="button" wire:click="removeTable(@js($table))" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove :table', ['table' => $table]) }}">×</button>
                        </span>
                    @empty
                        <span class="text-xs text-gray-500">{{ __('The whole database is backed up.') }}</span>
                    @endforelse
                </div>

                <div class="mt-2 flex items-center gap-3">
                    <x-ui.button type="button" size="xs" variant="secondary" wire:click="openTablePicker">
                        {{ __('Choose tables') }}
                    </x-ui.button>
                    <p class="text-xs text-gray-500">{{ __('A skipped table is absent from the restore.') }}</p>
                </div>

                @if($tablePickerOpen)
                    <div class="mt-2 rounded-lg border border-gray-200">
                        <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-3 py-2">
                            <span class="text-xs font-medium text-gray-700">{{ __('Tables on this site') }}</span>
                            <button type="button" wire:click="closeTablePicker" class="text-xs text-gray-500 hover:text-gray-700">{{ __('Close') }}</button>
                        </div>

                        @if($tablePickerError)
                            <p class="px-3 py-3 text-xs text-gray-600">{{ $tablePickerError }}</p>
                        @else
                            <div x-data="{ q: '' }">
                                <div class="border-b border-gray-100 px-3 py-2">
                                    <input type="search" x-model="q" placeholder="{{ __('Search by table name') }}"
                                           class="w-full rounded border-gray-300 text-xs focus:border-accent-500 focus:ring-accent-500">
                                </div>
                                <div class="max-h-64 overflow-y-auto py-1">
                                    @foreach($pickerTables as $table)
                                        <label class="flex items-center gap-2 px-3 py-1 hover:bg-gray-50"
                                               x-show="q === '' || '{{ $table['name'] }}'.toLowerCase().includes(q.toLowerCase())">
                                            <input type="checkbox" class="h-3.5 w-3.5 shrink-0 rounded border-gray-300 text-accent-600 focus:ring-accent-500"
                                                   wire:click="toggleTable(@js($table['name']))"
                                                   @checked(in_array($table['name'], $this->excludedTables(), true))>
                                            <span class="truncate text-xs text-gray-700">{{ $table['name'] }}</span>
                                            @if($table['core'])
                                                <x-ui.badge variant="gray">{{ __('core') }}</x-ui.badge>
                                            @endif
                                            <span class="ml-auto shrink-0 text-[11px] tabular-nums text-gray-500">{{ $table['size'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-schedule-form')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="submit">
                {{ __('Save Schedule') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
