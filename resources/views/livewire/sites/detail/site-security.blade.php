<div>
    <div class="mb-6 flex justify-end">
        <div class="flex gap-2">
            <x-ui.button variant="secondary" size="sm" wire:click="checkSslNow" wire:loading.attr="disabled">
                <svg class="h-4 w-4 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="checkSslNow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Refresh All
            </x-ui.button>
        </div>
    </div>

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
                {{-- Summary --}}
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

                {{-- Details --}}
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

                {{-- Error message --}}
                @if($this->sslCertificate->error_message)
                    <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                        {{ $this->sslCertificate->error_message }}
                    </div>
                @endif

                {{-- Alert Settings --}}
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

                {{-- Check Now --}}
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
                {{-- Summary --}}
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

                {{-- Details --}}
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

                {{-- Error message --}}
                @if($this->domainMonitor->error_message)
                    <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                        {{ $this->domainMonitor->error_message }}
                    </div>
                @endif

                {{-- Alert Settings --}}
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

                {{-- Check Now --}}
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
</div>
