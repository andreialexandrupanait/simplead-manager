@props([
    'title',
    'description' => null,
    'icon' => 'inbox',
    /**
     * One line instead of the full icon block.
     *
     * There were four hand-rolled empty states across the app — the component below, a div with a
     * green tick, a bare div, and `<p class="text-gray-500">No X found.</p>` — because the full
     * treatment is genuinely wrong in some places: a 12-row icon block inside a 200px overview card
     * or a table cell is worse than the sentence it replaced. So this is one component with two
     * sizes rather than one size everybody works around.
     *
     * Use the full form for a page or a section that owns its area. Use compact inside a card, a
     * modal list, or a table cell.
     */
    'compact' => false,
])

@if($compact)
    <div {{ $attributes->merge(['class' => 'py-4 text-center']) }}>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $title }}</p>
        @if($description)
            <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ $description }}</p>
        @endif
        @if(isset($action))
            <div class="mt-2">{{ $action }}</div>
        @endif
    </div>
@else
    <div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center py-12 text-center']) }}>
        <div class="mb-4 rounded-full bg-gray-100 dark:bg-gray-700 p-4">
            <x-dynamic-component :component="'icons.' . $icon" class="h-7 w-7 text-gray-400 dark:text-gray-300" aria-hidden="true" />
        </div>
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
        @if($description)
            <p class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
        @if(isset($action))
            <div class="mt-5">{{ $action }}</div>
        @endif
    </div>
@endif
