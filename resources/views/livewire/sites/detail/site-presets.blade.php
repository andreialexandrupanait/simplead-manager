<div>
    <x-ui.page-header
        title="{{ __('Presets') }}"
        subtitle="{{ __('The Standard SimpleAD pack — the ten settings that matter, out of the connector\'s ~55.') }}"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" wire:click="applyStandardPackage" wire:loading.attr="disabled" wire:target="applyStandardPackage">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="applyStandardPackage" />
                {{ __('Apply the standard preset') }}
            </x-ui.button>
            <x-ui.button variant="primary" size="sm" wire:click="save" wire:loading.attr="disabled" wire:target="save" :disabled="! $isDirty">
                {{ __('Save') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash-alert type="success" key="success" />

    @foreach($this->groups as $groupTitle => $settings)
        <x-ui.card class="mb-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ $groupTitle }}</h3>

            <div class="space-y-3">
                @foreach($settings as $setting)
                    <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                        <div class="min-w-0 pr-4">
                            <p class="text-sm font-medium text-gray-900">
                                {{ $setting['label'] }}
                                @if($setting['essential'])
                                    <x-ui.badge variant="red" class="ml-1">{{ __('Essential') }}</x-ui.badge>
                                @endif
                            </p>
                            @if($setting['description'])
                                <p class="text-xs text-gray-500">{{ $setting['description'] }}</p>
                            @endif
                            @if($setting['enabled'])
                                <x-security.setting-status :status="$setting['status']" />
                            @endif
                        </div>

                        <x-ui.toggle
                            :enabled="$setting['enabled']"
                            wire:click="toggleSetting('{{ $setting['key'] }}')"
                        />
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endforeach

    {{-- SPEC §13: the rest stay in the code, they just stop being the first thing
         you see. One link, deliberately understated. --}}
    <p class="mt-6 text-[13px] text-gray-500">
        {{ __('Need a setting that is not in this set?') }}
        <a href="{{ route('sites.tweaks.performance', $site) }}" class="text-accent-700 underline">
            {{ __('See the connector\'s full catalogue') }}
        </a>
    </p>
</div>
