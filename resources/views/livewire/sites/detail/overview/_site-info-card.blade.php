<x-ui.module-card title="Site Info" icon="info" tone="dark">
    {{-- WordPress Version --}}
    @php
        $wpEol = $site->wp_version ? \App\Services\WordPressEolService::classify($site->wp_version) : null;
    @endphp
    <x-ui.info-row label="WordPress">
        <span class="inline-flex items-center gap-1.5">
            <span>{{ $site->wp_version ?? 'N/A' }}</span>
            @if($wpEol && $wpEol['severity'])
                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $wpEol['severity'] === 'critical' ? 'bg-red-100 text-red-700' : ($wpEol['severity'] === 'high' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700') }}">
                    {{ $wpEol['label'] }}
                </span>
            @endif
        </span>
    </x-ui.info-row>

    {{-- PHP Version --}}
    <x-ui.info-row label="PHP">{{ $site->php_version ?? 'N/A' }}</x-ui.info-row>

    {{-- Server Software --}}
    @if($site->server_software)
        <x-ui.info-row label="Server">{{ $site->server_software }}</x-ui.info-row>
    @endif

    {{-- Debug Mode --}}
    @if($site->debug_mode !== null)
        <x-ui.info-row label="Debug Mode">
            <span class="{{ $site->debug_mode ? 'mval-bad' : 'mval-ok' }}">{{ $site->debug_mode ? 'On' : 'Off' }}</span>
        </x-ui.info-row>
    @endif

    {{-- PHP Memory Limit --}}
    @if($site->php_memory_limit)
        <x-ui.info-row label="Memory Limit">{{ $site->php_memory_limit }}</x-ui.info-row>
    @endif

    {{-- DB Size --}}
    @if($site->db_size_mb)
        <x-ui.info-row label="DB Size">{{ $site->db_size_mb }} MB</x-ui.info-row>
    @endif

    {{-- Uploads Size --}}
    @if($site->uploads_size_mb)
        <x-ui.info-row label="Uploads Size">{{ $site->uploads_size_mb }} MB</x-ui.info-row>
    @endif
</x-ui.module-card>
