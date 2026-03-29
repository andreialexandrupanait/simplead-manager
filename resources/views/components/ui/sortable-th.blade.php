@props(['column', 'sortBy', 'sortDir', 'align' => 'left'])

<th class="px-3 py-2 text-{{ $align }} text-xs font-medium text-gray-500 uppercase">
    <button wire:click="sort('{{ $column }}')" class="inline-flex items-center gap-1 hover:text-gray-700 uppercase">
        {{ $slot }}
        @if($sortBy === $column)
            <svg class="h-3 w-3 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
        @endif
    </button>
</th>
