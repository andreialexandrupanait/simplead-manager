@props([
    'title' => null,
    'icon' => null,
    // Round header-icon colour: dark | ok | warn | bad | accent (REFERINTA .mico).
    'tone' => 'dark',
])

<div {{ $attributes->merge(['class' => 'mcard']) }}>
    @if($title || $icon || isset($actions))
        <div class="mhd">
            @if($icon)
                <span class="mico mi-{{ $tone }}">
                    <x-dynamic-component :component="'icons.' . $icon" aria-hidden="true" />
                </span>
            @endif
            @if($title)
                <p class="mtitle">{{ $title }}</p>
            @endif
            {{ $actions ?? '' }}
        </div>
    @endif
    {{ $slot }}
</div>
