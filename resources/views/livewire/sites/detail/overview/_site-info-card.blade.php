<x-ui.card>
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100">
                <svg class="h-5 w-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Site Health</h3>
        </div>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        {{-- Health Score --}}
        <div class="mb-4 text-center">
            <div class="text-5xl font-bold
                @if($site->health_score >= 90) text-green-600
                @elseif($site->health_score >= 70) text-yellow-600
                @else text-red-600
                @endif">
                {{ $site->health_score ?? 0 }}
            </div>
            <div class="mt-1 text-sm text-gray-500">Health Score</div>
        </div>

        {{-- Site Info Grid --}}
        <div class="space-y-2 border-t border-gray-100 pt-4">
            {{-- WordPress Version --}}
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">WordPress</span>
                <span class="text-sm font-medium text-gray-900">{{ $site->wp_version ?? 'N/A' }}</span>
            </div>

            {{-- PHP Version --}}
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">PHP</span>
                <span class="text-sm font-medium text-gray-900">{{ $site->php_version ?? 'N/A' }}</span>
            </div>

            {{-- Server Software --}}
            @if($site->server_software)
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">Server</span>
                <span class="text-sm font-medium text-gray-900">{{ $site->server_software }}</span>
            </div>
            @endif

            {{-- Debug Mode --}}
            @if($site->debug_mode !== null)
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">Debug Mode</span>
                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium {{ $site->debug_mode ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
                    {{ $site->debug_mode ? 'On' : 'Off' }}
                </span>
            </div>
            @endif

            {{-- PHP Memory Limit --}}
            @if($site->php_memory_limit)
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">Memory Limit</span>
                <span class="text-sm font-medium text-gray-900">{{ $site->php_memory_limit }}</span>
            </div>
            @endif

            {{-- DB Size --}}
            @if($site->db_size_mb)
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">DB Size</span>
                <span class="text-sm font-medium text-gray-900">{{ $site->db_size_mb }} MB</span>
            </div>
            @endif

            {{-- Uploads Size --}}
            @if($site->uploads_size_mb)
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">Uploads Size</span>
                <span class="text-sm font-medium text-gray-900">{{ $site->uploads_size_mb }} MB</span>
            </div>
            @endif
        </div>
    </div>
</x-ui.card>
