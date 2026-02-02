@props([])

<td {{ $attributes->merge(['class' => 'whitespace-nowrap px-4 py-3 text-sm text-gray-700']) }}>
    {{ $slot }}
</td>
