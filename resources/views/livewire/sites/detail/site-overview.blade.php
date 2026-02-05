<div>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Health Score</div>
            @php
                $score = $site->health_score;
                $color = $score >= 90 ? 'text-green-600' : ($score >= 70 ? 'text-yellow-600' : 'text-red-600');
            @endphp
            <div class="mt-1 text-2xl font-bold {{ $color }}">{{ $score ?? '—' }}</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Uptime</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $site->uptime_percentage ?? '—' }}%</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">WordPress Version</div>
            <div class="mt-1 flex items-center gap-2">
                <span class="text-2xl font-bold text-gray-900">{{ $site->wp_version ?? '—' }}</span>
                @if($site->core_update_version)
                    <x-ui.badge variant="yellow">{{ $site->core_update_version }} available</x-ui.badge>
                @endif
            </div>
        </x-ui.card>
    </div>

    {{-- WordPress-specific cards --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Pending Updates</div>
            <div class="mt-1 flex items-center gap-2">
                <span class="text-2xl font-bold {{ $site->pending_updates_count > 0 ? 'text-yellow-600' : 'text-green-600' }}">
                    {{ $site->pending_updates_count }}
                </span>
                @if($site->pending_updates_count > 0)
                    <a href="{{ route('sites.updates', $site) }}" class="text-sm text-purple-600 hover:text-purple-700">
                        View updates &rarr;
                    </a>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Storage</div>
            <div class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Database</span>
                    <span class="font-medium text-gray-900">{{ $site->db_size_mb ? $site->db_size_mb . ' MB' : '—' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Uploads</span>
                    <span class="font-medium text-gray-900">{{ $site->uploads_size_mb ? $site->uploads_size_mb . ' MB' : '—' }}</span>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">WordPress Connection</div>
            <div class="mt-2 flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full {{ $site->is_connected ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                <span class="text-sm {{ $site->is_connected ? 'text-green-700' : 'text-gray-500' }}">
                    {{ $site->is_connected ? 'Connected' : 'Not connected' }}
                </span>
            </div>
            @if($site->last_synced_at)
                <p class="mt-1 text-xs text-gray-400">Last synced {{ $site->last_synced_at->diffForHumans() }}</p>
            @endif
        </x-ui.card>
    </div>

    {{-- Performance summary --}}
    @php $perfMon = $site->performanceMonitor; @endphp
    @if($perfMon && ($perfMon->latest_mobile_score !== null || $perfMon->latest_desktop_score !== null))
        <div class="mt-6">
            <a href="{{ route('sites.performance', $site) }}" class="block">
                <x-ui.card class="hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Performance</div>
                            <div class="mt-1 flex items-center gap-4">
                                @if($perfMon->latest_mobile_score !== null)
                                    @php
                                        $mColor = $perfMon->latest_mobile_score >= 90 ? 'text-green-600' : ($perfMon->latest_mobile_score >= 50 ? 'text-yellow-600' : 'text-red-600');
                                    @endphp
                                    <div>
                                        <span class="text-2xl font-bold {{ $mColor }}">{{ $perfMon->latest_mobile_score }}</span>
                                        <span class="ml-1 text-xs text-gray-500">Mobile</span>
                                    </div>
                                @endif
                                @if($perfMon->latest_desktop_score !== null)
                                    @php
                                        $dColor = $perfMon->latest_desktop_score >= 90 ? 'text-green-600' : ($perfMon->latest_desktop_score >= 50 ? 'text-yellow-600' : 'text-red-600');
                                    @endphp
                                    <div>
                                        <span class="text-2xl font-bold {{ $dColor }}">{{ $perfMon->latest_desktop_score }}</span>
                                        <span class="ml-1 text-xs text-gray-500">Desktop</span>
                                    </div>
                                @endif
                            </div>
                            @if($perfMon->last_tested_at)
                                <p class="mt-1 text-xs text-gray-400">Tested {{ $perfMon->last_tested_at->diffForHumans() }}</p>
                            @endif
                        </div>
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </x-ui.card>
            </a>
        </div>
    @endif

    {{-- Analytics & Search Console summary --}}
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Analytics summary --}}
        <a href="{{ route('sites.analytics', $site) }}" class="block">
            <x-ui.card class="hover:shadow-md transition">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Analytics (28d)</div>
                        @if($analyticsSummary)
                            <div class="mt-1 flex items-center gap-4">
                                <div>
                                    <span class="text-2xl font-bold text-gray-900">{{ number_format($analyticsSummary['total_users']) }}</span>
                                    <span class="ml-1 text-xs text-gray-500">Users</span>
                                </div>
                                <div>
                                    <span class="text-2xl font-bold text-gray-900">{{ number_format($analyticsSummary['sessions']) }}</span>
                                    <span class="ml-1 text-xs text-gray-500">Sessions</span>
                                </div>
                            </div>
                        @else
                            <p class="mt-1 text-sm text-gray-400">Not connected</p>
                        @endif
                    </div>
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </div>
            </x-ui.card>
        </a>

        {{-- Search Console summary --}}
        <a href="{{ route('sites.search-console', $site) }}" class="block">
            <x-ui.card class="hover:shadow-md transition">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Search Console (28d)</div>
                        @if($searchConsoleSummary)
                            <div class="mt-1 flex items-center gap-4">
                                <div>
                                    <span class="text-2xl font-bold text-gray-900">{{ number_format($searchConsoleSummary['clicks']) }}</span>
                                    <span class="ml-1 text-xs text-gray-500">Clicks</span>
                                </div>
                                <div>
                                    <span class="text-2xl font-bold text-gray-900">{{ number_format($searchConsoleSummary['impressions']) }}</span>
                                    <span class="ml-1 text-xs text-gray-500">Impressions</span>
                                </div>
                            </div>
                        @else
                            <p class="mt-1 text-sm text-gray-400">Not connected</p>
                        @endif
                    </div>
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </div>
            </x-ui.card>
        </a>
    </div>

    {{-- Links summary --}}
    @php $linkMon = $site->linkMonitor; @endphp
    @if($linkMon && $linkMon->last_scan_at)
        <div class="mt-6">
            <a href="{{ route('sites.links', $site) }}" class="block">
                <x-ui.card class="hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Links</div>
                            <div class="mt-1 flex items-center gap-4">
                                @php
                                    $brokenColor = $linkMon->broken_links === 0 ? 'text-green-600' : 'text-red-600';
                                @endphp
                                <div>
                                    <span class="text-2xl font-bold {{ $brokenColor }}">{{ $linkMon->broken_links }}</span>
                                    <span class="ml-1 text-xs text-gray-500">Broken</span>
                                </div>
                                @if($linkMon->redirects > 0)
                                    <div>
                                        <span class="text-2xl font-bold text-yellow-600">{{ $linkMon->redirects }}</span>
                                        <span class="ml-1 text-xs text-gray-500">Redirects</span>
                                    </div>
                                @endif
                                <div>
                                    <span class="text-2xl font-bold text-gray-900">{{ $linkMon->total_links }}</span>
                                    <span class="ml-1 text-xs text-gray-500">Total</span>
                                </div>
                            </div>
                            @if($linkMon->last_scan_at)
                                <p class="mt-1 text-xs text-gray-400">Scanned {{ $linkMon->last_scan_at->diffForHumans() }}</p>
                            @endif
                        </div>
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </x-ui.card>
            </a>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card>
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Site Information</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">URL</dt>
                    <dd class="text-gray-900"><a href="{{ $site->url }}" target="_blank" class="text-purple-600 hover:text-purple-700">{{ $site->url }}</a></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">PHP Version</dt>
                    <dd class="text-gray-900">{{ $site->php_version ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Server</dt>
                    <dd class="text-gray-900">{{ $site->server_software ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">SSL Status</dt>
                    <dd>
                        @if($site->sslCertificate)
                            <x-ui.badge :variant="$site->sslCertificate->status_color">
                                {{ $site->sslCertificate->status_label }}
                            </x-ui.badge>
                        @else
                            <x-ui.badge :variant="$site->ssl_ok ? 'green' : 'red'">
                                {{ $site->ssl_ok ? 'Valid' : 'Invalid' }}
                            </x-ui.badge>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">SSL Expiry</dt>
                    <dd class="text-gray-900">
                        {{ $site->sslCertificate?->expires_at?->format('M d, Y') ?? $site->ssl_expiry?->format('M d, Y') ?? '—' }}
                    </dd>
                </div>
                @if($site->domainMonitor?->expires_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Domain Expiry</dt>
                        <dd class="text-gray-900">
                            {{ $site->domainMonitor->expires_at->format('M d, Y') }}
                            @if($site->domainMonitor->days_remaining !== null)
                                <span class="ml-1 text-xs {{ $site->domainMonitor->days_remaining <= 30 ? 'text-yellow-600' : 'text-green-600' }}">
                                    ({{ $site->domainMonitor->days_remaining }} days)
                                </span>
                            @endif
                        </dd>
                    </div>
                @endif
                @if($site->domainMonitor?->registrar)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Registrar</dt>
                        <dd class="text-gray-900">{{ $site->domainMonitor->registrar }}</dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-gray-500">Multisite</dt>
                    <dd class="text-gray-900">{{ $site->is_multisite ? 'Yes' : 'No' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Status</h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Status</span>
                    <x-ui.badge :variant="$site->is_up ? 'green' : 'red'">
                        {{ $site->is_up ? 'Online' : 'Offline' }}
                    </x-ui.badge>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">WordPress Connection</span>
                    <div class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full {{ $site->is_connected ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                        <span class="text-sm {{ $site->is_connected ? 'text-green-700' : 'text-gray-500' }}">
                            {{ $site->is_connected ? 'Connected' : 'Not connected' }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Pending Updates</span>
                    <x-ui.badge :variant="$site->pending_updates_count > 0 ? 'yellow' : 'green'">
                        {{ $site->pending_updates_count }} updates
                    </x-ui.badge>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Backup Status</span>
                    <x-ui.badge :variant="$site->backup_ok ? 'green' : 'red'">
                        {{ $site->backup_ok ? 'OK' : 'Failed' }}
                    </x-ui.badge>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Last Backup</span>
                    <span class="text-sm text-gray-900">{{ $site->last_backup_at?->diffForHumans() ?? 'Never' }}</span>
                </div>
                @if($site->client)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-500">Client</span>
                        <span class="text-sm text-gray-900">{{ $site->client->name }}</span>
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
