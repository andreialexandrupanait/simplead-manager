<div>
    @include('livewire.settings.partials.settings-tabs')

    @if(session('settings-saved'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">Settings saved successfully.</div>
    @endif

    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    @if(session('data-purged'))
        <div class="mb-4 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-700">Monitoring data has been purged.</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- Application Settings --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Application</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Application Name</label>
                    <x-ui.input wire:model="appName" class="mt-1" />
                    @error('appName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Logo Upload --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Company Logo</label>
                    <div class="flex items-center gap-4">
                        <div class="h-16 w-16 rounded-lg bg-purple-500 flex items-center justify-center text-white text-lg font-bold overflow-hidden shrink-0">
                            @if($logo)
                                <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="h-full w-full object-cover">
                            @elseif($logoPath)
                                <img src="{{ Storage::url($logoPath) }}" alt="Logo" class="h-full w-full object-cover">
                            @else
                                SA
                            @endif
                        </div>
                        <div>
                            <input type="file" wire:model="logo" accept="image/*" class="text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-purple-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-purple-700 hover:file:bg-purple-100">
                            @error('logo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-gray-400">Square image, at least 64x64px. Used in sidebar and as favicon.</p>
                            @if($logoPath)
                                <button type="button" wire:click="removeLogo" class="mt-1 text-xs text-red-600 hover:text-red-700">Remove logo</button>
                            @endif
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Application URL</label>
                    <x-ui.input wire:model="appUrl" type="url" placeholder="https://your-app.com" class="mt-1" />
                    @error('appUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Default Timezone</label>
                        <x-ui.select wire:model="defaultTimezone" class="mt-1">
                            @foreach(timezone_identifiers_list() as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date Format</label>
                        <x-ui.select wire:model="dateFormat" class="mt-1">
                            <option value="M d, Y">{{ now()->format('M d, Y') }} (M d, Y)</option>
                            <option value="d/m/Y">{{ now()->format('d/m/Y') }} (d/m/Y)</option>
                            <option value="m/d/Y">{{ now()->format('m/d/Y') }} (m/d/Y)</option>
                            <option value="Y-m-d">{{ now()->format('Y-m-d') }} (Y-m-d)</option>
                        </x-ui.select>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- Monitoring Defaults --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Monitoring Defaults</h3>
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Default Check Interval</label>
                        <x-ui.select wire:model="defaultInterval" class="mt-1">
                            <option value="60">1 minute</option>
                            <option value="120">2 minutes</option>
                            <option value="300">5 minutes</option>
                            <option value="600">10 minutes</option>
                            <option value="900">15 minutes</option>
                            <option value="1800">30 minutes</option>
                            <option value="3600">1 hour</option>
                        </x-ui.select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Default Timeout (seconds)</label>
                        <x-ui.input wire:model="defaultTimeout" type="number" min="5" max="120" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Alert After Failures</label>
                        <x-ui.input wire:model="alertAfterFailures" type="number" min="1" max="10" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-400">Consecutive failures before alerting</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Data Retention (days)</label>
                        <x-ui.input wire:model="dataRetentionDays" type="number" min="7" max="365" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-400">How long to keep check data</p>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <div class="flex justify-end">
            <x-ui.button type="submit">Save Settings</x-ui.button>
        </div>
    </form>

    {{-- Site Statuses --}}
    <div class="mt-8">
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Site Statuses</h3>
                    <p class="text-sm text-gray-500">Group sites by custom statuses like "Maintenance" or "Miscellaneous".</p>
                </div>
                <x-ui.button size="sm" wire:click="openStatusForm">
                    Add Status
                </x-ui.button>
            </div>

            @if($this->siteStatuses->isEmpty())
                <p class="py-4 text-center text-sm text-gray-400">No site statuses configured.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->siteStatuses as $status)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $status->color }}"></div>
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ $status->name }}</span>
                                    <span class="ml-2 text-xs text-gray-500">{{ $status->sites_count }} {{ Str::plural('site', $status->sites_count) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="openStatusForm({{ $status->id }})" class="rounded-lg px-2 py-1 text-xs text-gray-500 hover:bg-gray-100">
                                    Edit
                                </button>
                                <button wire:click="deleteStatus({{ $status->id }})"
                                        wire:confirm="Delete this status?"
                                        class="rounded-lg px-2 py-1 text-xs text-red-500 hover:bg-red-50">
                                    Delete
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Status Form Modal --}}
    <x-ui.modal name="status-form" maxWidth="sm">
        <form wire:submit="saveStatus">
            <h2 class="text-lg font-semibold text-gray-900">{{ $editingStatusId ? 'Edit Status' : 'Add Status' }}</h2>

            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Name</label>
                    <x-ui.input wire:model="statusName" class="mt-1" placeholder="e.g. Maintenance" />
                    @error('statusName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Color</label>
                    <div class="mt-1 flex items-center gap-3">
                        <input type="color" wire:model.live="statusColor" class="h-9 w-9 cursor-pointer rounded border border-gray-300 p-0.5">
                        <x-ui.input wire:model.live="statusColor" class="flex-1" maxlength="7" placeholder="#6b7280" />
                    </div>
                    @error('statusColor') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Sort Order</label>
                    <x-ui.input wire:model="statusSortOrder" type="number" min="0" class="mt-1" />
                    @error('statusSortOrder') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-status-form')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit">
                    {{ $editingStatusId ? 'Save Changes' : 'Add Status' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Danger Zone --}}
    <div class="mt-8">
        <x-ui.card class="border-red-200 ring-red-100">
            <h3 class="text-base font-semibold text-red-600 mb-2">Danger Zone</h3>
            <p class="text-sm text-gray-500 mb-4">Permanently delete all monitoring check and incident data. This action cannot be undone.</p>
            <x-ui.button variant="danger" wire:click="purgeMonitoringData" wire:confirm="Are you sure? This will delete ALL monitoring data and cannot be undone.">
                Purge Monitoring Data
            </x-ui.button>
        </x-ui.card>
    </div>
</div>
