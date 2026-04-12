@props([
    'options' => ['7d' => '7d', '28d' => '28d', '90d' => '90d'],
    'selected' => '28d',
    'wire' => 'setDateRange',
    'refreshAction' => 'refreshData',
])

<div class="flex items-center gap-2" x-data="{
    showCustom: false,
    customStart: '',
    customEnd: '',
    applyCustom() {
        if (this.customStart && this.customEnd) {
            $wire.setCustomDateRange(this.customStart, this.customEnd);
            this.showCustom = false;
        }
    }
}">
    @foreach($options as $value => $label)
        <button
            wire:click="{{ $wire }}('{{ $value }}')"
            @click="showCustom = false"
            @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                'bg-accent-100 text-accent-700' => $selected === $value,
                'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $selected !== $value,
            ])
        >
            {{ $label }}
        </button>
    @endforeach

    {{-- Custom date range --}}
    <div class="relative">
        <button
            @click="showCustom = !showCustom"
            @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                'bg-accent-100 text-accent-700' => $selected === 'custom',
                'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $selected !== 'custom',
            ])
        >
            Custom
        </button>
        <div
            x-show="showCustom"
            x-transition
            @click.outside="showCustom = false"
            class="absolute right-0 top-full mt-2 z-50 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-lg"
            style="min-width: 280px"
        >
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Start Date</label>
                    <input x-model="customStart" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">End Date</label>
                    <input x-model="customEnd" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500" />
                </div>
                <button
                    @click="applyCustom()"
                    class="w-full rounded-lg bg-accent-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-accent-700 transition"
                >
                    Apply
                </button>
            </div>
        </div>
    </div>

    @if($refreshAction)
        <button
            wire:click="{{ $refreshAction }}"
            wire:loading.attr="disabled"
            class="rounded-lg bg-gray-100 p-1.5 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 transition"
            title="Refresh data"
        >
            <svg class="h-4 w-4" wire:loading.class="animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
        </button>
    @endif
</div>
