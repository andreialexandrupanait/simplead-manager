@props(['href', 'icon', 'active' => false])

<a href="{{ $href }}"
   class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition
          {{ $active
              ? 'bg-purple-500/20 text-white'
              : 'text-white/70 hover:text-white hover:bg-white/5' }}">

    <x-dynamic-component :component="'icons.' . $icon" class="h-5 w-5 shrink-0" />

    <span>{{ $slot }}</span>
</a>
