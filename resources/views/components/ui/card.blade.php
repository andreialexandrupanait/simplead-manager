@props(['padding' => true])

<div {{ $attributes->merge([
    'class' => 'rounded-xl bg-white border border-gray-200 dark:border-gray-700 dark:bg-gray-800 ' . ($padding ? 'p-6' : '')
]) }}>
    {{ $slot }}
</div>
