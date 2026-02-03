@props([])

<input {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm
                shadow-sm transition
                placeholder:text-gray-400
                focus:border-purple-500 focus:ring-1 focus:ring-purple-500
                disabled:bg-gray-50 disabled:text-gray-500'
]) }}>
