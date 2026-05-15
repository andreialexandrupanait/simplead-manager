@props(['name', 'maxWidth' => 'lg', 'title' => null])

@php
$maxWidthClass = match($maxWidth) {
    'sm' => 'max-w-sm',
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
    '3xl' => 'max-w-3xl',
};
$titleId = 'modal-' . $name . '-title';
@endphp

<div x-data="{ open: false }"
     x-on:open-modal-{{ $name }}.window="open = true"
     x-on:close-modal-{{ $name }}.window="open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open"
     x-transition
     role="dialog"
     aria-modal="true"
     @if($title) aria-label="{{ $title }}" @else aria-labelledby="{{ $titleId }}" @endif
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="display: none;">

    {{-- Backdrop --}}
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" @click="open = false" aria-hidden="true"></div>

    {{-- Content --}}
    <div x-show="open" x-transition
         x-trap.inert.noscroll="open"
         class="relative w-full {{ $maxWidthClass }} max-h-[90vh] overflow-y-auto rounded-xl bg-white dark:bg-gray-800 p-6 shadow-xl">
        @if($title)
            <span id="{{ $titleId }}" class="sr-only">{{ $title }}</span>
        @endif
        {{ $slot }}
    </div>
</div>
