@props(['href', 'icon', 'active' => false])

<div class="relative" x-data="{
    showTooltip: false,
    tooltipEl: null,
    reposition() {
        if (!this.tooltipEl) return;
        let rect = this.$refs.trigger.getBoundingClientRect();
        this.tooltipEl.style.left = Math.round(rect.right + 8) + 'px';
        this.tooltipEl.style.top = Math.round(rect.top + rect.height / 2) + 'px';
    },
    init() {
        this.$watch('sidebarOpen', (val) => { if (val) this.showTooltip = false; });
    }
}">
    <a href="{{ $href }}"
       x-ref="trigger"
       @mouseenter="if (!sidebarOpen && window.innerWidth >= 1024) { showTooltip = true; $nextTick(() => reposition()); }"
       @mouseleave="showTooltip = false"
       class="flex items-center gap-3 px-3 rounded-lg py-2.5 text-sm font-medium transition-all duration-200
              {{ $active
                  ? 'bg-purple-500/20 text-white'
                  : 'text-white/70 hover:text-white hover:bg-white/5' }}"
       :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">

        <x-dynamic-component :component="'icons.' . $icon" class="h-5 w-5 shrink-0" />

        <span class="whitespace-nowrap transition-all duration-300"
              :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">{{ $slot }}</span>
    </a>

    {{-- Tooltip (visible only when sidebar is collapsed and hovering) --}}
    <template x-teleport="body">
        <div x-show="showTooltip"
             x-cloak
             x-ref="tooltip"
             x-init="tooltipEl = $el"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 translate-x-1"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-1"
             class="pointer-events-none fixed"
             style="z-index: 10000; transform: translateY(-50%);">
            <div class="relative rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg whitespace-nowrap">
                {{ $slot }}
                {{-- Left arrow --}}
                <div class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-gray-900"></div>
            </div>
        </div>
    </template>
</div>
