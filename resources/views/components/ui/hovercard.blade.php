@props(['align' => 'right', 'width' => 'w-[28rem]'])

<span x-data="hovercard({ alignRight: {{ $align === 'right' ? 'true' : 'false' }} })" class="inline-flex">
    <span x-ref="trigger" @mouseenter="startOpen()" @mouseleave="startClose()">
        {{ $trigger }}
    </span>

    <template x-teleport="body">
        <div x-show="open" x-cloak
             x-init="panelEl = $el"
             @mouseenter="cancelClose()"
             @mouseleave="startClose()"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="{{ $width }} rounded-lg bg-white dark:bg-gray-800 p-4 shadow-lg border border-gray-200 dark:border-gray-700"
             style="display: none; position: fixed; z-index: 9999;">
            {{ $slot }}
        </div>
    </template>
</span>
