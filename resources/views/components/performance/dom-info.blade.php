@props(['elements', 'maxDepth', 'maxChildren', 'color' => 'gray'])

@php
    $colorClasses = match($color) {
        'green' => 'border-green-200 bg-green-50',
        'orange' => 'border-yellow-200 bg-yellow-50',
        'red' => 'border-red-200 bg-red-50',
        default => 'border-gray-200 bg-gray-50',
    };
    $textColor = match($color) {
        'green' => 'text-green-700',
        'orange' => 'text-yellow-700',
        'red' => 'text-red-700',
        default => 'text-gray-700',
    };
    $labelColor = match($color) {
        'green' => 'text-green-600',
        'orange' => 'text-yellow-600',
        'red' => 'text-red-600',
        default => 'text-gray-500',
    };
@endphp

<div class="mb-6 rounded-lg border p-4 {{ $colorClasses }}">
    <div class="flex items-center gap-2">
        <svg class="h-5 w-5 {{ $labelColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
        </svg>
        <h4 class="text-sm font-semibold {{ $textColor }}">DOM Size</h4>
    </div>
    <div class="mt-2 flex flex-wrap gap-4 text-sm">
        <div>
            <span class="font-semibold {{ $textColor }}">{{ number_format($elements ?? 0) }}</span>
            <span class="{{ $labelColor }}"> elements</span>
        </div>
        @if($maxDepth)
            <div>
                <span class="font-semibold {{ $textColor }}">{{ $maxDepth }}</span>
                <span class="{{ $labelColor }}"> max depth</span>
            </div>
        @endif
        @if($maxChildren)
            <div>
                <span class="font-semibold {{ $textColor }}">{{ $maxChildren }}</span>
                <span class="{{ $labelColor }}"> max children</span>
            </div>
        @endif
    </div>
</div>
