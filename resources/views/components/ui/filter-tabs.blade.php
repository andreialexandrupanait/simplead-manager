@props([
    'options' => [],
    'selected' => 'all',
    'wire' => null,
    'groupLabel' => null,
    /**
     * Optional per-option counts, keyed the same way as $options.
     *
     * The alerts screen worked this out first and wrote the reason in its own markup: "Filters
     * double as the summary — the numbers you would have read anyway." Every other screen ignored
     * it and put a row of stat tiles above filters that repeated the same categories, so Uptime
     * showed Total/Up/Down/Degraded/Paused twice on one page. Counting on the tab means one control
     * that both tells you the number and takes you to the rows behind it.
     */
    'counts' => [],
])

<div role="tablist" @if($groupLabel) aria-label="{{ $groupLabel }}" @endif class="flex overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-1">
    @foreach($options as $value => $label)
        @php $count = $counts[$value] ?? null; @endphp
        <button
            type="button"
            role="tab"
            aria-selected="{{ $selected === $value ? 'true' : 'false' }}"
            @if($wire)
                wire:click="$set('{{ $wire }}', '{{ $value }}')"
            @else
                {{ $attributes->wire('click') }}
            @endif
            @class([
                'shrink-0 rounded-md px-2.5 py-1 text-[13px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500',
                'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm' => $selected === $value,
                'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $selected !== $value,
            ])
        >
            {{ $label }}
            @if($count !== null)
                <span @class([
                    'ml-1 tabular-nums',
                    'text-gray-400 dark:text-gray-500' => $selected !== $value,
                    'text-gray-500 dark:text-gray-400' => $selected === $value,
                ])>{{ $count }}</span>
            @endif
        </button>
    @endforeach
</div>
