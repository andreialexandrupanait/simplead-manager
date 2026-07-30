@php
    $dbData = $this->databaseData;
@endphp

<x-ui.module-card title="Database" icon="database" tone="dark">
    {{-- DB Size --}}
    @if($site->db_size_mb)
        <x-ui.info-row label="Database Size">{{ number_format($site->db_size_mb, 1) }} MB</x-ui.info-row>
    @endif

    @if($dbData)
        {{-- Cleanup Status --}}
        @if(isset($dbData['is_enabled']))
            <x-ui.info-row label="Auto Cleanup">
                <span class="{{ $dbData['is_enabled'] ? 'mval-ok' : '' }}">{{ $dbData['is_enabled'] ? 'Enabled' : 'Disabled' }}</span>
            </x-ui.info-row>
        @endif

        {{-- Last Cleanup --}}
        @if(!empty($dbData['last_cleanup_at']))
            <x-ui.info-row label="Last Cleanup">{{ $dbData['last_cleanup_at']->diffForHumans() }}</x-ui.info-row>
        @endif

        {{-- Pending Optimizations --}}
        @if(!empty($dbData['optimization_total']))
            <div class="mt-3">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Pending Optimizations</span>
                    <x-ui.badge variant="yellow">{{ number_format($dbData['optimization_total']) }} items</x-ui.badge>
                </div>
                <div class="mg2">
                    @foreach($dbData['optimization_categories'] as $category => $count)
                        @if($count > 0)
                            <x-ui.stat-box label="{{ $category }}">{{ number_format($count) }}</x-ui.stat-box>
                        @endif
                    @endforeach
                </div>
            </div>
        @elseif(isset($dbData['optimization_total']) && $dbData['optimization_total'] === 0)
            <div class="mt-3 flex items-center gap-1.5 text-xs mval-ok">
                <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Database is clean — no pending optimizations
            </div>
        @endif
    @elseif(!$site->db_size_mb)
        <p class="py-2 text-center text-sm text-gray-500">No database info available</p>
        <a href="{{ route('sites.database', $site) }}" class="mact">Configure Database Cleanup →</a>
    @endif
</x-ui.module-card>
