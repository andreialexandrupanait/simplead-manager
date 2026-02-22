@props(['padding' => true])

<div {{ $attributes->merge([
    'class' => 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 ' . ($padding ? 'p-6' : '')
]) }}>
    {{ $slot }}
</div>
