@props(['title'])

{{-- The top border separates this section from whatever precedes it, so the
     first section in a sidebar must not draw one: in the fleet sidebar it lands
     directly under the logo bar's own border-b and separates nothing from
     nothing. The site sidebar is unaffected — there the first section follows the
     back-link, the site switcher and Prezentare, so it is not the first child. --}}
<div class="mt-2 pt-2 border-t border-gray-200 first:mt-0 first:border-t-0 first:pt-0 dark:border-gray-800">
    <p class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400 transition-all duration-300"
       :class="sidebarOpen ? '' : 'lg:opacity-0 lg:h-0 lg:mb-0 lg:overflow-hidden'">
        {{ $title }}
    </p>
    <div class="space-y-0.5">
        {{ $slot }}
    </div>
</div>
