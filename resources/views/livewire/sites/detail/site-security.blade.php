<div>
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Security</h1>
        <p class="mt-1 text-sm text-gray-500">Scan for vulnerabilities and security issues</p>
    </div>

    {{-- Flash Messages --}}
    @if(session('scan-dispatched'))
        <div class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">{{ session('scan-dispatched') }}</div>
    @endif
    @if(session('rec-fixed'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('rec-fixed') }}</div>
    @endif
    @if(session('rec-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('rec-error') }}</div>
    @endif

    {{-- Security Score Header --}}
    <div class="mb-6">
        <x-ui.card>
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-6">
                    {{-- Score Circle --}}
                    <div class="relative flex h-24 w-24 shrink-0 items-center justify-center">
                        <svg class="h-24 w-24 -rotate-90" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="#e5e7eb" stroke-width="8"/>
                            @if($this->latestScan)
                                <circle cx="50" cy="50" r="42" fill="none"
                                        stroke="{{ $this->latestScan->score_color === 'green' ? '#22c55e' : ($this->latestScan->score_color === 'yellow' ? '#eab308' : '#ef4444') }}"
                                        stroke-width="8"
                                        stroke-dasharray="{{ 2 * 3.14159 * 42 }}"
                                        stroke-dashoffset="{{ 2 * 3.14159 * 42 * (1 - $this->latestScan->score / 100) }}"
                                        stroke-linecap="round"/>
                            @endif
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold {{ $this->latestScan ? 'text-gray-900' : 'text-gray-400' }}">
                                {{ $this->latestScan?->score ?? '—' }}
                            </span>
                            <span class="text-xs text-gray-500">/ 100</span>
                        </div>
                    </div>

                    <div>
                        @if($this->latestScan)
                            <p class="text-lg font-semibold text-gray-900">{{ $this->latestScan->score_label }}</p>
                            <div class="mt-1 flex flex-wrap gap-2">
                                @if($this->latestScan->critical_count > 0)
                                    <x-ui.badge variant="red">{{ $this->latestScan->critical_count }} Critical</x-ui.badge>
                                @endif
                                @if($this->latestScan->high_count > 0)
                                    <x-ui.badge variant="orange">{{ $this->latestScan->high_count }} High</x-ui.badge>
                                @endif
                                @if($this->latestScan->medium_count > 0)
                                    <x-ui.badge variant="yellow">{{ $this->latestScan->medium_count }} Medium</x-ui.badge>
                                @endif
                                @if($this->latestScan->low_count > 0)
                                    <x-ui.badge variant="blue">{{ $this->latestScan->low_count }} Low</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Last scanned: {{ $this->latestScan->scanned_at->diffForHumans() }}
                            </p>
                        @else
                            <p class="text-lg font-semibold text-gray-500">No Scan Yet</p>
                            <p class="text-sm text-gray-400">Run a security scan to get your score.</p>
                        @endif
                    </div>
                </div>

                <div>
                    <x-ui.button variant="primary" size="sm" wire:click="scanNow" wire:loading.attr="disabled">
                        <svg class="h-4 w-4 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="scanNow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Scan Now
                    </x-ui.button>
                </div>
            </div>

            {{-- Category Breakdown --}}
            @if($this->latestScan && $this->latestScan->scores_breakdown)
                <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    @php
                        $categoryLabels = [
                            'file_security' => 'Files',
                            'login_security' => 'Login',
                            'database_security' => 'Database',
                            'http_headers' => 'Headers',
                            'ssl_https' => 'SSL',
                            'core_integrity' => 'Core',
                            'plugins' => 'Plugins',
                        ];
                    @endphp
                    @foreach($this->latestScan->scores_breakdown as $cat => $catScore)
                        <div class="text-center">
                            <div class="mx-auto mb-1 h-2 w-full rounded-full bg-gray-100">
                                <div class="h-2 rounded-full {{ $catScore >= 80 ? 'bg-green-500' : ($catScore >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}"
                                     style="width: {{ $catScore }}%"></div>
                            </div>
                            <span class="text-xs text-gray-500">{{ $categoryLabels[$cat] ?? ucfirst($cat) }}</span>
                            <span class="block text-xs font-medium text-gray-700">{{ $catScore }}%</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Sub-navigation Pills --}}
    <div class="mb-6 flex gap-1 rounded-lg bg-gray-100 p-1">
        @foreach(['overview' => 'Overview', 'recommendations' => 'Recommendations', 'vulnerabilities' => 'Vulnerabilities'] as $tab => $label)
            <button wire:click="$set('securityTab', '{{ $tab }}')"
                    class="flex-1 rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $securityTab === $tab ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                {{ $label }}
                @if($tab === 'vulnerabilities' && $this->vulnerabilities->count() > 0)
                    <span class="ml-1 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-100 px-1.5 text-xs font-medium text-red-700">
                        {{ $this->vulnerabilities->count() }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Overview Tab --}}
    @if($securityTab === 'overview')
        {{-- Active Issues --}}
        @if($this->activeIssues->count() > 0)
            <div class="mb-6">
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Active Issues ({{ $this->activeIssues->count() }})</h3>
                    <div class="space-y-3">
                        @foreach($this->activeIssues as $issue)
                            <div class="flex items-start justify-between gap-4 rounded-lg border border-gray-100 p-3">
                                <div class="flex items-start gap-3">
                                    <x-ui.badge :variant="$issue->severity_color" class="mt-0.5 shrink-0">
                                        {{ ucfirst($issue->severity) }}
                                    </x-ui.badge>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ $issue->title }}</p>
                                        @if($issue->description)
                                            <p class="mt-0.5 text-xs text-gray-500">{{ Str::limit($issue->description, 120) }}</p>
                                        @endif
                                        @if($issue->recommendation)
                                            <p class="mt-1 text-xs text-purple-600">{{ Str::limit($issue->recommendation, 120) }}</p>
                                        @endif
                                        <span class="mt-1 inline-block text-xs text-gray-400">{{ $issue->category_label }}</span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <x-ui.button variant="secondary" size="sm" wire:click="resolveIssue({{ $issue->id }})" wire:loading.attr="disabled">
                                        Fix
                                    </x-ui.button>
                                    <x-ui.button variant="secondary" size="sm" wire:click="ignoreIssue({{ $issue->id }})" wire:loading.attr="disabled">
                                        Ignore
                                    </x-ui.button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            </div>
        @endif

        {{-- Existing SSL & Domain Cards --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- SSL Certificate Card --}}
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">SSL Certificate</h3>
                    @if($this->sslCertificate)
                        <x-ui.badge :variant="$this->sslCertificate->status_color">
                            {{ $this->sslCertificate->status_label }}
                        </x-ui.badge>
                    @else
                        <x-ui.badge variant="gray">No HTTPS</x-ui.badge>
                    @endif
                </div>

                @if($this->sslCertificate)
                    <div class="mb-4 space-y-2">
                        @if($this->sslCertificate->expires_at)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Expires</span>
                                <span class="font-medium text-gray-900">
                                    {{ $this->sslCertificate->expires_at->format('M d, Y') }}
                                    @if($this->sslCertificate->days_remaining !== null)
                                        <span class="ml-1 {{ $this->sslCertificate->days_remaining <= 0 ? 'text-red-600' : ($this->sslCertificate->days_remaining <= 30 ? 'text-yellow-600' : 'text-green-600') }}">
                                            ({{ $this->sslCertificate->days_remaining }} days)
                                        </span>
                                    @endif
                                </span>
                            </div>
                        @endif
                        @if($this->sslCertificate->issuer)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Issuer</span>
                                <span class="font-medium text-gray-900">{{ $this->sslCertificate->issuer }}</span>
                            </div>
                        @endif
                    </div>

                    <details class="mb-4">
                        <summary class="cursor-pointer text-sm font-medium text-purple-600 hover:text-purple-700">Show Details</summary>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Domain</dt>
                                <dd class="text-gray-900">{{ $this->sslCertificate->domain }}</dd>
                            </div>
                            @if($this->sslCertificate->san_domains && count($this->sslCertificate->san_domains) > 0)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">SAN Domains</dt>
                                    <dd class="text-gray-900 text-right">{{ implode(', ', $this->sslCertificate->san_domains) }}</dd>
                                </div>
                            @endif
                            @if($this->sslCertificate->protocol)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Protocol</dt>
                                    <dd class="text-gray-900">{{ $this->sslCertificate->protocol }}</dd>
                                </div>
                            @endif
                            @if($this->sslCertificate->cipher)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Cipher</dt>
                                    <dd class="text-gray-900">{{ $this->sslCertificate->cipher }}</dd>
                                </div>
                            @endif
                            @if($this->sslCertificate->key_size)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Key Size</dt>
                                    <dd class="text-gray-900">{{ $this->sslCertificate->key_size }} bits</dd>
                                </div>
                            @endif
                            @if($this->sslCertificate->signature_algorithm)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Signature</dt>
                                    <dd class="text-gray-900">{{ $this->sslCertificate->signature_algorithm }}</dd>
                                </div>
                            @endif
                            @if($this->sslCertificate->issued_at)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Issued</dt>
                                    <dd class="text-gray-900">{{ $this->sslCertificate->issued_at->format('M d, Y') }}</dd>
                                </div>
                            @endif
                            @if($this->sslCertificate->expires_at)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Expires</dt>
                                    <dd class="text-gray-900">{{ $this->sslCertificate->expires_at->format('M d, Y') }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Chain Valid</dt>
                                <dd>
                                    <x-ui.badge :variant="$this->sslCertificate->chain_valid ? 'green' : 'red'">
                                        {{ $this->sslCertificate->chain_valid ? 'Yes' : 'No' }}
                                    </x-ui.badge>
                                </dd>
                            </div>
                        </dl>
                    </details>

                    @if($this->sslCertificate->error_message)
                        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                            {{ $this->sslCertificate->error_message }}
                        </div>
                    @endif

                    <div class="mb-4 border-t pt-4">
                        <h4 class="text-sm font-medium text-gray-700 mb-2">Alert Settings</h4>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox"
                                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                       @checked($this->sslCertificate->alerts_enabled)
                                       wire:change="updateSslAlertSettings($event.target.checked, {{ $this->sslCertificate->warn_days }})">
                                Alerts enabled
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <span class="text-gray-500">Warn at</span>
                                <select class="rounded-lg border border-gray-300 px-2 py-1 text-sm"
                                        wire:change="updateSslAlertSettings({{ $this->sslCertificate->alerts_enabled ? 'true' : 'false' }}, $event.target.value)">
                                    @foreach([7, 14, 21, 30, 60, 90] as $days)
                                        <option value="{{ $days }}" @selected($this->sslCertificate->warn_days === $days)>{{ $days }} days</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t pt-4">
                        <span class="text-xs text-gray-500">
                            Last checked: {{ $this->sslCertificate->last_checked_at?->diffForHumans() ?? 'Never' }}
                        </span>
                        <x-ui.button variant="secondary" size="sm" wire:click="checkSslNow" wire:loading.attr="disabled">
                            <svg class="h-3.5 w-3.5 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="checkSslNow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Check Now
                        </x-ui.button>
                    </div>
                @else
                    <p class="text-sm text-gray-500">This site does not use HTTPS. No SSL certificate to monitor.</p>
                @endif
            </x-ui.card>

            {{-- Domain Registration Card --}}
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Domain Registration</h3>
                    @if($this->domainMonitor)
                        <x-ui.badge :variant="$this->domainMonitor->status_color">
                            {{ $this->domainMonitor->status_label }}
                        </x-ui.badge>
                    @else
                        <x-ui.badge variant="gray">Not Monitored</x-ui.badge>
                    @endif
                </div>

                @if($this->domainMonitor)
                    <div class="mb-4 space-y-2">
                        @if($this->domainMonitor->expires_at)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Expires</span>
                                <span class="font-medium text-gray-900">
                                    {{ $this->domainMonitor->expires_at->format('M d, Y') }}
                                    @if($this->domainMonitor->days_remaining !== null)
                                        <span class="ml-1 {{ $this->domainMonitor->days_remaining <= 0 ? 'text-red-600' : ($this->domainMonitor->days_remaining <= 30 ? 'text-yellow-600' : 'text-green-600') }}">
                                            ({{ $this->domainMonitor->days_remaining }} days)
                                        </span>
                                    @endif
                                </span>
                            </div>
                        @endif
                        @if($this->domainMonitor->registrar)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Registrar</span>
                                <span class="font-medium text-gray-900">{{ $this->domainMonitor->registrar }}</span>
                            </div>
                        @endif
                    </div>

                    <details class="mb-4">
                        <summary class="cursor-pointer text-sm font-medium text-purple-600 hover:text-purple-700">Show Details</summary>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Domain</dt>
                                <dd class="text-gray-900">{{ $this->domainMonitor->domain }}</dd>
                            </div>
                            @if($this->domainMonitor->registrar)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Registrar</dt>
                                    <dd class="text-gray-900">
                                        {{ $this->domainMonitor->registrar }}
                                        @if($this->domainMonitor->registrar_url)
                                            <a href="{{ $this->domainMonitor->registrar_url }}" target="_blank" class="ml-1 text-purple-600 hover:text-purple-700">&rarr;</a>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                            @if($this->domainMonitor->registered_at)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Registered</dt>
                                    <dd class="text-gray-900">{{ $this->domainMonitor->registered_at->format('M d, Y') }}</dd>
                                </div>
                            @endif
                            @if($this->domainMonitor->expires_at)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Expires</dt>
                                    <dd class="text-gray-900">{{ $this->domainMonitor->expires_at->format('M d, Y') }}</dd>
                                </div>
                            @endif
                            @if($this->domainMonitor->nameservers && count($this->domainMonitor->nameservers) > 0)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Nameservers</dt>
                                    <dd class="text-gray-900 text-right">
                                        @foreach($this->domainMonitor->nameservers as $ns)
                                            <div>{{ $ns }}</div>
                                        @endforeach
                                    </dd>
                                </div>
                            @endif
                            @if($this->domainMonitor->dns_provider)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">DNS Provider</dt>
                                    <dd class="text-gray-900">{{ $this->domainMonitor->dns_provider }}</dd>
                                </div>
                            @endif
                            @if($this->domainMonitor->domain_statuses && count($this->domainMonitor->domain_statuses) > 0)
                                <div>
                                    <dt class="text-gray-500 mb-1">Status Flags</dt>
                                    <dd class="flex flex-wrap gap-1">
                                        @foreach($this->domainMonitor->domain_statuses as $flag)
                                            <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $flag }}</span>
                                        @endforeach
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </details>

                    @if($this->domainMonitor->error_message)
                        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                            {{ $this->domainMonitor->error_message }}
                        </div>
                    @endif

                    <div class="mb-4 border-t pt-4">
                        <h4 class="text-sm font-medium text-gray-700 mb-2">Alert Settings</h4>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox"
                                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                       @checked($this->domainMonitor->alerts_enabled)
                                       wire:change="updateDomainAlertSettings($event.target.checked, {{ $this->domainMonitor->warn_days }})">
                                Alerts enabled
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <span class="text-gray-500">Warn at</span>
                                <select class="rounded-lg border border-gray-300 px-2 py-1 text-sm"
                                        wire:change="updateDomainAlertSettings({{ $this->domainMonitor->alerts_enabled ? 'true' : 'false' }}, $event.target.value)">
                                    @foreach([7, 14, 21, 30, 60, 90] as $days)
                                        <option value="{{ $days }}" @selected($this->domainMonitor->warn_days === $days)>{{ $days }} days</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t pt-4">
                        <span class="text-xs text-gray-500">
                            Last checked: {{ $this->domainMonitor->last_checked_at?->diffForHumans() ?? 'Never' }}
                        </span>
                        <x-ui.button variant="secondary" size="sm" wire:click="checkDomainNow" wire:loading.attr="disabled">
                            <svg class="h-3.5 w-3.5 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="checkDomainNow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Check Now
                        </x-ui.button>
                    </div>
                @else
                    <p class="text-sm text-gray-500">No domain monitor configured for this site.</p>
                @endif
            </x-ui.card>
        </div>

        {{-- Core File Integrity Card --}}
        <div class="mt-6">
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Core File Integrity</h3>
                    @if($this->latestCoreCheck)
                        <x-ui.badge :variant="$this->latestCoreCheck->status_color">
                            {{ $this->latestCoreCheck->status_label }}
                        </x-ui.badge>
                    @else
                        <x-ui.badge variant="gray">Not Checked</x-ui.badge>
                    @endif
                </div>

                @if(session('core-check-dispatched'))
                    <div class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">{{ session('core-check-dispatched') }}</div>
                @endif

                @if($this->latestCoreCheck)
                    <div class="mb-4 space-y-2">
                        @if($this->latestCoreCheck->wp_version)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">WordPress Version</span>
                                <span class="font-medium text-gray-900">{{ $this->latestCoreCheck->wp_version }}</span>
                            </div>
                        @endif
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Total Files Checked</span>
                            <span class="font-medium text-gray-900">{{ number_format($this->latestCoreCheck->total_files) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Modified</span>
                            <span class="font-medium {{ $this->latestCoreCheck->modified_count > 0 ? 'text-red-600' : 'text-gray-900' }}">
                                {{ $this->latestCoreCheck->modified_count }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Missing</span>
                            <span class="font-medium {{ $this->latestCoreCheck->missing_count > 0 ? 'text-yellow-600' : 'text-gray-900' }}">
                                {{ $this->latestCoreCheck->missing_count }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Unknown</span>
                            <span class="font-medium {{ $this->latestCoreCheck->unknown_count > 0 ? 'text-yellow-600' : 'text-gray-900' }}">
                                {{ $this->latestCoreCheck->unknown_count }}
                            </span>
                        </div>
                    </div>

                    @if($this->latestCoreCheck->modified_files && count($this->latestCoreCheck->modified_files) > 0)
                        <details class="mb-3">
                            <summary class="cursor-pointer text-sm font-medium text-red-600 hover:text-red-700">
                                {{ count($this->latestCoreCheck->modified_files) }} Modified File(s)
                            </summary>
                            <div class="mt-2 max-h-60 overflow-y-auto rounded-lg bg-red-50 p-3">
                                @foreach($this->latestCoreCheck->modified_files as $file)
                                    <div class="mb-2 last:mb-0 text-xs">
                                        <div class="font-medium text-red-800">{{ $file['path'] }}</div>
                                        <div class="text-red-600">
                                            Expected: <code class="bg-red-100 px-1 rounded">{{ Str::limit($file['expected_hash'], 16) }}</code>
                                            Actual: <code class="bg-red-100 px-1 rounded">{{ Str::limit($file['actual_hash'], 16) }}</code>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @if($this->latestCoreCheck->missing_files && count($this->latestCoreCheck->missing_files) > 0)
                        <details class="mb-3">
                            <summary class="cursor-pointer text-sm font-medium text-yellow-600 hover:text-yellow-700">
                                {{ count($this->latestCoreCheck->missing_files) }} Missing File(s)
                            </summary>
                            <div class="mt-2 max-h-60 overflow-y-auto rounded-lg bg-yellow-50 p-3">
                                @foreach($this->latestCoreCheck->missing_files as $path)
                                    <div class="text-xs text-yellow-800">{{ $path }}</div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @if($this->latestCoreCheck->unknown_files && count($this->latestCoreCheck->unknown_files) > 0)
                        <details class="mb-3">
                            <summary class="cursor-pointer text-sm font-medium text-yellow-600 hover:text-yellow-700">
                                {{ count($this->latestCoreCheck->unknown_files) }} Unknown File(s)
                            </summary>
                            <div class="mt-2 max-h-60 overflow-y-auto rounded-lg bg-yellow-50 p-3">
                                @foreach($this->latestCoreCheck->unknown_files as $path)
                                    <div class="text-xs text-yellow-800">{{ $path }}</div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @if($this->latestCoreCheck->error_message)
                        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                            {{ $this->latestCoreCheck->error_message }}
                        </div>
                    @endif

                    <div class="flex items-center justify-between border-t pt-4">
                        <span class="text-xs text-gray-500">
                            Last checked: {{ $this->latestCoreCheck->checked_at?->diffForHumans() ?? 'Never' }}
                        </span>
                        <x-ui.button variant="secondary" size="sm" wire:click="checkCoreIntegrityNow" wire:loading.attr="disabled">
                            <svg class="h-3.5 w-3.5 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="checkCoreIntegrityNow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Check Now
                        </x-ui.button>
                    </div>
                @else
                    <p class="text-sm text-gray-500 mb-4">No core file integrity check has been performed yet.</p>
                    <x-ui.button variant="secondary" size="sm" wire:click="checkCoreIntegrityNow" wire:loading.attr="disabled">
                        <svg class="h-3.5 w-3.5 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="checkCoreIntegrityNow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Check Now
                    </x-ui.button>
                @endif
            </x-ui.card>
        </div>

        {{-- SSL Check History --}}
        @if($this->sslCertificate && $this->sslHistory->count() > 0)
            <div class="mt-6">
                <x-ui.card>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">SSL Check History</h3>
                    <x-ui.table>
                        <x-slot:head>
                            <x-ui.th>Date</x-ui.th>
                            <x-ui.th>Status</x-ui.th>
                            <x-ui.th>Days Left</x-ui.th>
                            <x-ui.th>Issuer</x-ui.th>
                            <x-ui.th>Protocol</x-ui.th>
                            <x-ui.th>Handshake</x-ui.th>
                        </x-slot:head>
                        @foreach($this->sslHistory as $check)
                            <tr>
                                <x-ui.td>{{ $check->checked_at->format('M d, Y H:i') }}</x-ui.td>
                                <x-ui.td>
                                    @php
                                        $histColor = match($check->status) {
                                            'valid' => 'green',
                                            'expiring_soon' => 'yellow',
                                            'expired', 'error' => 'red',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <x-ui.badge :variant="$histColor">{{ ucfirst(str_replace('_', ' ', $check->status)) }}</x-ui.badge>
                                </x-ui.td>
                                <x-ui.td>{{ $check->days_remaining ?? '—' }}</x-ui.td>
                                <x-ui.td>{{ $check->issuer ?? '—' }}</x-ui.td>
                                <x-ui.td>{{ $check->protocol ?? '—' }}</x-ui.td>
                                <x-ui.td>{{ $check->handshake_time ? $check->handshake_time . 'ms' : '—' }}</x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            </div>
        @endif
    @endif

    {{-- Recommendations Tab --}}
    @if($securityTab === 'recommendations')
        <x-ui.card>
            {{-- Progress Bar --}}
            <div class="mb-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-lg font-semibold text-gray-900">Security Recommendations</h3>
                    <span class="text-sm text-gray-500">
                        {{ $this->recommendationStats['passed'] }} of {{ $this->recommendationStats['total'] }} passed
                    </span>
                </div>
                <div class="h-3 w-full rounded-full bg-gray-100">
                    @php
                        $pct = $this->recommendationStats['total'] > 0
                            ? round(($this->recommendationStats['passed'] / $this->recommendationStats['total']) * 100)
                            : 0;
                    @endphp
                    <div class="h-3 rounded-full {{ $pct >= 80 ? 'bg-green-500' : ($pct >= 50 ? 'bg-yellow-500' : 'bg-red-500') }} transition-all"
                         style="width: {{ $pct }}%"></div>
                </div>
            </div>

            @if($this->recommendations->isEmpty())
                <p class="text-sm text-gray-500">No recommendations loaded yet. Run a security scan first.</p>
            @else
                @php
                    $categoryLabels = [
                        'file_security' => 'File Security',
                        'login_security' => 'Login Security',
                        'database_security' => 'Database Security',
                        'http_headers' => 'HTTP Headers',
                        'ssl_https' => 'SSL & HTTPS',
                    ];
                @endphp

                <div class="space-y-6">
                    @foreach($this->recommendations as $category => $recs)
                        <div x-data="{ open: true }">
                            <button @click="open = !open" class="flex w-full items-center justify-between rounded-lg bg-gray-50 px-4 py-3 text-left">
                                <div class="flex items-center gap-3">
                                    <h4 class="text-sm font-semibold text-gray-900">{{ $categoryLabels[$category] ?? ucfirst(str_replace('_', ' ', $category)) }}</h4>
                                    <span class="text-xs text-gray-500">
                                        {{ $recs->where('status', 'passed')->count() }} / {{ $recs->count() }} passed
                                    </span>
                                </div>
                                <svg class="h-4 w-4 text-gray-500 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-collapse class="mt-2 space-y-2">
                                @foreach($recs as $rec)
                                    <div class="flex items-start justify-between gap-4 rounded-lg border border-gray-100 p-3">
                                        <div class="flex items-start gap-3">
                                            <div class="mt-0.5 shrink-0">
                                                @if($rec->status === 'passed')
                                                    <x-icons.check-circle class="h-5 w-5 text-green-500" />
                                                @elseif($rec->status === 'failed')
                                                    <x-icons.x-circle class="h-5 w-5 text-red-500" />
                                                @else
                                                    <x-icons.clock class="h-5 w-5 text-gray-400" />
                                                @endif
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-900">{{ $rec->title }}</p>
                                                <p class="mt-0.5 text-xs text-gray-500">{{ $rec->description }}</p>
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 gap-1">
                                            @if($rec->status === 'failed' && $rec->can_auto_fix)
                                                <x-ui.button variant="primary" size="sm" wire:click="fixRecommendation('{{ $rec->key }}')" wire:loading.attr="disabled">
                                                    Fix
                                                </x-ui.button>
                                            @endif
                                            @if($rec->status !== 'ignored' && $rec->status !== 'passed')
                                                <x-ui.button variant="secondary" size="sm" wire:click="ignoreRecommendation({{ $rec->id }})" wire:loading.attr="disabled">
                                                    Ignore
                                                </x-ui.button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- Vulnerabilities Tab --}}
    @if($securityTab === 'vulnerabilities')
        <x-ui.card>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Plugin Vulnerabilities</h3>
                @if($this->vulnerabilities->count() > 0)
                    <div class="flex gap-2">
                        @php
                            $vulnCritical = $this->vulnerabilities->where('severity', 'critical')->count();
                            $vulnHigh = $this->vulnerabilities->where('severity', 'high')->count();
                            $vulnMedium = $this->vulnerabilities->where('severity', 'medium')->count();
                            $vulnLow = $this->vulnerabilities->where('severity', 'low')->count();
                        @endphp
                        @if($vulnCritical > 0)
                            <x-ui.badge variant="red">{{ $vulnCritical }} Critical</x-ui.badge>
                        @endif
                        @if($vulnHigh > 0)
                            <x-ui.badge variant="orange">{{ $vulnHigh }} High</x-ui.badge>
                        @endif
                        @if($vulnMedium > 0)
                            <x-ui.badge variant="yellow">{{ $vulnMedium }} Medium</x-ui.badge>
                        @endif
                        @if($vulnLow > 0)
                            <x-ui.badge variant="blue">{{ $vulnLow }} Low</x-ui.badge>
                        @endif
                    </div>
                @endif
            </div>

            @if($this->vulnerabilities->count() === 0)
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <x-icons.shield class="h-12 w-12 text-green-300 mb-3" />
                    <p class="text-sm font-medium text-gray-900">No vulnerabilities detected</p>
                    <p class="text-xs text-gray-500 mt-1">All installed plugins appear to be secure.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($this->vulnerabilities as $vuln)
                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <x-ui.badge :variant="$vuln->severity_color" class="mt-0.5 shrink-0">
                                        {{ ucfirst($vuln->severity) }}
                                    </x-ui.badge>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ $vuln->title }}</p>
                                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                            <span>{{ ucfirst($vuln->software_type) }}: <span class="font-medium text-gray-700">{{ $vuln->software_slug }}</span></span>
                                            @if($vuln->installed_version)
                                                <span>Installed: <span class="font-medium text-gray-700">{{ $vuln->installed_version }}</span></span>
                                            @endif
                                            @if($vuln->fixed_in_version)
                                                <span>Fixed in: <span class="font-medium text-green-600">{{ $vuln->fixed_in_version }}</span></span>
                                            @endif
                                            @if($vuln->vulnerability_id)
                                                <span class="text-gray-400">{{ $vuln->vulnerability_id }}</span>
                                            @endif
                                        </div>
                                        @if($vuln->description)
                                            <p class="mt-1.5 text-xs text-gray-500">{{ Str::limit($vuln->description, 200) }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    @if($vuln->is_fixable)
                                        <a href="{{ route('sites.updates', $this->site) }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            Update
                                        </a>
                                    @endif
                                    <x-ui.button variant="secondary" size="sm" wire:click="ignoreVulnerability({{ $vuln->id }})" wire:loading.attr="disabled">
                                        Ignore
                                    </x-ui.button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
