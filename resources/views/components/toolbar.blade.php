@props([
    'count' => 0,
])

{{--
    Bară de acțiuni pentru selecție multiplă (SPEC §4.4).
    Se randează doar când există site-uri selectate. Butoanele emit
    evenimente Livewire pe componenta părinte (ex. GlobalDashboard),
    care furnizează $selectedSites și metodele bulk*.

    Metode existente în App\Livewire\Traits\WithBulkSiteActions:
      - bulkBackup()        → „backup"
      - bulkCheckUptime()   → „verifică acum"
      - clearSelection()    → „deselectează tot"
    Restul acțiunilor încă nu există în trait — vezi {{-- TODO --}} mai jos.
--}}

@if ($count > 0)
    <div
        x-data
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        {{ $attributes->merge(['class' => 'sticky bottom-4 z-30 mx-auto w-full max-w-4xl px-3']) }}
    >
        <div
            role="toolbar"
            aria-label="Acțiuni în masă pentru site-urile selectate"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white/95 px-3 py-2 backdrop-blur
                   dark:border-gray-700 dark:bg-gray-800/95"
        >
            {{-- Contor selecție --}}
            <div class="flex items-center gap-2 pr-1">
                <x-ui.badge variant="purple">{{ $count }}</x-ui.badge>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ $count === 1 ? 'selectat' : 'selectate' }}
                </span>
            </div>

            <span class="mx-1 hidden h-6 w-px bg-gray-200 dark:bg-gray-700 sm:block" aria-hidden="true"></span>

            {{-- Acțiuni în masă --}}
            <div class="flex flex-wrap items-center gap-1.5">
                {{-- TODO: bulkUpdate() nu există încă în WithBulkSiteActions --}}
                <x-ui.button variant="secondary" size="sm" wire:click="bulkUpdate" wire:loading.attr="disabled">
                    <x-icons.refresh-cw class="h-4 w-4" />
                    <span>Actualizează</span>
                </x-ui.button>

                <x-ui.button variant="secondary" size="sm" wire:click="bulkBackup" wire:loading.attr="disabled">
                    <x-icons.hard-drive class="h-4 w-4" />
                    <span>Backup</span>
                </x-ui.button>

                {{-- TODO: bulkApplyPlan() nu există încă în WithBulkSiteActions --}}
                <x-ui.button variant="secondary" size="sm" wire:click="bulkApplyPlan" wire:loading.attr="disabled">
                    <x-icons.layers class="h-4 w-4" />
                    <span>Aplică plan</span>
                </x-ui.button>

                {{-- TODO: bulkApplyPresets() nu există încă în WithBulkSiteActions --}}
                <x-ui.button variant="secondary" size="sm" wire:click="bulkApplyPresets" wire:loading.attr="disabled">
                    <x-icons.sliders class="h-4 w-4" />
                    <span>Aplică presetări</span>
                </x-ui.button>

                {{-- TODO: bulkPurgeCache() nu există încă în WithBulkSiteActions --}}
                <x-ui.button variant="secondary" size="sm" wire:click="bulkPurgeCache" wire:loading.attr="disabled">
                    <x-icons.zap class="h-4 w-4" />
                    <span>Golește cache</span>
                </x-ui.button>

                <x-ui.button variant="secondary" size="sm" wire:click="bulkCheckUptime" wire:loading.attr="disabled">
                    <x-icons.check-circle class="h-4 w-4" />
                    <span>Verifică acum</span>
                </x-ui.button>
            </div>

            {{-- Deselectează tot --}}
            <div class="ml-auto">
                <x-ui.button variant="ghost" size="sm" wire:click="clearSelection">
                    <x-icons.x class="h-4 w-4" />
                    <span>Deselectează tot</span>
                </x-ui.button>
            </div>
        </div>
    </div>
@endif
