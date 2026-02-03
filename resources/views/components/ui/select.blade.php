@props([])

<select {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm
                shadow-sm transition
                focus:border-purple-500 focus:ring-1 focus:ring-purple-500
                disabled:bg-gray-50 disabled:text-gray-500'
]) }}>
    {{ $slot }}
</select>
