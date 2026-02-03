@props(['audit'])

@php
    $counts = $audit['counts'] ?? [];
    $totalSavings = $audit['total_savings_bytes'] ?? 0;
@endphp

<x-ui.card class="mb-6 overflow-hidden">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-lg font-semibold text-gray-900">Image Optimization</h3>
        @if($totalSavings > 0)
            <span class="text-sm font-medium text-orange-600">
                @if($totalSavings >= 1048576)
                    {{ round($totalSavings / 1048576, 1) }} MB
                @else
                    {{ round($totalSavings / 1024, 1) }} KB
                @endif
                potential savings
            </span>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @php
            $issueTypes = [
                'not_webp' => ['label' => 'Not WebP/AVIF', 'icon' => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14', 'color' => 'purple'],
                'oversized' => ['label' => 'Oversized', 'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7', 'color' => 'yellow'],
                'offscreen' => ['label' => 'Offscreen', 'icon' => 'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243', 'color' => 'blue'],
                'unoptimized' => ['label' => 'Unoptimized', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z', 'color' => 'red'],
            ];
        @endphp

        @foreach($issueTypes as $type => $config)
            @php
                $count = $counts[$type] ?? 0;
                $bgColor = match($config['color']) {
                    'purple' => 'bg-purple-50',
                    'yellow' => 'bg-yellow-50',
                    'blue' => 'bg-blue-50',
                    'red' => 'bg-red-50',
                    default => 'bg-gray-50',
                };
                $textColor = match($config['color']) {
                    'purple' => 'text-purple-600',
                    'yellow' => 'text-yellow-600',
                    'blue' => 'text-blue-600',
                    'red' => 'text-red-600',
                    default => 'text-gray-600',
                };
            @endphp
            <div class="rounded-lg {{ $bgColor }} p-3 text-center">
                <svg class="mx-auto h-5 w-5 {{ $textColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $config['icon'] }}"/>
                </svg>
                <div class="mt-1 text-lg font-bold {{ $textColor }}">{{ $count }}</div>
                <div class="text-xs text-gray-500">{{ $config['label'] }}</div>
            </div>
        @endforeach
    </div>
</x-ui.card>
