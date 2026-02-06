@props([
    'label' => null,
    'for' => null,
    'error' => null,
    'hint' => null,
    'required' => false,
])

<div {{ $attributes }}>
    @if($label)
        <label @if($for) for="{{ $for }}" @endif class="mb-1 block text-sm font-medium text-gray-700">
            {{ $label }}
            @if($required)
                <span class="text-red-500">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if($hint && !$errors->has($error ?? ''))
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif

    @if($error)
        @error($error)
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    @endif
</div>
