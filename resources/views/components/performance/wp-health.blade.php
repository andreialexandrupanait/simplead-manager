@props(['checks'])

<x-ui.card class="mb-6">
    <h3 class="mb-4 text-lg font-semibold text-gray-900">WordPress Health</h3>
    <div class="space-y-2">
        @foreach($checks as $check)
            @php
                $statusConfig = match($check['status'] ?? 'warn') {
                    'pass' => ['icon' => 'M5 13l4 4L19 7', 'color' => 'text-green-500', 'bg' => 'bg-green-50', 'border' => 'border-green-200'],
                    'warn' => ['icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z', 'color' => 'text-yellow-500', 'bg' => 'bg-yellow-50', 'border' => 'border-yellow-200'],
                    'fail' => ['icon' => 'M6 18L18 6M6 6l12 12', 'color' => 'text-red-500', 'bg' => 'bg-red-50', 'border' => 'border-red-200'],
                    default => ['icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01', 'color' => 'text-gray-400', 'bg' => 'bg-gray-50', 'border' => 'border-gray-200'],
                };
            @endphp
            <div class="flex items-start gap-3 rounded-lg border px-3 py-2.5 {{ $statusConfig['bg'] }} {{ $statusConfig['border'] }}">
                <svg class="mt-0.5 h-4 w-4 flex-shrink-0 {{ $statusConfig['color'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $statusConfig['icon'] }}"/>
                </svg>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-900">{{ $check['label'] ?? '' }}</div>
                    <div class="text-xs text-gray-600">{{ $check['detail'] ?? '' }}</div>
                    @if(!empty($check['recommendation']))
                        <div class="mt-1 text-xs text-gray-500 italic">{{ $check['recommendation'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-ui.card>
