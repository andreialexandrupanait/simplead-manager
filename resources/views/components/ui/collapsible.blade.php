@props([
    'title',
    'open' => false,
])

<details {{ $attributes->merge(['class' => 'group']) }} @if($open) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between text-sm font-medium text-purple-600 hover:text-purple-700">
        <span>{{ $title }}</span>
        <svg class="h-4 w-4 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </summary>
    <div class="mt-3">
        {{ $slot }}
    </div>
</details>
