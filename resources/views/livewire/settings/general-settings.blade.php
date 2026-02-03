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

    <form wire:submit="save" class="space-y-6 max-w-2xl">
        {{-- Application Settings --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Application</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Application Name</label>
                    <x-ui.input wire:model="appName" class="mt-1" />
                    @error('appName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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

    {{-- Storage Destinations --}}
    <livewire:settings.storage-settings />

    {{-- Danger Zone --}}
    <div class="mt-8 max-w-2xl">
        <x-ui.card class="border-red-200 ring-red-100">
            <h3 class="text-base font-semibold text-red-600 mb-2">Danger Zone</h3>
            <p class="text-sm text-gray-500 mb-4">Permanently delete all monitoring check and incident data. This action cannot be undone.</p>
            <x-ui.button variant="danger" wire:click="purgeMonitoringData" wire:confirm="Are you sure? This will delete ALL monitoring data and cannot be undone.">
                Purge Monitoring Data
            </x-ui.button>
        </x-ui.card>
    </div>
</div>
