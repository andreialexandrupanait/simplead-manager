@props(['column', 'sortBy', 'sortDir', 'align' => 'left'])

<th class="px-3 py-2 text-{{ $align }} text-xs font-medium text-gray-500 uppercase">
    {{-- aria-sort tells a screen reader which column the table is ordered by and in which
         direction — the arrow glyph says that to everyone else and said nothing to them. --}}
    <button
        wire:click="sort('{{ $column }}')"
        class="inline-flex items-center gap-1 rounded uppercase hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-1"
        @if($sortBy === $column) aria-sort="{{ $sortDir === 'asc' ? 'ascending' : 'descending' }}" @endif
    >
        {{ $slot }}
        @if($sortBy === $column)
            <svg class="h-3 w-3 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
        @endif
    </button>
</th>
