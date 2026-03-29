@props(['href', 'icon', 'active' => false, 'inactive' => false])

<a href="{{ $href }}"
   @click="mobileSidebarOpen = false"
   @mouseenter="showSidebarTooltip($el)"
   @mouseleave="hideSidebarTooltip()"
   @if($active) aria-current="page" @endif
   class="flex items-center gap-3 px-3 rounded-lg py-1.5 text-sm font-medium transition-all duration-200
          {{ $active
              ? 'bg-sidebar-hover text-white'
              : ($inactive
                  ? 'text-white/30 hover:text-white/50 hover:bg-white/5'
                  : 'text-white/70 hover:text-white hover:bg-sidebar-hover') }}"
   :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0 lg:gap-0'">

    <x-dynamic-component :component="'icons.' . $icon" class="h-4 w-4 shrink-0" aria-hidden="true" />

    <span class="flex items-center gap-2 whitespace-nowrap transition-all duration-300"
          :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
        {{ $slot }}
        @if($inactive)
            <span class="rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-medium leading-none text-white/40">Off</span>
        @endif
    </span>
</a>
