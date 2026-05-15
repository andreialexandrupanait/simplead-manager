@props(['score' => null])

@php
    $circumference = 2 * 3.14159 * 42;
    $colorHex = match(\App\Models\SecurityScan::scoreColor($score ?? 0)) {
        'green' => '#22c55e',
        'yellow' => '#eab308',
        default => '#ef4444',
    };
@endphp

<div class="relative flex h-24 w-24 shrink-0 items-center justify-center">
    <svg class="h-24 w-24 -rotate-90" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="42" fill="none" stroke="#e5e7eb" stroke-width="8"/>
        @if($score !== null)
            <circle cx="50" cy="50" r="42" fill="none"
                    stroke="{{ $colorHex }}"
                    stroke-width="8"
                    stroke-dasharray="{{ $circumference }}"
                    stroke-dashoffset="{{ $circumference * (1 - $score / 100) }}"
                    stroke-linecap="round"/>
        @endif
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span class="text-2xl font-semibold {{ $score !== null ? 'text-gray-900' : 'text-gray-400' }}">
            {{ $score ?? '—' }}
        </span>
        <span class="text-xs text-gray-500">/ 100</span>
    </div>
</div>
