@php
    $dbData = $this->databaseData;
@endphp

<x-ui.card :padding="false">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100">
                <svg aria-hidden="true" class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Database</h3>
        </div>
        <a href="{{ route('sites.database', $site) }}" class="text-xs text-accent-600 hover:text-accent-700">
            View Details &rarr;
        </a>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        <div class="space-y-3">
            {{-- DB Size --}}
            @if($site->db_size_mb)
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Database Size</span>
                    <span class="text-sm font-medium text-gray-900">{{ number_format($site->db_size_mb, 1) }} MB</span>
                </div>
            @endif

            @if($dbData)
                {{-- Cleanup Status --}}
                @if(isset($dbData['is_enabled']))
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Auto Cleanup</span>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $dbData['is_enabled'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $dbData['is_enabled'] ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                @endif

                {{-- Last Cleanup --}}
                @if(!empty($dbData['last_cleanup_at']))
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Last Cleanup</span>
                        <span class="text-sm text-gray-900">{{ $dbData['last_cleanup_at']->diffForHumans() }}</span>
                    </div>
                @endif

                {{-- Pending Optimizations --}}
                @if(!empty($dbData['optimization_total']))
                    <div class="border-t border-gray-100 pt-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Pending Optimizations</span>
                            <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700">
                                {{ number_format($dbData['optimization_total']) }} items
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($dbData['optimization_categories'] as $category => $count)
                                @if($count > 0)
                                    <div class="flex items-center justify-between rounded bg-gray-50 px-2.5 py-1.5">
                                        <span class="text-xs text-gray-600">{{ $category }}</span>
                                        <span class="text-xs font-semibold text-gray-900">{{ number_format($count) }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @elseif(isset($dbData['optimization_total']) && $dbData['optimization_total'] === 0)
                    <div class="border-t border-gray-100 pt-3">
                        <div class="flex items-center gap-1.5 text-xs text-green-600">
                            <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Database is clean — no pending optimizations
                        </div>
                    </div>
                @endif
            @elseif(!$site->db_size_mb)
                <p class="py-2 text-center text-sm text-gray-500">No database info available</p>
                <div class="text-center">
                    <a href="{{ route('sites.database', $site) }}" class="text-xs text-accent-600 hover:text-accent-700">
                        Configure Database Cleanup &rarr;
                    </a>
                </div>
            @endif
        </div>
    </div>
</x-ui.card>
