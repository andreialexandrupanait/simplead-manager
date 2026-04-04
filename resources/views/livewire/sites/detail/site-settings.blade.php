<div>
    <x-ui.page-header
        title="{{ __('Settings') }}"
        subtitle="{{ __('Configure monitoring modules and maintenance plan for this site') }}"
    >
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" x-on:click="$dispatch('open-modal-copy-settings')">
                {{ __('Copy to Sites') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mt-6 space-y-6">
        {{-- Plan Section --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Maintenance Plan') }}</h3>

            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Applied Plan') }}</label>
                    <x-ui.select wire:model="selectedPlanId">
                        <option value="">{{ __('— No plan —') }}</option>
                        @foreach($this->plans as $plan)
                            <option value="{{ $plan->id }}">
                                {{ $plan->name }}{{ $plan->is_default ? ' (' . __('Default') . ')' : '' }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button wire:click="applyPlan" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="applyPlan">{{ __('Apply Plan') }}</span>
                    <span wire:loading wire:target="applyPlan">{{ __('Applying...') }}</span>
                </x-ui.button>
            </div>

            @if($site->maintenance_plan_id && $site->is_plan_customized)
                <p class="mt-2 text-xs text-amber-600">
                    {{ __("This site's configuration has been customized from the original plan.") }}
                </p>
            @endif
        </x-ui.card>

        {{-- Module Configuration --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Module Configuration') }}</h3>
            <p class="text-sm text-gray-500 mb-4">{{ __('Toggle modules on or off. Some modules require an external connection to be configured first.') }}</p>

            <div class="divide-y divide-gray-100">
                @foreach($this->moduleConfig as $key => $mod)
                    <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $mod['enabled'] ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-400' }}">
                                <x-dynamic-component :component="'icons.' . ($this->moduleIcons[$key] ?? 'settings')" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $this->moduleLabels[$key] ?? $key }}</p>
                                @if($mod['requires_connection'] && !$mod['is_connected'])
                                    <p class="text-xs text-amber-600">{{ __('Enabled but not connected') }}</p>
                                @elseif(!$mod['exists'])
                                    <p class="text-xs text-gray-400">{{ __('Not configured') }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            {{-- Interval selector (only for modules with interval support) --}}
                            @if($mod['enabled'] && $mod['interval'] && $mod['exists'])
                                @php
                                    $minIntervals = \App\Services\ModuleConfigService::getMinIntervals();
                                    $min = $minIntervals[$key] ?? 1;
                                @endphp
                                <select
                                    wire:change="updateInterval('{{ $key }}', $event.target.value)"
                                    class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-600"
                                >
                                    @foreach($this->getIntervalOptions($key) as $val => $label)
                                        <option value="{{ $val }}" @selected($mod['interval'] == $val)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif

                            {{-- Toggle --}}
                            @if(!$mod['requires_connection'] || $mod['is_connected'])
                                <button
                                    wire:click="toggleModule('{{ $key }}')"
                                    class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none {{ $mod['enabled'] ? 'bg-purple-600' : 'bg-gray-200' }}"
                                    role="switch"
                                    aria-checked="{{ $mod['enabled'] ? 'true' : 'false' }}"
                                >
                                    <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $mod['enabled'] ? 'translate-x-4' : 'translate-x-0' }}"></span>
                                </button>
                            @else
                                <span class="text-xs text-gray-400">{{ __('Requires setup') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    <livewire:components.copy-settings-modal :source-site="$site" :show-security-option="false" :show-tweaks-option="false" :show-modules-option="true" />
</div>
