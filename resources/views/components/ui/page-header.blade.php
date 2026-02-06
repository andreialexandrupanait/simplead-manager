@props([
    'title',
    'subtitle' => null,
])

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">{{ $title }}</h1>
    @if($subtitle)
        <p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>
    @endif
    @if(isset($actions))
        <div class="mt-4 flex items-center gap-3">
            {{ $actions }}
        </div>
    @endif
</div>
