@props([])

<th {{ $attributes->merge(['class' => 'px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500']) }}>
    {{ $slot }}
</th>
