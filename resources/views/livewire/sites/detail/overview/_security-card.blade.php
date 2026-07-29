@php
    $securityMonitor = $site->securityMonitor;
    $isActive = $securityMonitor?->is_active ?? false;
    $secTone = $isActive ? ($site->core_update_version ? 'warn' : 'ok') : 'dark';
@endphp

<x-ui.module-card title="Security" icon="shield-check" :tone="$secTone">
    @if($isActive)
        {{-- Monitoring Status --}}
        <x-ui.info-row label="Monitoring">
            <span class="mval-ok">Active</span>
        </x-ui.info-row>

        {{-- WordPress Version --}}
        <x-ui.info-row label="WordPress">
            @if($site->core_update_version)
                <span class="mval-warn">Update Available</span>
            @else
                <span class="mval-ok">Up to Date</span>
            @endif
        </x-ui.info-row>

        {{-- Last Scan --}}
        @if($securityMonitor->last_scan_at)
            <x-ui.info-row label="Last Scan">
                {{ $securityMonitor->last_scan_at->diffForHumans() }}
            </x-ui.info-row>
        @endif

        <a href="{{ route('sites.security', $site) }}" class="mact">View Details →</a>
    @else
        <div class="py-2 text-center">
            <p class="text-sm text-gray-500">Security monitoring is not active</p>
            <a href="{{ route('sites.security', $site) }}" class="mt-2 inline-block text-xs text-accent-600 hover:text-accent-700">
                Enable Security →
            </a>
        </div>
    @endif
</x-ui.module-card>
