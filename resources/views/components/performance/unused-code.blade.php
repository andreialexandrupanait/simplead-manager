@props(['jsBytes' => null, 'cssBytes' => null, 'jsDetails' => null, 'cssDetails' => null, 'totalSize' => 0])

<x-ui.card class="mb-6 overflow-hidden">
    <h3 class="mb-4 text-lg font-semibold text-gray-900">Unused Code</h3>
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        {{-- Unused JavaScript --}}
        @if($jsBytes)
            <div class="min-w-0">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700">Unused JavaScript</span>
                    <span class="text-sm font-semibold text-yellow-600">
                        @if($jsBytes >= 1048576)
                            {{ round($jsBytes / 1048576, 1) }} MB
                        @else
                            {{ round($jsBytes / 1024, 1) }} KB
                        @endif
                    </span>
                </div>
                @if($totalSize > 0)
                    <div class="mb-3 h-2 rounded-full bg-gray-100">
                        <div class="h-2 rounded-full bg-yellow-500" style="width: {{ min(100, round(($jsBytes / max(1, $totalSize)) * 100, 1)) }}%"></div>
                    </div>
                @endif
                @if($jsDetails)
                    <div class="space-y-1.5">
                        @foreach(array_slice($jsDetails, 0, 5) as $file)
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span class="min-w-0 truncate text-gray-600" title="{{ $file['url'] }}">{{ basename(parse_url($file['url'] ?? '', PHP_URL_PATH) ?: $file['url'] ?? '') }}</span>
                                <span class="flex-shrink-0 text-yellow-600">
                                    @if(($file['wasted_bytes'] ?? 0) >= 1024)
                                        {{ round(($file['wasted_bytes'] ?? 0) / 1024, 1) }} KB
                                    @else
                                        {{ $file['wasted_bytes'] ?? 0 }} B
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Unused CSS --}}
        @if($cssBytes)
            <div class="min-w-0">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-700">Unused CSS</span>
                    <span class="text-sm font-semibold text-blue-600">
                        @if($cssBytes >= 1048576)
                            {{ round($cssBytes / 1048576, 1) }} MB
                        @else
                            {{ round($cssBytes / 1024, 1) }} KB
                        @endif
                    </span>
                </div>
                @if($totalSize > 0)
                    <div class="mb-3 h-2 rounded-full bg-gray-100">
                        <div class="h-2 rounded-full bg-blue-500" style="width: {{ min(100, round(($cssBytes / max(1, $totalSize)) * 100, 1)) }}%"></div>
                    </div>
                @endif
                @if($cssDetails)
                    <div class="space-y-1.5">
                        @foreach(array_slice($cssDetails, 0, 5) as $file)
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span class="min-w-0 truncate text-gray-600" title="{{ $file['url'] }}">{{ basename(parse_url($file['url'] ?? '', PHP_URL_PATH) ?: $file['url'] ?? '') }}</span>
                                <span class="flex-shrink-0 text-blue-600">
                                    @if(($file['wasted_bytes'] ?? 0) >= 1024)
                                        {{ round(($file['wasted_bytes'] ?? 0) / 1024, 1) }} KB
                                    @else
                                        {{ $file['wasted_bytes'] ?? 0 }} B
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-ui.card>
