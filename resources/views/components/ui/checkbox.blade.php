@props([
    'label' => null,
    'id' => null,
    'disabled' => false,
])

<label class="inline-flex items-center gap-2{{ $disabled ? ' opacity-50 cursor-not-allowed' : ' cursor-pointer' }}">
    <input
        type="checkbox"
        @if($id) id="{{ $id }}" @endif
        @if($disabled) disabled @endif
        {{ $attributes->merge(['class' => 'rounded border-gray-300 text-purple-600 focus:ring-purple-500']) }}
    >
    @if($label)
        <span class="text-sm text-gray-700">{{ $label }}</span>
    @endif
</label>
