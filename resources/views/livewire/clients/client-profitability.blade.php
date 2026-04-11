<div>
    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">&euro;{{ number_format($this->summary['mrr'], 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Monthly Revenue') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">&euro;{{ number_format($this->summary['monthly_cost'], 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Monthly Costs') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold {{ $this->summary['monthly_profit'] >= 0 ? 'text-green-600' : 'text-red-600' }}">&euro;{{ number_format($this->summary['monthly_profit'], 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Monthly Profit') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold {{ $this->summary['margin'] >= 30 ? 'text-green-600' : ($this->summary['margin'] >= 0 ? 'text-yellow-600' : 'text-red-600') }}">{{ $this->summary['margin'] }}%</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Profit Margin') }}</p>
            </div>
        </x-ui.card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Revenue --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('Revenue') }}</h3>

            <div class="space-y-2 mb-4">
                @foreach($revenues as $rev)
                    <div class="flex items-center justify-between text-sm py-1.5 border-b border-gray-100 dark:border-gray-700 last:border-0">
                        <div>
                            <span class="font-medium text-gray-900 dark:text-white">{{ $rev->description }}</span>
                            <div class="text-xs text-gray-400">
                                {{ ucfirst($rev->type) }}
                                @if($rev->is_recurring) &mdash; {{ ucfirst($rev->recurring_interval) }} @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-green-600">&euro;{{ number_format($rev->amount, 2) }}</span>
                            <button wire:click="deleteEntry('revenue', {{ $rev->id }})" wire:confirm="{{ __('Delete this revenue entry?') }}" class="text-gray-300 hover:text-red-500">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Add revenue form --}}
            <div class="border-t border-gray-200 dark:border-gray-600 pt-3 space-y-2">
                <div class="flex gap-2">
                    <select wire:model="revenueType" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-1">
                        <option value="maintenance">{{ __('Maintenance') }}</option>
                        <option value="project">{{ __('Project') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                    <input wire:model="revenueDescription" type="text" placeholder="{{ __('Description') }}" class="flex-1 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-1">
                    <input wire:model="revenueAmount" type="number" step="0.01" placeholder="0.00" class="w-24 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-1">
                </div>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-1 text-xs text-gray-500">
                        <input type="checkbox" wire:model="revenueRecurring" class="rounded border-gray-300 text-purple-600 h-3 w-3">
                        {{ __('Recurring') }}
                    </label>
                    @if($revenueRecurring)
                        <select wire:model="revenueInterval" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-0.5">
                            <option value="monthly">{{ __('Monthly') }}</option>
                            <option value="yearly">{{ __('Yearly') }}</option>
                        </select>
                    @endif
                    <button wire:click="addRevenue" class="ml-auto rounded bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-700">{{ __('Add') }}</button>
                </div>
            </div>
        </x-ui.card>

        {{-- Costs --}}
        <x-ui.card>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('Costs') }}</h3>

            <div class="space-y-2 mb-4">
                @foreach($costs as $cost)
                    <div class="flex items-center justify-between text-sm py-1.5 border-b border-gray-100 dark:border-gray-700 last:border-0">
                        <div>
                            <span class="font-medium text-gray-900 dark:text-white">{{ $cost->description }}</span>
                            <div class="text-xs text-gray-400">
                                {{ ucfirst($cost->type) }}
                                @if($cost->site) &mdash; {{ $cost->site->name }} @endif
                                @if($cost->is_recurring) &mdash; {{ ucfirst($cost->recurring_interval) }} @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-red-600">&euro;{{ number_format($cost->amount, 2) }}</span>
                            <button wire:click="deleteEntry('cost', {{ $cost->id }})" wire:confirm="{{ __('Delete this cost entry?') }}" class="text-gray-300 hover:text-red-500">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Add cost form --}}
            <div class="border-t border-gray-200 dark:border-gray-600 pt-3 space-y-2">
                <div class="flex gap-2">
                    <select wire:model="costType" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-1">
                        <option value="hosting">{{ __('Hosting') }}</option>
                        <option value="license">{{ __('License') }}</option>
                        <option value="labor">{{ __('Labor') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                    <input wire:model="costDescription" type="text" placeholder="{{ __('Description') }}" class="flex-1 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-1">
                    <input wire:model="costAmount" type="number" step="0.01" placeholder="0.00" class="w-24 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-1">
                </div>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-1 text-xs text-gray-500">
                        <input type="checkbox" wire:model="costRecurring" class="rounded border-gray-300 text-purple-600 h-3 w-3">
                        {{ __('Recurring') }}
                    </label>
                    @if($costRecurring)
                        <select wire:model="costInterval" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-xs py-0.5">
                            <option value="monthly">{{ __('Monthly') }}</option>
                            <option value="yearly">{{ __('Yearly') }}</option>
                        </select>
                    @endif
                    <button wire:click="addCost" class="ml-auto rounded bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-700">{{ __('Add') }}</button>
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
