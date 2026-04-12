@props([
    'options' => [],
    'selected' => 'all',
    'wire' => null,
])

<div class="flex overflow-x-auto rounded-lg bg-gray-100 dark:bg-gray-800 p-1">
    @foreach($options as $value => $label)
        <button
            @if($wire)
                wire:click="$set('{{ $wire }}', '{{ $value }}')"
            @else
                {{ $attributes->wire('click') }}
            @endif
            @class([
                'shrink-0 rounded-md px-3 py-1.5 text-sm font-medium transition',
                'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm' => $selected === $value,
                'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => $selected !== $value,
            ])
        >
            {{ $label }}
        </button>
    @endforeach
</div>
