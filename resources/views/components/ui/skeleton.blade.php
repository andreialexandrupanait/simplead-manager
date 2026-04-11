@props(['lines' => 3, 'type' => 'card'])

@if($type === 'card')
<div {{ $attributes->merge(['class' => 'animate-pulse']) }}>
    <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3 mb-3"></div>
    @for($i = 0; $i < $lines; $i++)
        <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded {{ $i === $lines - 1 ? 'w-2/3' : 'w-full' }} mb-2"></div>
    @endfor
</div>
@elseif($type === 'table')
<div {{ $attributes->merge(['class' => 'animate-pulse space-y-3']) }}>
    @for($i = 0; $i < $lines; $i++)
        <div class="flex gap-4">
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/4"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/3"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/6"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/4"></div>
        </div>
    @endfor
</div>
@elseif($type === 'stats')
<div {{ $attributes->merge(['class' => 'animate-pulse grid grid-cols-2 sm:grid-cols-4 gap-4']) }}>
    @for($i = 0; $i < 4; $i++)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="h-6 bg-gray-200 dark:bg-gray-700 rounded w-1/2 mx-auto mb-2"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-2/3 mx-auto"></div>
        </div>
    @endfor
</div>
@endif
