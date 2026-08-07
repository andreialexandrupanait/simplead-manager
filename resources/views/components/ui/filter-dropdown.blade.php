@props([
    'label',
    'icon' => null,
    /** Is a filter applied? Drives the tinted trigger. */
    'active' => false,
    'width' => '56',
    'align' => 'left',
])

{{--
    A filter pill with a menu, for when there are too many options for tabs — clients, statuses,
    sort orders.

    It exists because the fleet dashboard had four of these written out by hand, ~110 lines with the
    same trigger classes four times and the same chevron SVG copied four times, next to a sites list
    that filtered the same records with x-ui.filter-tabs. Two paradigms for "narrow this list" on two
    screens showing the same thing.

    Pair with x-ui.filter-option for the rows, so the tick mark and its screen-reader text are also
    written once.
--}}
<x-ui.dropdown :align="$align" :width="$width">
    <x-slot:trigger>
        <button
            type="button"
            @class([
                'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-1',
                'border-accent-300 bg-accent-50 text-accent-700' => $active,
                'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200' => ! $active,
            ])
        >
            @if($icon)
                <x-dynamic-component :component="'icons.' . $icon" class="h-4 w-4" aria-hidden="true" />
            @endif
            <span class="max-w-[8rem] truncate">{{ $label }}</span>
            <x-icons.chevron-down class="h-3 w-3 opacity-50" aria-hidden="true" />
        </button>
    </x-slot:trigger>

    {{ $slot }}
</x-ui.dropdown>
