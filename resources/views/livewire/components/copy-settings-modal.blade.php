<x-ui.modal name="copy-settings" maxWidth="md">
    <h2 class="text-lg font-semibold text-gray-900">Copy Settings to Other Sites</h2>
    <p class="mt-1 text-sm text-gray-500">
        Copy settings from <strong>{{ $sourceSite->name }}</strong> to selected sites.
    </p>

    @if(session('copy-error'))
        <x-ui.alert variant="error" class="mt-4">{{ session('copy-error') }}</x-ui.alert>
    @endif

    {{-- Setting Types --}}
    @if(($showSecurityOption ? 1 : 0) + ($showTweaksOption ? 1 : 0) + ($showModulesOption ? 1 : 0) > 1)
        <div class="mt-4">
            <p class="text-sm font-medium text-gray-700 mb-2">What to copy:</p>
            <div class="space-y-2">
                @if($showSecurityOption)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="copySecuritySettings" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        Security Settings
                    </label>
                @endif
                @if($showTweaksOption)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="copyTweakSettings" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        Tweak Settings
                    </label>
                @endif
                @if($showModulesOption)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="copyModuleConfig" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        Module Configuration
                    </label>
                @endif
            </div>
        </div>
    @endif

    {{-- Site Selection --}}
    <div class="mt-4">
        <div class="flex items-center justify-between mb-2">
            <p class="text-sm font-medium text-gray-700">Target sites:</p>
            <label class="flex items-center gap-2 text-xs text-gray-500">
                <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                Select All
            </label>
        </div>

        <div class="max-h-60 overflow-y-auto rounded-lg border border-gray-200">
            @forelse($availableSites as $site)
                <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                    <input
                        type="checkbox"
                        wire:model="selectedSiteIds"
                        value="{{ $site->id }}"
                        class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                    >
                    <x-site-favicon :site="$site" class="h-4 w-4" />
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ $site->name }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ $site->domain }}</p>
                    </div>
                </label>
            @empty
                <p class="px-3 py-4 text-sm text-gray-400 text-center">No other sites available.</p>
            @endforelse
        </div>

        @if(count($selectedSiteIds) > 0)
            <p class="mt-1 text-xs text-gray-500">{{ count($selectedSiteIds) }} site(s) selected</p>
        @endif
    </div>

    {{-- Actions --}}
    <div class="mt-6 flex items-center justify-end gap-3">
        <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-copy-settings')">
            Cancel
        </x-ui.button>
        <x-ui.button type="button" wire:click="apply" wire:loading.attr="disabled">
            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="apply" />
            Copy Settings
        </x-ui.button>
    </div>
</x-ui.modal>
