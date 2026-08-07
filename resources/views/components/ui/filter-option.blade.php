@props([
    /** Is this the option currently applied? */
    'selected' => false,
])

{{--
    One row inside an x-ui.filter-dropdown.

    The tick that marks the applied option was six copies of the same inline SVG, and only one of
    them carried the screen-reader text saying what the tick meant — so five of the six announced
    the option name and nothing else, leaving no way to tell which filter was active by ear.
--}}
<button
    type="button"
    {{ $attributes->merge([
        'class' => 'flex w-full items-center justify-between gap-2 px-4 py-2 text-left text-sm focus-visible:outline-none focus-visible:bg-gray-50 dark:focus-visible:bg-gray-700 '
            .($selected
                ? 'bg-accent-50 text-accent-700 dark:bg-accent-500/10 dark:text-accent-300'
                : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700'),
    ]) }}
    @if($selected) aria-current="true" @endif
>
    <span class="flex min-w-0 items-center gap-2">{{ $slot }}</span>
    @if($selected)
        <x-icons.check class="h-4 w-4 shrink-0 text-accent-600 dark:text-accent-400" aria-hidden="true" />
        <span class="sr-only">{{ __('(active filter)') }}</span>
    @endif
</button>
