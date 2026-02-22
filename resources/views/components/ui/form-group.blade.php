@props([
    'label' => null,
    'for' => null,
    'error' => null,
    'hint' => null,
    'required' => false,
])

@php
    $errorId = $for ? "{$for}-error" : null;
    $hintId = $for ? "{$for}-hint" : null;
@endphp

<div {{ $attributes }}>
    @if($label)
        <label @if($for) for="{{ $for }}" @endif class="mb-1 block text-sm font-medium text-gray-700">
            {{ $label }}
            @if($required)
                <span class="text-red-500" aria-hidden="true">*</span>
                <span class="sr-only">(required)</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if($hint && !$errors->has($error ?? ''))
        <p @if($hintId) id="{{ $hintId }}" @endif class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif

    @if($error)
        @error($error)
            <p @if($errorId) id="{{ $errorId }}" @endif class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
