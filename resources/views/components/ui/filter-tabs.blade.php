@props([
    'options' => [],
    'selected' => 'all',
    'wire' => null,
])

<div class="flex rounded-lg bg-gray-100 p-1">
    @foreach($options as $value => $label)
        <button
            @if($wire)
                wire:click="$set('{{ $wire }}', '{{ $value }}')"
            @else
                {{ $attributes->wire('click') }}
            @endif
            @class([
                'rounded-md px-3 py-1.5 text-sm font-medium transition',
                'bg-white text-gray-900 shadow-sm' => $selected === $value,
                'text-gray-500 hover:text-gray-700' => $selected !== $value,
            ])
        >
            {{ $label }}
        </button>
    @endforeach
</div>
