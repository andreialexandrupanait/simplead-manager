<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ $site->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ $site->domain }}</p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Health Score</div>
            @php
                $score = $site->health_score;
                $color = $score >= 90 ? 'text-green-600' : ($score >= 70 ? 'text-yellow-600' : 'text-red-600');
            @endphp
            <div class="mt-1 text-3xl font-bold {{ $color }}">{{ $score ?? '—' }}</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Uptime</div>
            <div class="mt-1 text-3xl font-bold text-gray-900">{{ $site->uptime_percentage ?? '—' }}%</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">WordPress Version</div>
            <div class="mt-1 flex items-center gap-2">
                <span class="text-3xl font-bold text-gray-900">{{ $site->wp_version ?? '—' }}</span>
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
                <span class="text-3xl font-bold {{ $site->pending_updates_count > 0 ? 'text-yellow-600' : 'text-green-600' }}">
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
