@props(['nodes', 'depth' => 0])

@foreach($nodes as $node)
    @if($node['type'] === 'dir')
        <div class="select-none" style="padding-left: {{ $depth * 1.25 }}rem">
            <div class="flex items-center gap-1.5 py-1 group hover:bg-gray-50 rounded px-1 cursor-pointer"
                 x-show="matchesSearch('{{ $node['path'] }}')"
                 @click="toggleExpand('{{ $node['path'] }}')">
                {{-- Expand/collapse chevron --}}
                <svg class="w-3.5 h-3.5 text-gray-400 transition-transform duration-150 flex-shrink-0"
                     :class="expanded['{{ $node['path'] }}'] ? 'rotate-90' : ''"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>

                {{-- Directory checkbox (tri-state) --}}
                <button type="button"
                        class="w-4 h-4 rounded border flex-shrink-0 flex items-center justify-center transition-colors"
                        :class="dirState('{{ $node['path'] }}') === 'all' ? 'bg-accent-600 border-accent-600' : (dirState('{{ $node['path'] }}') === 'some' ? 'bg-accent-300 border-accent-400' : 'border-gray-300 bg-white')"
                        @click.stop="toggleDir('{{ $node['path'] }}')"
                        title="Select all files in {{ $node['name'] }}">
                    <svg x-show="dirState('{{ $node['path'] }}') === 'all'" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg x-show="dirState('{{ $node['path'] }}') === 'some'" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M20 12H4" />
                    </svg>
                </button>

                {{-- Folder icon --}}
                <svg class="w-4 h-4 flex-shrink-0" :class="expanded['{{ $node['path'] }}'] ? 'text-accent-500' : 'text-yellow-500'" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
                </svg>

                <span class="text-sm text-gray-700 truncate">{{ $node['name'] }}</span>
                <span class="text-xs text-gray-400 ml-auto flex-shrink-0" x-text="dirFileCount('{{ $node['path'] }}') + ' files'"></span>
            </div>

            {{-- Children (shown when expanded) --}}
            <div x-show="expanded['{{ $node['path'] }}']" x-collapse>
                <x-backup.file-tree-node :nodes="$node['children']" :depth="$depth + 1" />
            </div>
        </div>
    @else
        <div class="flex items-center gap-1.5 py-0.5 hover:bg-gray-50 rounded px-1"
             x-show="matchesSearch('{{ $node['path'] }}')"
             style="padding-left: {{ ($depth * 1.25) + 1.25 }}rem">
            {{-- File checkbox --}}
            <input type="checkbox"
                   class="w-4 h-4 rounded border-gray-300 text-accent-600 focus:ring-accent-500 flex-shrink-0"
                   :checked="selected['{{ $node['path'] }}']"
                   @change="toggleFile('{{ $node['path'] }}')" />

            {{-- File icon --}}
            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>

            <span class="text-sm text-gray-600 truncate">{{ $node['name'] }}</span>

            @if(isset($node['size']))
                <span class="text-xs text-gray-400 ml-auto flex-shrink-0 tabular-nums">
                    {{ $node['size'] < 1024 ? $node['size'] . ' B' : ($node['size'] < 1048576 ? round($node['size'] / 1024, 1) . ' KB' : round($node['size'] / 1048576, 1) . ' MB') }}
                </span>
            @endif
        </div>
    @endif
@endforeach
