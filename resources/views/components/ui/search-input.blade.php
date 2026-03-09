@props([
    'placeholder' => 'Search...',
])

@php
    // Extract positioning classes for the wrapper
    $wrapperClasses = $attributes->only(['class'])->get('class', '');
    $positioningClasses = collect(explode(' ', $wrapperClasses))
        ->filter(fn($class) => str_starts_with($class, 'ml-') || str_starts_with($class, 'mr-') || str_starts_with($class, 'w-') || str_starts_with($class, 'flex-'))
        ->implode(' ');

    // Remove positioning classes from input attributes
    $inputClasses = collect(explode(' ', $wrapperClasses))
        ->filter(fn($class) => !str_starts_with($class, 'ml-') && !str_starts_with($class, 'mr-') && !str_starts_with($class, 'w-') && !str_starts_with($class, 'flex-'))
        ->implode(' ');
@endphp

<div class="relative {{ $positioningClasses }}">
    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
    </div>
    <input
        type="text"
        {{ $attributes->except(['class'])->merge([
            'class' => 'block w-full rounded-lg border border-gray-300 py-2 pl-10 pr-3 text-sm
                       shadow-sm transition placeholder:text-gray-400
                       focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500' . ($inputClasses ? ' ' . $inputClasses : ''),
            'placeholder' => $placeholder,
        ]) }}
    >
</div>
