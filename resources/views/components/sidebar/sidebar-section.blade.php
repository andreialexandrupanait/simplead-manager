@props(['title'])

<div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-800">
    <p class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400 transition-all duration-300"
       :class="sidebarOpen ? '' : 'lg:opacity-0 lg:h-0 lg:mb-0 lg:overflow-hidden'">
        {{ $title }}
    </p>
    <div class="space-y-0.5">
        {{ $slot }}
    </div>
</div>
