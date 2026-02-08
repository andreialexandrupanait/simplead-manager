@props([
    'options' => ['7d' => '7d', '28d' => '28d', '90d' => '90d'],
    'selected' => '28d',
    'wire' => 'setDateRange',
    'refreshAction' => 'refreshData',
])

<div class="flex items-center gap-2">
    @foreach($options as $value => $label)
        <button
            wire:click="{{ $wire }}('{{ $value }}')"
            @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                'bg-purple-100 text-purple-700' => $selected === $value,
                'bg-gray-100 text-gray-600 hover:bg-gray-200' => $selected !== $value,
            ])
        >
            {{ $label }}
        </button>
    @endforeach

    @if($refreshAction)
        <button
            wire:click="{{ $refreshAction }}"
            wire:loading.attr="disabled"
            class="rounded-lg bg-gray-100 p-1.5 text-gray-600 hover:bg-gray-200 transition"
            title="Refresh data"
        >
            <svg class="h-4 w-4" wire:loading.class="animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
        </button>
    @endif
</div>
