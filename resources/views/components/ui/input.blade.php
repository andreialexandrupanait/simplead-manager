@props(['error' => null, 'errorId' => null])

@php
    $describedBy = $errorId ?? ($error ? ($attributes->get('id') ? $attributes->get('id') . '-error' : null) : null);
@endphp

<input {{ $attributes->merge([
    'class' => 'block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm
                placeholder:text-gray-400
                focus:border-accent-500 focus:ring-1 focus:ring-accent-500
                disabled:bg-gray-50 disabled:text-gray-500
                dark:border-gray-600 dark:bg-gray-800',
    'autocomplete' => 'off',
]) }}
    @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
    @if($error) aria-invalid="true" @endif
>
