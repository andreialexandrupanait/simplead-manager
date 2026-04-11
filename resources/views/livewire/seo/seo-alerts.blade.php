<div>
    <x-ui.page-header title="{{ __('SEO Alerts') }}" subtitle="{{ __('Alert rules across all monitored sites') }}">
        <x-slot:actions>
            <button
                type="button"
                @click="$dispatch('open-modal-create-rule')"
                class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ __('Create Alert Rule') }}
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        {{-- Site filter --}}
        <select
            wire:model.live="siteFilter"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
        >
            <option value="">{{ __('All Sites') }}</option>
            @foreach($this->sites as $site)
                <option value="{{ $site->id }}">{{ $site->name }}</option>
            @endforeach
        </select>

        {{-- Type filter --}}
        <select
            wire:model.live="typeFilter"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
        >
            <option value="">{{ __('All Types') }}</option>
            @foreach(\App\Models\SeoAlertRule::TYPES as $type)
                <option value="{{ $type }}">{{ \App\Models\SeoAlertRule::typeLabel($type) }}</option>
            @endforeach
        </select>

        {{-- Active filter --}}
        <select
            wire:model.live="activeFilter"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
        >
            <option value="">{{ __('All Statuses') }}</option>
            <option value="1">{{ __('Active') }}</option>
            <option value="0">{{ __('Inactive') }}</option>
        </select>

        {{-- Search --}}
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by site name…') }}"
            class="ml-auto w-64"
        />
    </div>

    {{-- Table --}}
    <x-ui.card class="!p-0 overflow-hidden">
        @if($this->rules->isEmpty())
            <x-ui.empty-state
                title="{{ __('No alert rules found') }}"
                description="{{ __('Create your first alert rule to get notified when SEO metrics change.') }}"
                icon="bell"
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-medium text-gray-500">{{ __('Site') }}</th>
                            <th class="px-4 py-2.5 text-left font-medium text-gray-500">{{ __('Rule Type') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Status') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Last Triggered') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Cooldown') }}</th>
                            <th class="px-4 py-2.5 text-right font-medium text-gray-500">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach($this->rules as $rule)
                            <tr class="hover:bg-gray-50" wire:key="rule-{{ $rule->id }}">
                                <td class="px-4 py-2.5 font-medium text-gray-900">
                                    {{ $rule->site?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-gray-700">
                                    {{ \App\Models\SeoAlertRule::typeLabel($rule->rule_type) }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    @if($rule->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">
                                            {{ __('Active') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                                            {{ __('Inactive') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-center text-xs text-gray-500">
                                    @if($rule->last_triggered_at)
                                        {{ $rule->last_triggered_at->diffForHumans() }}
                                    @else
                                        <span class="text-gray-400">{{ __('Never') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-center text-xs text-gray-500">
                                    {{ $rule->cooldown_minutes }} {{ __('min') }}
                                </td>
                                <td class="px-4 py-2.5 text-right space-x-3">
                                    <button
                                        wire:click="toggleActive({{ $rule->id }})"
                                        class="text-xs font-medium {{ $rule->is_active ? 'text-yellow-600 hover:text-yellow-800' : 'text-green-600 hover:text-green-800' }}"
                                    >
                                        {{ $rule->is_active ? __('Deactivate') : __('Activate') }}
                                    </button>
                                    <button
                                        wire:click="deleteRule({{ $rule->id }})"
                                        wire:confirm="{{ __('Delete this alert rule?') }}"
                                        class="text-xs font-medium text-red-600 hover:text-red-800"
                                    >
                                        {{ __('Delete') }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $this->rules->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Create Rule Modal --}}
    <x-ui.modal name="create-rule" maxWidth="md">
        <div id="modal-create-rule-title" class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Create Alert Rule') }}</h2>
            <button type="button" @click="$dispatch('close-modal-create-rule')" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form wire:submit="createRule" class="space-y-4">
            {{-- Site --}}
            <div>
                <label for="new-site" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Site') }}
                </label>
                <select
                    id="new-site"
                    wire:model="newSiteId"
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                >
                    <option value="">{{ __('— Select a site —') }}</option>
                    @foreach($this->sites as $site)
                        <option value="{{ $site->id }}">{{ $site->name }}</option>
                    @endforeach
                </select>
                @error('newSiteId')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Rule Type --}}
            <div>
                <label for="new-rule-type" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Rule Type') }}
                </label>
                <select
                    id="new-rule-type"
                    wire:model.live="newRuleType"
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                >
                    <option value="">{{ __('— Select a type —') }}</option>
                    @foreach(\App\Models\SeoAlertRule::TYPES as $type)
                        <option value="{{ $type }}">{{ \App\Models\SeoAlertRule::typeLabel($type) }}</option>
                    @endforeach
                </select>
                @error('newRuleType')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror

                @if($newRuleType)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Default threshold will be applied:') }}
                        <span class="font-mono">{{ json_encode(\App\Models\SeoAlertRule::defaultThreshold($newRuleType)) }}</span>
                    </p>
                @endif
            </div>

            {{-- Cooldown --}}
            <div>
                <label for="new-cooldown" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Cooldown (minutes)') }}
                </label>
                <input
                    id="new-cooldown"
                    type="number"
                    min="1"
                    max="10080"
                    wire:model="newCooldownMinutes"
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Minimum time between repeated alerts for this rule.') }}</p>
                @error('newCooldownMinutes')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Active toggle --}}
            <div class="flex items-center gap-3">
                <input
                    id="new-is-active"
                    type="checkbox"
                    wire:model="newIsActive"
                    class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                />
                <label for="new-is-active" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Active immediately') }}
                </label>
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3 pt-2">
                <button
                    type="button"
                    @click="$dispatch('close-modal-create-rule')"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    {{ __('Cancel') }}
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition"
                >
                    {{ __('Create Rule') }}
                </button>
            </div>
        </form>
    </x-ui.modal>
</div>
