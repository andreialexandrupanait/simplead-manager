@props([
    'count' => 6,
    'cols' => 3,
])

<div class="animate-pulse grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-{{ $cols }}">
    @for($i = 0; $i < $count; $i++)
        <div class="rounded-xl border border-gray-200 bg-white p-5 space-y-3">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-lg bg-gray-200 shrink-0"></div>
                <div class="flex-1 space-y-1.5">
                    <div class="h-4 w-2/3 rounded bg-gray-200"></div>
                    <div class="h-3 w-1/2 rounded bg-gray-100"></div>
                </div>
            </div>
            <div class="space-y-2 pt-2">
                <div class="h-3 w-full rounded bg-gray-100"></div>
                <div class="h-3 w-3/4 rounded bg-gray-100"></div>
            </div>
            <div class="flex items-center gap-2 pt-1">
                <div class="h-6 w-16 rounded-full bg-gray-200"></div>
                <div class="h-6 w-16 rounded-full bg-gray-100"></div>
            </div>
        </div>
    @endfor
</div>
