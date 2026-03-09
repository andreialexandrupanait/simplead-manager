@props(['align' => 'right', 'width' => '48'])

@php
$widthClass = match($width) {
    '48' => 'w-48',
    '56' => 'w-56',
    '64' => 'w-64',
};
$originClass = $align === 'right' ? 'origin-top-right' : 'origin-top-left';
@endphp

<div x-data="dropdown({ alignRight: {{ $align === 'right' ? 'true' : 'false' }} })" @click.window="close($event)">
    <div x-ref="trigger" @click="toggle()" aria-haspopup="true" :aria-expanded="open">
        {{ $trigger }}
    </div>

    <template x-teleport="body">
        <div x-show="open" @click="setTimeout(() => open = false, 50)"
             x-init="panelEl = $el"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="{{ $widthClass }} {{ $originClass }} rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5"
             style="display: none; position: fixed; z-index: 9999;">
            {{ $slot }}
        </div>
    </template>
</div>
