@props([])

<select {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm
                focus:border-accent-500 focus:ring-1 focus:ring-accent-500
                disabled:bg-gray-50 disabled:text-gray-500
                dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'
]) }}>
    {{ $slot }}
</select>
