@props([
    'href',
    // Optional: sub-items inside a group render without one (the design hides
    // submenu icons anyway — see .sidebar-open .sad-submenu rule in app.css).
    'icon' => null,
    'active' => false,
    'inactive' => false,
    // Optional severity counter (SPEC §3.1 / §4.3): shows only when > 0,
    // coloured by gravity. Tones: danger | warning | accent | muted.
    'count' => null,
    'countTone' => 'danger',
])

@php
    // The rail is dark in both themes, so every colour here is chosen against
    // dark rather than inherited from the light palette.
    $hasCount = $count !== null && (int) $count > 0;
    $toneClasses = [
        'danger' => 'bg-red-500 text-white',
        'warning' => 'bg-amber-400 text-black',
        'accent' => 'bg-accent-500 text-white',
        'muted' => 'bg-white/10 text-white/70',
    ][$countTone] ?? 'bg-red-500 text-white';
    $dotClass = ['danger' => 'bg-red-500', 'warning' => 'bg-amber-400', 'accent' => 'bg-accent-500', 'muted' => 'bg-white/40'][$countTone] ?? 'bg-red-500';
@endphp

<a href="{{ $href }}"
   @click="mobileSidebarOpen = false"
   @mouseenter="showSidebarTooltip($el)"
   @mouseleave="hideSidebarTooltip()"
   @if($active) aria-current="page" @endif
   class="relative flex items-center gap-3 px-3 rounded-lg py-1.5 text-sm font-medium transition-all duration-200
          {{ $active
              ? 'bg-white/10 text-white font-semibold'
              : ($inactive
                  ? 'text-white/30 hover:text-white/50 hover:bg-white/5'
                  : 'text-white/70 hover:text-white hover:bg-white/5') }}"
   :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0 lg:gap-0'">

    @if($icon)
        <x-dynamic-component :component="'icons.' . $icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
    @endif

    {{-- Collapsed-rail indicator: a bare dot so a non-zero counter is still visible when labels are hidden. --}}
    @if($hasCount)
        <span aria-hidden="true"
              class="absolute top-1 right-1 h-1.5 w-1.5 rounded-full {{ $dotClass }} hidden"
              :class="sidebarOpen ? '' : 'lg:block'"></span>
    @endif

    <span class="flex flex-1 items-center gap-2 whitespace-nowrap transition-all duration-300"
          :class="sidebarOpen ? '' : 'lg:flex-none lg:opacity-0 lg:w-0 lg:overflow-hidden'">
        <span class="flex-1">{{ $slot }}</span>
        @if($inactive)
            <span class="rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-medium leading-none text-white/50">Off</span>
        @endif
        @if($hasCount)
            <span class="ml-auto min-w-[1.25rem] rounded-full px-1.5 py-0.5 text-center text-[11px] font-semibold leading-none {{ $toneClasses }}">{{ (int) $count > 99 ? '99+' : (int) $count }}</span>
        @endif
    </span>
</a>
