@props([
    'rows' => 5,
    'cols' => 5,
])

<div class="animate-pulse">
    {{-- Header --}}
    <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
        <div class="flex items-center gap-4">
            @for($i = 0; $i < $cols; $i++)
                <div class="h-3 rounded bg-gray-200 {{ $i === 0 ? 'w-1/4' : 'w-1/6' }}"></div>
            @endfor
        </div>
    </div>

    {{-- Rows --}}
    @for($r = 0; $r < $rows; $r++)
        <div class="flex items-center gap-4 border-b border-gray-100 px-4 py-4">
            {{-- First col: icon + text --}}
            <div class="flex items-center gap-3 w-1/4">
                <div class="h-8 w-8 rounded-lg bg-gray-200 shrink-0"></div>
                <div class="space-y-1.5 flex-1">
                    <div class="h-3.5 w-3/4 rounded bg-gray-200"></div>
                    <div class="h-3 w-1/2 rounded bg-gray-100"></div>
                </div>
            </div>
            {{-- Remaining cols --}}
            @for($c = 1; $c < $cols; $c++)
                <div class="w-1/6">
                    <div class="h-3 w-{{ collect(['3/4', '1/2', '2/3', 'full'])->random() }} rounded bg-gray-{{ $c % 2 === 0 ? '200' : '100' }}"></div>
                </div>
            @endfor
        </div>
    @endfor
</div>
