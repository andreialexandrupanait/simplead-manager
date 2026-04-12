<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-accent-100">
                <svg aria-hidden="true" class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Site Info</h3>
        </div>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        <div class="space-y-2">
            {{-- WordPress Version --}}
            @php
                $wpEol = $site->wp_version ? \App\Services\WordPressEolService::classify($site->wp_version) : null;
            @endphp
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">WordPress</span>
                <div class="flex items-center gap-1.5">
                    <span class="text-sm font-medium text-gray-900">{{ $site->wp_version ?? 'N/A' }}</span>
                    @if($wpEol && $wpEol['severity'])
                        <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $wpEol['severity'] === 'critical' ? 'bg-red-100 text-red-700' : ($wpEol['severity'] === 'high' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700') }}">
                            {{ $wpEol['label'] }}
                        </span>
                    @endif
                </div>
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
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $site->debug_mode ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
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
