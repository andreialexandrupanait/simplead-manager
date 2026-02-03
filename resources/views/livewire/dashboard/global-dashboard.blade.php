<div>
    {{-- Section 1: Stats Bar --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Total Sites</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['total_sites'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Sites Up</div>
            <div class="mt-1 text-2xl font-bold text-green-600">{{ $this->stats['sites_up'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Sites Down</div>
            <div class="mt-1 text-2xl font-bold {{ $this->stats['sites_down'] > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $this->stats['sites_down'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Clients</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['total_clients'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Avg Uptime</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['avg_uptime'] ? $this->stats['avg_uptime'] . '%' : '—' }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Avg Response</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['avg_response_time'] ? $this->stats['avg_response_time'] . 'ms' : '—' }}</div>
        </x-ui.card>
    </div>

    {{-- Section 2: Sites List View --}}
    <div class="mt-6">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Sites</h2>
            <a href="{{ route('sites.index') }}" class="text-sm font-medium text-purple-600 hover:text-purple-800">View all</a>
        </div>

        @if($this->sites->isEmpty())
            <x-ui.card>
                <x-ui.empty-state title="No sites yet" description="Add your first site to get started." icon="globe" />
            </x-ui.card>
        @else
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5">
                @foreach($this->sites as $site)
                    @php
                        // Update badge color
                        $updates = $site->pending_updates_count ?? 0;
                        $updateBadgeColor = $updates === 0 ? 'bg-green-500' : ($updates <= 5 ? 'bg-orange-500' : 'bg-red-500');

                        // Uptime status
                        $uptimeColor = 'text-gray-300';
                        $uptimeTooltip = 'No monitor';
                        if ($site->uptimeMonitor) {
                            if ($site->is_up === true) {
                                $uptimeColor = 'text-green-500';
                                $uptimeTooltip = 'Up — ' . ($site->uptimeMonitor->uptime_30d ? round($site->uptimeMonitor->uptime_30d, 2) . '%' : 'monitoring');
                            } elseif ($site->is_up === false) {
                                $uptimeColor = 'text-red-500';
                                $uptimeTooltip = 'Down' . ($site->uptimeMonitor->last_failure_reason ? ' — ' . $site->uptimeMonitor->last_failure_reason : '');
                            } else {
                                $uptimeColor = 'text-yellow-500';
                                $uptimeTooltip = 'Degraded';
                            }
                        }

                        // SSL status
                        $sslColor = 'text-gray-300';
                        $sslTooltip = 'No certificate';
                        if ($site->sslCertificate) {
                            $cert = $site->sslCertificate;
                            if ($cert->status === 'valid') {
                                $sslColor = 'text-green-500';
                                $sslTooltip = 'SSL valid — ' . ($cert->days_remaining ?? '?') . ' days remaining';
                            } elseif ($cert->status === 'expiring_soon') {
                                $sslColor = 'text-yellow-500';
                                $sslTooltip = 'SSL expiring soon — ' . ($cert->days_remaining ?? '?') . ' days remaining';
                            } else {
                                $sslColor = 'text-red-500';
                                $sslTooltip = 'SSL ' . ($cert->status ?? 'error');
                            }
                        }

                        // Response time
                        $responseColor = 'text-gray-300';
                        $responseTooltip = 'No data';
                        if ($site->uptimeMonitor && $site->uptimeMonitor->avg_response_time) {
                            $rt = $site->uptimeMonitor->avg_response_time;
                            if ($rt < 500) {
                                $responseColor = 'text-green-500';
                            } elseif ($rt <= 2000) {
                                $responseColor = 'text-yellow-500';
                            } else {
                                $responseColor = 'text-red-500';
                            }
                            $responseTooltip = 'Response: ' . $rt . 'ms';
                        }

                        // Performance
                        $perfColor = 'text-gray-300';
                        $perfTooltip = 'No data';
                        if ($site->performanceMonitor && $site->performanceMonitor->latest_mobile_score !== null) {
                            $score = $site->performanceMonitor->latest_mobile_score;
                            if ($score >= 90) {
                                $perfColor = 'text-green-500';
                            } elseif ($score >= 50) {
                                $perfColor = 'text-yellow-500';
                            } else {
                                $perfColor = 'text-red-500';
                            }
                            $perfTooltip = 'Performance: ' . $score . '/100';
                        }

                        // Links
                        $linksColor = 'text-gray-300';
                        $linksTooltip = 'No scan';
                        if ($site->linkMonitor) {
                            $broken = $site->linkMonitor->broken_links ?? 0;
                            if ($broken === 0) {
                                $linksColor = 'text-green-500';
                                $linksTooltip = 'No broken links';
                            } elseif ($broken <= 5) {
                                $linksColor = 'text-yellow-500';
                                $linksTooltip = $broken . ' broken link' . ($broken > 1 ? 's' : '');
                            } else {
                                $linksColor = 'text-red-500';
                                $linksTooltip = $broken . ' broken links';
                            }
                        }

                        // Domain expiry
                        $domainColor = 'text-gray-300';
                        $domainTooltip = 'No monitor';
                        if ($site->domainMonitor && $site->domainMonitor->expires_at) {
                            $daysLeft = (int) now()->diffInDays($site->domainMonitor->expires_at, false);
                            if ($daysLeft < 0) {
                                $domainColor = 'text-red-500';
                                $domainTooltip = 'Domain expired';
                            } elseif ($daysLeft <= 30) {
                                $domainColor = 'text-yellow-500';
                                $domainTooltip = 'Domain expires in ' . $daysLeft . ' days';
                            } else {
                                $domainColor = 'text-green-500';
                                $domainTooltip = 'Domain expires in ' . $daysLeft . ' days';
                            }
                        }

                        // Plugins (update count)
                        $pluginsColor = $updates === 0 ? 'text-green-500' : ($updates <= 5 ? 'text-yellow-500' : 'text-red-500');
                        $pluginsTooltip = $updates === 0 ? 'All plugins up to date' : $updates . ' plugin update' . ($updates > 1 ? 's' : '') . ' available';

                        // Users
                        $usersColor = 'text-gray-300';
                        $usersTooltip = 'No users synced';
                        // We don't eager load siteUsers count to keep it light — use site_users relationship if available

                        // WordPress connected
                        $wpConnColor = $site->is_connected ? 'text-green-500' : 'text-gray-300';
                        $wpConnTooltip = $site->is_connected ? 'WordPress connected' : 'Not connected';

                        // Backup
                        $backupColor = 'text-gray-300';
                        $backupTooltip = 'Not configured';
                        if ($site->backupConfig) {
                            $bc = $site->backupConfig;
                            if ($bc->last_backup_status === 'failed') {
                                $backupColor = 'text-red-500';
                                $backupTooltip = 'Backup failed';
                            } elseif ($bc->last_backup_at && $bc->last_backup_at->diffInDays(now()) > 2) {
                                $backupColor = 'text-yellow-500';
                                $backupTooltip = 'Backup overdue — last ' . $bc->last_backup_at->diffForHumans();
                            } elseif ($bc->last_backup_at) {
                                $backupColor = 'text-green-500';
                                $backupTooltip = 'Backup OK — last ' . $bc->last_backup_at->diffForHumans();
                            }
                        }

                        // WP Version
                        $wpVerColor = 'text-gray-300';
                        $wpVerTooltip = 'Unknown';
                        if ($site->wp_version) {
                            if ($site->core_update_version) {
                                $wpVerColor = 'text-yellow-500';
                                $wpVerTooltip = 'WP ' . $site->wp_version . ' — update to ' . $site->core_update_version . ' available';
                            } else {
                                $wpVerColor = 'text-green-500';
                                $wpVerTooltip = 'WP ' . $site->wp_version . ' — up to date';
                            }
                        }

                        // Health bar
                        $healthScore = $site->health_score ?? 0;
                        $healthWidth = max(0, min(100, $healthScore));
                        $healthBarColor = $healthScore >= 90 ? 'bg-green-500' : ($healthScore >= 70 ? 'bg-yellow-500' : 'bg-red-500');
                    @endphp

                    <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 transition hover:bg-gray-50 {{ $site->is_up === false ? 'bg-red-50/30' : '' }}">
                        {{-- Update Badge --}}
                        <x-ui.tooltip :text="$updates . ' pending update' . ($updates !== 1 ? 's' : '')">
                            <div class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold text-white {{ $updateBadgeColor }}">
                                {{ $updates }}
                            </div>
                        </x-ui.tooltip>

                        {{-- Site Identity --}}
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('sites.overview', $site) }}" class="truncate text-sm font-medium text-gray-900 hover:text-purple-700">
                                {{ $site->domain }}
                            </a>
                        </div>

                        {{-- Plugin count + Quick actions --}}
                        <div class="flex flex-shrink-0 items-center gap-2">
                            <span class="hidden text-xs text-gray-500 lg:inline" title="{{ $site->site_plugins_count }} plugins">
                                {{ $site->site_plugins_count ?? 0 }}p
                            </span>

                            <button
                                wire:click="syncSite({{ $site->id }})"
                                class="hidden rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-purple-600 lg:inline-flex"
                                title="Sync site"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </button>

                            <a
                                href="{{ $site->url }}"
                                target="_blank"
                                rel="noopener"
                                class="hidden rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-purple-600 lg:inline-flex"
                                title="Open site"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>

                            {{-- Separator --}}
                            <div class="mx-1 hidden h-4 w-px bg-gray-200 lg:block"></div>
                        </div>

                        {{-- Status Icons (hidden below lg) --}}
                        <div class="hidden items-center gap-1.5 lg:flex">
                            {{-- 1. Uptime (Hovercard) --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-4 w-4 {{ $uptimeColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                </x-slot:trigger>
                                @if($site->uptimeMonitor)
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold text-gray-900">Uptime</span>
                                        @php
                                            $state = $site->uptimeMonitor->current_state ?? 'unknown';
                                            $stateBadge = match($state) {
                                                'up' => 'bg-green-100 text-green-700',
                                                'down' => 'bg-red-100 text-red-700',
                                                'degraded' => 'bg-yellow-100 text-yellow-700',
                                                default => 'bg-gray-100 text-gray-600',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $stateBadge }}">{{ ucfirst($state) }}</span>
                                    </div>
                                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                        <div>
                                            <span class="text-gray-500">24h</span>
                                            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->uptime_24h !== null ? number_format($site->uptimeMonitor->uptime_24h, 2) . '%' : '--' }}</span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500">7d</span>
                                            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->uptime_7d !== null ? number_format($site->uptimeMonitor->uptime_7d, 2) . '%' : '--' }}</span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500">30d</span>
                                            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->uptime_30d !== null ? number_format($site->uptimeMonitor->uptime_30d, 2) . '%' : '--' }}</span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500">Avg</span>
                                            <span class="ml-1 font-medium text-gray-900">{{ $site->uptimeMonitor->avg_response_time ? $site->uptimeMonitor->avg_response_time . 'ms' : '--' }}</span>
                                        </div>
                                    </div>
                                    @if($site->uptimeMonitor->last_checked_at)
                                        <p class="mt-2 text-xs text-gray-500">Last checked {{ $site->uptimeMonitor->last_checked_at->diffForHumans() }}</p>
                                    @endif
                                    @php $recentIncidents = $site->uptimeMonitor->incidents->sortByDesc('started_at')->take(3); @endphp
                                    @if($recentIncidents->isNotEmpty())
                                        <div class="mt-3 border-t border-gray-100 pt-2">
                                            <p class="text-xs font-medium text-gray-700">Recent Incidents</p>
                                            <div class="mt-1 space-y-1">
                                                @foreach($recentIncidents as $incident)
                                                    <div class="flex items-center justify-between text-xs">
                                                        <span class="truncate text-gray-600">{{ \Illuminate\Support\Str::limit($incident->cause ?? 'Unknown', 30) }}</span>
                                                        <span class="ml-2 flex-shrink-0 text-gray-400">{{ $incident->started_at->diffForHumans() }} ({{ $incident->duration }})</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
                                        <button wire:click="checkNow({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Check Now</button>
                                        <a href="{{ route('sites.uptime', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Details</a>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-500">No monitor configured</p>
                                    <a href="{{ route('sites.uptime', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">Configure Monitor</a>
                                @endif
                            </x-ui.hovercard>

                            {{-- 2. SSL --}}
                            <x-ui.tooltip :text="$sslTooltip">
                                <svg class="h-4 w-4 {{ $sslColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </x-ui.tooltip>

                            {{-- 3. Response Time --}}
                            <x-ui.tooltip :text="$responseTooltip">
                                <svg class="h-4 w-4 {{ $responseColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </x-ui.tooltip>

                            {{-- 4. Analytics (Hovercard) --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-4 w-4 {{ $perfColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                </x-slot:trigger>
                                @if($site->analyticsConnection && $site->analyticsConnection->is_active)
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold text-gray-900">Google Analytics</span>
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Connected</span>
                                    </div>
                                    @if($site->analyticsConnection->property_name)
                                        <p class="mt-2 text-xs text-gray-600">
                                            <span class="text-gray-500">Property:</span>
                                            <span class="font-medium">{{ $site->analyticsConnection->property_name }}</span>
                                        </p>
                                    @endif
                                    @if($site->analyticsConnection->last_sync_at)
                                        <p class="mt-1 text-xs text-gray-500">Last synced {{ $site->analyticsConnection->last_sync_at->diffForHumans() }}</p>
                                    @endif
                                    <div class="mt-3 border-t border-gray-100 pt-3">
                                        <a href="{{ route('sites.analytics', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Analytics</a>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-500">Analytics not connected</p>
                                    <a href="{{ route('sites.analytics', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">Connect Analytics</a>
                                @endif
                            </x-ui.hovercard>

                            {{-- 5. Links --}}
                            <x-ui.tooltip :text="$linksTooltip">
                                <svg class="h-4 w-4 {{ $linksColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                            </x-ui.tooltip>

                            {{-- 6. Domain --}}
                            <x-ui.tooltip :text="$domainTooltip">
                                <svg class="h-4 w-4 {{ $domainColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </x-ui.tooltip>

                            <div class="mx-0.5 h-4 w-px bg-gray-200"></div>

                            {{-- 7. Plugins/Updates (Hovercard) --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-4 w-4 {{ $pluginsColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                                </x-slot:trigger>
                                @if($updates > 0)
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold text-gray-900">Pending Updates</span>
                                        <span class="inline-flex items-center rounded-full {{ $updates <= 5 ? 'bg-orange-100 text-orange-700' : 'bg-red-100 text-red-700' }} px-2 py-0.5 text-xs font-medium">{{ $updates }}</span>
                                    </div>
                                    <div class="mt-3 space-y-2">
                                        @if($site->core_update_version)
                                            <div class="flex items-center justify-between text-xs">
                                                <span class="font-medium text-gray-900">WordPress Core</span>
                                                <span class="text-gray-500">{{ $site->wp_version ?? '?' }} &rarr; {{ $site->core_update_version }}</span>
                                            </div>
                                        @endif
                                        @foreach($site->sitePlugins->take(5) as $plugin)
                                            <div class="flex items-center justify-between text-xs">
                                                <span class="truncate font-medium text-gray-700" title="{{ $plugin->name }}">{{ \Illuminate\Support\Str::limit($plugin->name, 25) }}</span>
                                                <span class="ml-2 flex-shrink-0 text-gray-500">{{ $plugin->version ?? '?' }} &rarr; {{ $plugin->update_version ?? '?' }}</span>
                                            </div>
                                        @endforeach
                                        @if($site->sitePlugins->count() > 5)
                                            <p class="text-xs text-gray-400">+{{ $site->sitePlugins->count() - 5 }} more plugins</p>
                                        @endif
                                        @foreach($site->siteThemes as $theme)
                                            <div class="flex items-center justify-between text-xs">
                                                <span class="truncate font-medium text-gray-700" title="{{ $theme->name }}">{{ \Illuminate\Support\Str::limit($theme->name, 25) }} <span class="text-gray-400">(theme)</span></span>
                                                <span class="ml-2 flex-shrink-0 text-gray-500">{{ $theme->version ?? '?' }} &rarr; {{ $theme->update_version ?? '?' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 border-t border-gray-100 pt-3">
                                        <a href="{{ route('sites.plugins', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View All Updates</a>
                                    </div>
                                @else
                                    <div class="flex items-center gap-2">
                                        <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span class="text-sm text-gray-600">All up to date</span>
                                    </div>
                                    <div class="mt-3 border-t border-gray-100 pt-3">
                                        <a href="{{ route('sites.plugins', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Plugins</a>
                                    </div>
                                @endif
                            </x-ui.hovercard>

                            {{-- 8. Users --}}
                            <x-ui.tooltip :text="$usersTooltip">
                                <svg class="h-4 w-4 {{ $usersColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            </x-ui.tooltip>

                            {{-- 9. WordPress Connected --}}
                            <x-ui.tooltip :text="$wpConnTooltip">
                                <svg class="h-4 w-4 {{ $wpConnColor }}" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM3.5 12c0-1.19.25-2.32.69-3.35l3.81 10.44A8.51 8.51 0 013.5 12zm8.5 8.5c-.83 0-1.64-.12-2.4-.34l2.55-7.41 2.61 7.15c.02.04.04.07.06.1-.89.32-1.84.5-2.82.5zm1.1-12.47c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.83 0-2.24-.11-2.24-.11-.46-.02-.51.68-.05.7 0 0 .43.06.89.08l1.32 3.61-1.85 5.56-3.08-9.17c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.16 0-.35 0-.55-.01A8.49 8.49 0 0112 3.5c2.13 0 4.07.78 5.56 2.07-.04 0-.07-.01-.11-.01-1.39 0-2.08 1.07-2.08 1.9 0 .7.38 1.29.78 2 .3.52.65 1.19.65 2.16 0 .67-.26 1.45-.6 2.53l-.79 2.63-2.86-8.75zM16.62 18.77l2.59-7.47c.48-1.21.64-2.17.64-3.03 0-.31-.02-.6-.06-.87A8.48 8.48 0 0120.5 12a8.51 8.51 0 01-3.88 6.77z"/></svg>
                            </x-ui.tooltip>

                            <div class="mx-0.5 h-4 w-px bg-gray-200"></div>

                            {{-- 10. Backup (Hovercard) --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-4 w-4 {{ $backupColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                                </x-slot:trigger>
                                @if($site->backupConfig)
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold text-gray-900">Backups</span>
                                        @php
                                            $bStatus = $site->backupConfig->last_backup_status;
                                            $bDot = match($bStatus) {
                                                'completed' => 'bg-green-500',
                                                'failed' => 'bg-red-500',
                                                default => 'bg-gray-400',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                                            <span class="h-2 w-2 rounded-full {{ $bDot }}"></span>
                                            {{ $bStatus ? ucfirst($bStatus) : 'No backups yet' }}
                                        </span>
                                    </div>
                                    @if($site->latestCompletedBackup)
                                        <div class="mt-3 text-xs">
                                            <div class="flex items-center justify-between">
                                                <span class="text-gray-500">Last backup</span>
                                                <span class="font-medium text-gray-900">{{ $site->latestCompletedBackup->completed_at->diffForHumans() }}</span>
                                            </div>
                                            <div class="mt-1 flex items-center justify-between">
                                                <span class="text-gray-500">Size</span>
                                                <span class="font-medium text-gray-900">{{ $site->latestCompletedBackup->file_size_formatted }}</span>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="mt-2 text-xs">
                                        <div class="flex items-center justify-between">
                                            <span class="text-gray-500">Schedule</span>
                                            <span class="font-medium text-gray-900">{{ ucfirst($site->backupConfig->frequency ?? 'Not set') }}</span>
                                        </div>
                                        @if($site->backupConfig->next_backup_at)
                                            <div class="mt-1 flex items-center justify-between">
                                                <span class="text-gray-500">Next backup</span>
                                                <span class="font-medium text-gray-900">{{ $site->backupConfig->next_backup_at->format('M j, g:ia') }}</span>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
                                        <button wire:click="runBackup({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Run Backup</button>
                                        <a href="{{ route('sites.backups', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Backups</a>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-500">No backup configured</p>
                                    <a href="{{ route('sites.backups', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">Configure Backups</a>
                                @endif
                            </x-ui.hovercard>

                            {{-- 11. WP Version --}}
                            <x-ui.tooltip :text="$wpVerTooltip">
                                <svg class="h-4 w-4 {{ $wpVerColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
                            </x-ui.tooltip>

                            {{-- 12. Reports (Hovercard) --}}
                            @php $reportsColor = $site->reportSchedules->isNotEmpty() ? 'text-green-500' : 'text-gray-300'; @endphp
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-4 w-4 {{ $reportsColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                </x-slot:trigger>
                                @php $activeSchedule = $site->reportSchedules->first(); @endphp
                                @if($activeSchedule)
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold text-gray-900">Reports</span>
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Active</span>
                                    </div>
                                    <div class="mt-3 text-xs">
                                        <div class="flex items-center justify-between">
                                            <span class="text-gray-500">Frequency</span>
                                            <span class="font-medium text-gray-900">{{ ucfirst($activeSchedule->frequency ?? 'N/A') }}</span>
                                        </div>
                                        @if($activeSchedule->next_run_at)
                                            <div class="mt-1 flex items-center justify-between">
                                                <span class="text-gray-500">Next report</span>
                                                <span class="font-medium text-gray-900">{{ $activeSchedule->next_run_at->format('M j, g:ia') }}</span>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
                                        <button wire:click="generateQuickReport({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Generate Report</button>
                                        <a href="{{ route('sites.reports', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Reports</a>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-500">No reports scheduled</p>
                                    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
                                        <button wire:click="generateQuickReport({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Generate Report</button>
                                        <a href="{{ route('sites.reports', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">Set Up Schedule</a>
                                    </div>
                                @endif
                            </x-ui.hovercard>
                        </div>

                        {{-- Health Bar --}}
                        <x-ui.tooltip :text="'Health: ' . $healthScore . '/100'">
                            <div class="hidden w-16 flex-shrink-0 sm:block">
                                <div class="h-2 w-full rounded-full bg-gray-200">
                                    <div class="h-2 rounded-full {{ $healthBarColor }}" style="width: {{ $healthWidth }}%"></div>
                                </div>
                            </div>
                        </x-ui.tooltip>

                        {{-- Three-dot Dropdown --}}
                        <x-ui.dropdown align="right" width="48">
                            <x-slot:trigger>
                                <button class="rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                </button>
                            </x-slot:trigger>

                            {{-- Navigation links --}}
                            <a href="{{ route('sites.overview', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Overview</a>
                            <a href="{{ route('sites.plugins', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Plugins</a>
                            <a href="{{ route('sites.backups', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Backups</a>
                            <a href="{{ route('sites.uptime', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Uptime</a>
                            <a href="{{ route('sites.performance', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Performance</a>
                            <a href="{{ route('sites.settings', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Settings</a>

                            {{-- Divider --}}
                            <div class="my-1 border-t border-gray-100"></div>

                            {{-- Action buttons --}}
                            <button wire:click="runBackup({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Run Backup</button>
                            <button wire:click="checkNow({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Check Uptime</button>
                            <button wire:click="syncSite({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Sync Site</button>
                        </x-ui.dropdown>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Section 4: Bottom Action Buttons --}}
    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <a
            href="{{ route('sites.create', ['mode' => 'connect']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            Connect Existing Site
        </a>
        <a
            href="{{ route('sites.create', ['mode' => 'create']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create New Site
        </a>
        <a
            href="{{ route('sites.create', ['mode' => 'migrate']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
            Migrate Existing Site
        </a>
        <a
            href="{{ route('sites.create', ['mode' => 'clone']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            Clone A Site
        </a>
    </div>
</div>
