@props(['title'])

<div class="mt-2 pt-2 border-t border-white/10">
    <p class="px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-white/30 transition-all duration-300"
       :class="sidebarOpen ? '' : 'lg:opacity-0 lg:h-0 lg:mb-0 lg:overflow-hidden'">
        {{ $title }}
    </p>
    <div class="space-y-0.5">
        {{ $slot }}
    </div>
</div>
