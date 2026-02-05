@props(['text'])

<span x-data="tooltip" class="inline-flex"
    @mouseenter="show = true; $nextTick(() => reposition())"
    @mouseleave="show = false">
    <span x-ref="trigger" class="inline-flex">
        {{ $slot }}
    </span>

    <template x-teleport="body">
        <div x-show="show" x-cloak
             x-init="panelEl = $el"
             x-transition:enter="transition-opacity duration-100"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity duration-75"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="pointer-events-none whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg"
             style="display: none; position: fixed; z-index: 10000;">
            {{ $text }}
            <div class="absolute left-1/2 -translate-x-1/2 border-4 border-transparent"
                 :class="$el.parentElement.dataset.position === 'bottom'
                    ? 'bottom-full border-b-gray-900'
                    : 'top-full border-t-gray-900'"></div>
        </div>
    </template>
</span>
