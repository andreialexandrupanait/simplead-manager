@props([
    'title',
    'subtitle' => null,
])

<div class="mb-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $title }}</h1>
            @if($subtitle)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
            @endif
        </div>
        @if(isset($actions) && $actions->isNotEmpty())
            <div class="flex items-center gap-2">
                {{ $actions }}
            </div>
        @endif
    </div>
</div>
