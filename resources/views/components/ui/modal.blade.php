@props(['name', 'maxWidth' => 'lg'])

@php
$maxWidthClass = match($maxWidth) {
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
};
@endphp

<div x-data="{ open: false }"
     x-on:open-modal-{{ $name }}.window="open = true"
     x-on:close-modal-{{ $name }}.window="open = false"
     x-show="open"
     x-transition
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="display: none;">

    {{-- Backdrop --}}
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" @click="open = false"></div>

    {{-- Content --}}
    <div x-show="open" x-transition
         class="relative w-full {{ $maxWidthClass }} rounded-xl bg-white p-6 shadow-xl">
        {{ $slot }}
    </div>
</div>
