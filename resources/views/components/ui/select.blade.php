@props([])

<select {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm
                shadow-sm
                focus:border-accent focus:ring-1 focus:ring-accent
                disabled:bg-gray-50 disabled:text-gray-500'
]) }}>
    {{ $slot }}
</select>
