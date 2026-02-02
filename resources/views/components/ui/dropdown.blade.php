@props(['align' => 'right', 'width' => '48'])

@php
$alignClasses = match($align) {
    'left' => 'left-0 origin-top-left',
    'right' => 'right-0 origin-top-right',
};

$widthClass = match($width) {
    '48' => 'w-48',
    '56' => 'w-56',
    '64' => 'w-64',
};
@endphp

<div x-data="{ open: false }" class="relative" @click.away="open = false">
    <div @click="open = !open">
        {{ $trigger }}
    </div>

    <div x-show="open" x-transition
         class="absolute {{ $alignClasses }} {{ $widthClass }} mt-2 rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5 z-50"
         style="display: none;">
        {{ $slot }}
    </div>
</div>
