@props([
    'options' => [],
    'selected' => 'all',
    /** Livewire property to $set. Mutually exclusive with `method`. */
    'wire' => null,
    /**
     * Livewire method to call with the option key, for screens whose tab switch does more than
     * assign a property — SitePlugins::setTab() also resets the filter, the search and the
     * selection. Without this the shared component could not express what the hand-rolled tabs
     * did, which is why they stayed hand-rolled.
     */
    'method' => null,
    'groupLabel' => null,
    /**
     * Optional per-option counts, keyed like $options.
     *
     * The alerts screen worked this out first and wrote the reason in its own markup: "Filters
     * double as the summary — the numbers you would have read anyway." Every other screen ignored
     * it and stacked a row of stat tiles on top of filters repeating the same categories.
     */
    'counts' => [],
    /** Option keys that get an attention dot — something is waiting behind that tab. */
    'markers' => [],
])

<div role="tablist" @if($groupLabel) aria-label="{{ $groupLabel }}" @endif class="flex w-fit max-w-full overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-1">
    @foreach($options as $value => $label)
        @php
            $count = $counts[$value] ?? null;
            $isActive = $selected === $value;
        @endphp
        <button
            type="button"
            role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            @if($method)
                wire:click="{{ $method }}('{{ $value }}')"
            @elseif($wire)
                wire:click="$set('{{ $wire }}', '{{ $value }}')"
            @else
                {{ $attributes->wire('click') }}
            @endif
            @class([
                'shrink-0 rounded-md px-2.5 py-1 text-[13px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500',
                'bg-white dark:bg-gray-600 text-gray-900 dark:text-white shadow-sm' => $isActive,
                'text-gray-500 dark:text-gray-300 hover:text-gray-700 dark:hover:text-white' => ! $isActive,
            ])
        >
            {{ $label }}
            @if($count !== null)
                <span @class([
                    'ml-1 tabular-nums',
                    'text-gray-400 dark:text-gray-500' => ! $isActive,
                    'text-gray-500 dark:text-gray-400' => $isActive,
                ])>{{ $count }}</span>
            @endif
            @if(in_array($value, $markers, true))
                <span class="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-accent-500 align-middle"
                      role="img" aria-label="{{ __('something is waiting') }}"></span>
            @endif
        </button>
    @endforeach
</div>
