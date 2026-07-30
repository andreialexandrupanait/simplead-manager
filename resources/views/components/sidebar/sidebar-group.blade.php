@props([
    'title',
    'icon',
    // True when the current route lives inside this group — drives the default
    // expanded state and a subtle "contains active" highlight on the parent.
    'active' => false,
    // Optional severity counter on the parent (SPEC §3.2 shows [contor] on some
    // groups, e.g. Actualizări / Securitate). Same contract as sidebar-item.
    'count' => null,
    'countTone' => 'danger',
    // Stable localStorage key; falls back to a slug of the title.
    'key' => null,
])

@php
    $storageKey = $key ?? \Illuminate\Support\Str::slug($title);
    $hasCount = $count !== null && (int) $count > 0;
    $toneClasses = [
        'danger' => 'bg-red-500 text-white',
        'warning' => 'bg-amber-400 text-black',
        'accent' => 'bg-accent-500 text-white',
        'muted' => 'bg-gray-100 text-gray-600',
    ][$countTone] ?? 'bg-red-500 text-white';
    $dotClass = ['danger' => 'bg-red-500', 'warning' => 'bg-amber-400', 'accent' => 'bg-accent-500', 'muted' => 'bg-gray-400'][$countTone] ?? 'bg-red-500';
@endphp

{{-- Accordion, not sticky state.
     The old behaviour saved every toggle to localStorage forever, so after a
     few clicks every group in the sidebar stayed permanently expanded on every
     page. Now the group holding the current route opens, the rest stay shut,
     and a manual toggle only lasts for this navigation. --}}
<div x-data="{
        expanded: {{ $active ? 'true' : 'false' }},
        toggle() {
            this.expanded = !this.expanded;
        }
     }">
    <button type="button"
            @click="toggle()"
            @mouseenter="showSidebarTooltip($el)"
            @mouseleave="hideSidebarTooltip()"
            :aria-expanded="(expanded || !sidebarOpen).toString()"
            class="relative flex w-full items-center gap-3 px-3 rounded-lg py-1.5 text-sm font-medium transition-all duration-200
                   {{ $active ? 'bg-gray-100 text-gray-900 font-semibold dark:bg-gray-800 dark:text-gray-100' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800' }}"
            :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0 lg:gap-0'">

        <x-dynamic-component :component="'icons.' . $icon" class="h-4 w-4 shrink-0" aria-hidden="true" />

        {{-- Collapsed-rail dot so a non-zero counter is still visible without labels. --}}
        @if($hasCount)
            <span aria-hidden="true"
                  class="absolute top-1 right-1 h-1.5 w-1.5 rounded-full {{ $dotClass }} hidden"
                  :class="sidebarOpen ? '' : 'lg:block'"></span>
        @endif

        <span class="flex flex-1 items-center gap-2 whitespace-nowrap transition-all duration-300"
              :class="sidebarOpen ? '' : 'lg:flex-none lg:opacity-0 lg:w-0 lg:overflow-hidden'">
            <span class="flex-1 text-left">{{ $title }}</span>
            @if($hasCount)
                <span class="min-w-[1.25rem] rounded-full px-1.5 py-0.5 text-center text-[11px] font-semibold leading-none {{ $toneClasses }}">{{ (int) $count > 99 ? '99+' : (int) $count }}</span>
            @endif
            <x-icons.chevron-right class="h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform duration-200"
                                   ::class="expanded ? 'rotate-90' : ''" />
        </span>
    </button>

    {{-- Sub-items. Always visible in the collapsed rail (icon-only) so nothing
         becomes unreachable; toggle-driven when the sidebar is expanded. --}}
    <div x-show="expanded || !sidebarOpen"
         x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="sad-submenu mt-0.5 space-y-0.5 ml-[1.4rem] pl-2"
         :class="sidebarOpen ? '' : 'lg:ml-0 lg:pl-0'">
        {{ $slot }}
    </div>
</div>
