@props(['href', 'icon', 'active' => false])

<a href="{{ $href }}"
   @mouseenter="showSidebarTooltip($el)"
   @mouseleave="hideSidebarTooltip()"
   class="flex items-center gap-3 px-3 rounded-lg py-1.5 text-[13px] font-medium transition-all duration-200
          {{ $active
              ? 'bg-purple-500/20 text-white'
              : 'text-[#ffffffb8] hover:text-white hover:bg-white/5' }}"
   :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">

    <x-dynamic-component :component="'icons.' . $icon" class="h-4 w-4 shrink-0" />

    <span class="whitespace-nowrap transition-all duration-300"
          :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">{{ $slot }}</span>
</a>
