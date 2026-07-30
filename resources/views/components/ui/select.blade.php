@props([])

<select {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-[13px]
                focus:border-accent-500 focus:ring-0 focus:outline-none
                disabled:bg-gray-50 disabled:text-gray-500
                dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'
]) }}>
    {{ $slot }}
</select>
