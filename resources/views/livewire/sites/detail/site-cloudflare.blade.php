<div>
    @if(session('cf-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('cf-success') }}</div>
    @endif
    @if(session('cf-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('cf-error') }}</div>
    @endif

    @if(!$this->siteCloudflare)
        {{-- Not connected --}}
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-900">Connect Cloudflare</h2>
            <p class="text-sm text-gray-500">Connect this site to a Cloudflare zone to manage DNS, cache, security, and analytics.</p>
        </div>

        <x-ui.card>
            @if($this->connections->isEmpty())
                <x-ui.empty-state
                    title="No Cloudflare connections"
                    description="Add a Cloudflare API token in Settings > Integrations first."
                    icon="cloud"
                />
            @else
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cloudflare Connection</label>
                        <select wire:model.live="selectedConnectionId" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="">Select a connection...</option>
                            @foreach($this->connections as $conn)
                                <option value="{{ $conn->id }}">{{ $conn->account_email ?: 'Connection #' . $conn->id }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if($selectedConnectionId)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Zone</label>
                            <select wire:model="selectedZoneId" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="">Select a zone...</option>
                                @foreach($this->availableZones as $zone)
                                    <option value="{{ $zone['id'] }}">{{ $zone['name'] }} ({{ $zone['status'] }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex justify-end">
                            <x-ui.button wire:click="connectToZone" wire:loading.attr="disabled">
                                Connect Zone
                            </x-ui.button>
                        </div>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @else
        {{-- Connected --}}
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $this->siteCloudflare->zone_name }}</h2>
                <p class="text-sm text-gray-500">
                    Plan: {{ $this->siteCloudflare->plan_label ?? 'N/A' }}
                    &middot; Status: {{ ucfirst($this->siteCloudflare->status) }}
                    @if($this->siteCloudflare->ssl_mode)
                        &middot; SSL: {{ strtoupper(str_replace('_', ' ', $this->siteCloudflare->ssl_mode)) }}
                    @endif
                    @if($this->siteCloudflare->is_paused)
                        &middot; <span class="text-yellow-600">Paused</span>
                    @endif
                </p>
            </div>
            <x-ui.button variant="secondary" wire:click="disconnectZone" wire:confirm="Are you sure you want to disconnect this Cloudflare zone?">
                Disconnect
            </x-ui.button>
        </div>

        {{-- Tabs --}}
        <div class="mb-6 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                @foreach(['dns' => 'DNS', 'cache' => 'Cache', 'security' => 'Security', 'analytics' => 'Analytics'] as $key => $label)
                    <button wire:click="$set('tab', '{{ $key }}')"
                        class="whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium transition {{ $tab === $key ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- DNS Tab --}}
        @if($tab === 'dns')
            <x-ui.card class="mb-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Add DNS Record</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
                        <select wire:model="dnsType" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                            @foreach(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'] as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                        <input type="text" wire:model="dnsName" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="@ or subdomain">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Content</label>
                        <input type="text" wire:model="dnsContent" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="IP address or value">
                    </div>
                    <div class="flex items-end">
                        <x-ui.button wire:click="addDnsRecord" size="sm" class="w-full">Add</x-ui.button>
                    </div>
                </div>
                @if(in_array($dnsType, ['A', 'AAAA', 'CNAME']))
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" wire:model="dnsProxied" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        Proxied (orange cloud)
                    </label>
                @endif
            </x-ui.card>

            {{-- Import/Export Bar --}}
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
                <x-ui.button variant="secondary" size="sm" wire:click="exportDnsRecords">
                    <svg class="h-4 w-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    Export JSON
                </x-ui.button>
                <div class="flex items-center gap-2">
                    <input type="file" wire:model="dnsImportFile" accept=".json" class="text-sm text-gray-500 file:mr-2 file:rounded-lg file:border-0 file:bg-purple-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-purple-700 hover:file:bg-purple-100">
                    @if($dnsImportFile)
                        <x-ui.button size="sm" wire:click="importDnsRecords">Import</x-ui.button>
                    @endif
                </div>
                @error('dnsImportFile') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>

            <x-ui.card>
                <h3 class="text-sm font-semibold text-gray-900 mb-4">DNS Records</h3>
                @if(empty($this->dnsRecords))
                    <p class="text-sm text-gray-500">No DNS records found.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                                    <th class="pb-2 pr-4">Type</th>
                                    <th class="pb-2 pr-4">Name</th>
                                    <th class="pb-2 pr-4">Content</th>
                                    <th class="pb-2 pr-4">TTL</th>
                                    <th class="pb-2 pr-4">Proxy</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($this->dnsRecords as $record)
                                    <tr>
                                        <td class="py-2 pr-4">
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $record['type'] }}</span>
                                        </td>
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $record['name'] }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs max-w-xs truncate">{{ $record['content'] }}</td>
                                        <td class="py-2 pr-4 text-xs text-gray-500">{{ $record['ttl'] == 1 ? 'Auto' : $record['ttl'] . 's' }}</td>
                                        <td class="py-2 pr-4">
                                            @if(in_array($record['type'], ['A', 'AAAA', 'CNAME']))
                                                <button wire:click="toggleProxy('{{ $record['id'] }}', '{{ $record['type'] }}', '{{ $record['name'] }}', '{{ $record['content'] }}', {{ $record['ttl'] }}, {{ $record['proxied'] ? 'true' : 'false' }})"
                                                    class="text-xs {{ $record['proxied'] ? 'text-orange-500' : 'text-gray-400' }}">
                                                    <svg class="h-4 w-4" fill="{{ $record['proxied'] ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                                </button>
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <button wire:click="openDnsEditModal('{{ $record['id'] }}', '{{ $record['type'] }}', '{{ $record['name'] }}', '{{ addslashes($record['content']) }}', {{ $record['ttl'] }}, {{ ($record['proxied'] ?? false) ? 'true' : 'false' }})"
                                                    class="text-gray-400 hover:text-purple-600">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                                </button>
                                                <button wire:click="deleteDnsRecord('{{ $record['id'] }}')"
                                                    wire:confirm="Delete this DNS record?"
                                                    class="text-gray-400 hover:text-red-600">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            {{-- DNS Edit Modal --}}
            @if($showDnsEditModal)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showDnsEditModal', false)">
                    <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                        <h3 class="text-base font-semibold text-gray-900 mb-4">Edit DNS Record</h3>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
                                <select wire:model="editDnsType" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                    @foreach(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'] as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                                <input type="text" wire:model="editDnsName" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                @error('editDnsName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Content</label>
                                <input type="text" wire:model="editDnsContent" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                @error('editDnsContent') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">TTL</label>
                                <select wire:model="editDnsTtl" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                    <option value="1">Auto</option>
                                    <option value="120">2 min</option>
                                    <option value="300">5 min</option>
                                    <option value="600">10 min</option>
                                    <option value="900">15 min</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hour</option>
                                    <option value="86400">1 day</option>
                                </select>
                            </div>
                            @if(in_array($editDnsType, ['A', 'AAAA', 'CNAME']))
                                <label class="flex items-center gap-2 text-sm text-gray-600">
                                    <input type="checkbox" wire:model="editDnsProxied" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                    Proxied (orange cloud)
                                </label>
                            @endif
                        </div>
                        <div class="mt-5 flex justify-end gap-2">
                            <x-ui.button variant="secondary" wire:click="$set('showDnsEditModal', false)">Cancel</x-ui.button>
                            <x-ui.button wire:click="updateDnsRecord">Save Changes</x-ui.button>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        {{-- Cache Tab --}}
        @if($tab === 'cache')
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">Purge Everything</h3>
                    <p class="text-sm text-gray-500 mb-4">Remove all cached files from Cloudflare's edge servers. This may temporarily slow down your site.</p>
                    <x-ui.button variant="danger" wire:click="purgeEverything" wire:confirm="Purge all cached files? This cannot be undone.">
                        Purge Everything
                    </x-ui.button>
                </x-ui.card>

                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">Purge by URL</h3>
                    <p class="text-sm text-gray-500 mb-2">Enter one URL per line to purge specific pages.</p>
                    <textarea wire:model="purgeUrls" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="https://example.com/page-1&#10;https://example.com/page-2"></textarea>
                    <div class="mt-3 flex justify-end">
                        <x-ui.button wire:click="purgeByUrls" size="sm">Purge URLs</x-ui.button>
                    </div>
                </x-ui.card>
            </div>

            <x-ui.card class="mt-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Purge History</h3>
                @if($this->cachePurges->isEmpty())
                    <p class="text-sm text-gray-500">No cache purges recorded.</p>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach($this->cachePurges as $purge)
                            <div class="flex items-center justify-between py-3">
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ ucfirst($purge->type) }}</span>
                                    @if($purge->targets)
                                        <span class="text-xs text-gray-500 ml-1">({{ count($purge->targets) }} {{ Str::plural('item', count($purge->targets)) }})</span>
                                    @endif
                                    <div class="text-xs text-gray-500">
                                        By {{ $purge->purgedBy?->name ?? 'System' }}
                                    </div>
                                </div>
                                <span class="text-xs text-gray-500">{{ $purge->purged_at?->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif

        {{-- Security Tab --}}
        @if($tab === 'security')
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">Security Level</h3>
                    <p class="text-sm text-gray-500 mb-3">Current level: <span class="font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $this->securityLevel)) }}</span></p>
                    <div class="flex items-center gap-3">
                        <select wire:model="newSecurityLevel" class="rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                            @foreach(['essentially_off' => 'Essentially Off', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'under_attack' => 'Under Attack'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-ui.button wire:click="setSecurityLevel" size="sm">Apply</x-ui.button>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">Under Attack Mode</h3>
                    <p class="text-sm text-gray-500 mb-3">Enable "I'm Under Attack" mode to add extra protection during an active DDoS attack.</p>
                    <x-ui.button
                        wire:click="toggleUnderAttack"
                        variant="{{ $this->securityLevel === 'under_attack' ? 'danger' : 'secondary' }}"
                    >
                        {{ $this->securityLevel === 'under_attack' ? 'Disable Under Attack Mode' : 'Enable Under Attack Mode' }}
                    </x-ui.button>
                </x-ui.card>
            </div>

            {{-- WAF Status + Block IP --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mt-6">
                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">WAF Status</h3>
                    <div class="flex items-center gap-2">
                        @if($this->wafStatus === 'on')
                            <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700">Enabled</span>
                        @elseif($this->wafStatus === 'off')
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Disabled</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-yellow-50 px-2.5 py-0.5 text-xs font-medium text-yellow-700">{{ ucfirst($this->wafStatus) }}</span>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Web Application Firewall protects against common web exploits. Manage WAF rules in the Cloudflare dashboard.</p>
                </x-ui.card>

                <x-ui.card>
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">Block IP via Cloudflare</h3>
                    <div class="space-y-3">
                        <div>
                            <input type="text" wire:model="blockIp" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="IP address (e.g. 192.168.1.1)">
                            @error('blockIp') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <input type="text" wire:model="blockNote" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="Note (optional)">
                        </div>
                        <x-ui.button wire:click="blockIpViaCf" size="sm">Block IP</x-ui.button>
                    </div>
                </x-ui.card>
            </div>

            {{-- IP Access Rules --}}
            @if(!empty($this->accessRules))
                <x-ui.card class="mt-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">IP Access Rules</h3>
                    <div class="divide-y divide-gray-100">
                        @foreach($this->accessRules as $rule)
                            <div class="flex items-center justify-between py-3">
                                <div>
                                    <span class="font-mono text-sm text-gray-900">{{ $rule['configuration']['value'] ?? 'N/A' }}</span>
                                    <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ ($rule['mode'] ?? '') === 'block' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ ucfirst($rule['mode'] ?? 'N/A') }}
                                    </span>
                                    @if(!empty($rule['notes']))
                                        <span class="ml-2 text-xs text-gray-500">{{ $rule['notes'] }}</span>
                                    @endif
                                </div>
                                <button wire:click="removeAccessRule('{{ $rule['id'] }}')"
                                    wire:confirm="Remove this access rule?"
                                    class="text-gray-400 hover:text-red-600">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            {{-- Firewall Rules --}}
            <x-ui.card class="mt-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-gray-900">Firewall Rules</h3>
                    <x-ui.button size="sm" wire:click="openCreateFirewallModal">Add Rule</x-ui.button>
                </div>
                @if(empty($this->firewallRules))
                    <p class="text-sm text-gray-500">No firewall rules configured.</p>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach($this->firewallRules as $rule)
                            <div class="flex items-center justify-between py-3">
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-medium text-gray-900">{{ $rule['description'] ?? 'Unnamed rule' }}</span>
                                    <div class="text-xs text-gray-500">Action: {{ ucfirst($rule['action'] ?? 'N/A') }}</div>
                                    @if(!empty($rule['filter']['expression'] ?? null))
                                        <div class="mt-1 truncate font-mono text-xs text-gray-400">{{ $rule['filter']['expression'] }}</div>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 ml-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ ($rule['paused'] ?? false) ? 'bg-gray-100 text-gray-600' : 'bg-green-50 text-green-700' }}">
                                        {{ ($rule['paused'] ?? false) ? 'Paused' : 'Active' }}
                                    </span>
                                    <button wire:click="openEditFirewallModal('{{ $rule['id'] }}', '{{ addslashes($rule['description'] ?? '') }}', '{{ addslashes($rule['filter']['expression'] ?? '') }}', '{{ $rule['action'] ?? 'block' }}')"
                                        class="text-gray-400 hover:text-purple-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                    </button>
                                    <button wire:click="deleteFirewallRule('{{ $rule['id'] }}')"
                                        wire:confirm="Delete this firewall rule?"
                                        class="text-gray-400 hover:text-red-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- Firewall Rule Modal --}}
            @if($showFirewallModal)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showFirewallModal', false)">
                    <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ $editingFirewallRuleId ? 'Edit' : 'Create' }} Firewall Rule</h3>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                                <input type="text" wire:model="fwDescription" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="Rule description">
                                @error('fwDescription') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Filter Expression</label>
                                <textarea wire:model="fwExpression" rows="3" class="w-full rounded-lg border-gray-300 font-mono text-sm focus:border-purple-500 focus:ring-purple-500" placeholder='(ip.src eq 192.168.1.1) or (http.request.uri.path contains "/admin")'></textarea>
                                @error('fwExpression') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Action</label>
                                <select wire:model="fwAction" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                    <option value="block">Block</option>
                                    <option value="challenge">Challenge (CAPTCHA)</option>
                                    <option value="js_challenge">JS Challenge</option>
                                    <option value="managed_challenge">Managed Challenge</option>
                                    <option value="allow">Allow</option>
                                    <option value="log">Log</option>
                                    <option value="bypass">Bypass</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end gap-2">
                            <x-ui.button variant="secondary" wire:click="$set('showFirewallModal', false)">Cancel</x-ui.button>
                            <x-ui.button wire:click="saveFirewallRule">{{ $editingFirewallRuleId ? 'Update Rule' : 'Create Rule' }}</x-ui.button>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        {{-- Analytics Tab --}}
        @if($tab === 'analytics')
            <div class="mb-6 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Zone Analytics</h3>
                <select wire:model.live="analyticsPeriod" class="rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <option value="-30">Last 30 minutes</option>
                    <option value="-360">Last 6 hours</option>
                    <option value="-1440">Last 24 hours</option>
                    <option value="-10080">Last 7 days</option>
                    <option value="-43200">Last 30 days</option>
                </select>
            </div>

            @if(!empty($this->analytics))
                @php
                    $totals = $this->analytics['totals'] ?? [];
                    $requests = $totals['requests'] ?? [];
                    $bandwidth = $totals['bandwidth'] ?? [];
                    $threats = $totals['threats'] ?? [];
                    $pageviews = $totals['pageviews'] ?? [];
                @endphp

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <x-ui.card class="!p-4">
                        <p class="text-2xl font-bold text-gray-900">{{ number_format($requests['all'] ?? 0) }}</p>
                        <p class="text-xs text-gray-500">Total Requests</p>
                        @if(!empty($requests['cached']))
                            <p class="text-xs text-green-600 mt-1">{{ number_format($requests['cached']) }} cached</p>
                        @endif
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        @php
                            $totalBw = $bandwidth['all'] ?? 0;
                            $bwFormatted = $totalBw > 1073741824 ? round($totalBw / 1073741824, 2) . ' GB' : round($totalBw / 1048576, 2) . ' MB';
                        @endphp
                        <p class="text-2xl font-bold text-gray-900">{{ $bwFormatted }}</p>
                        <p class="text-xs text-gray-500">Bandwidth</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <p class="text-2xl font-bold text-gray-900">{{ number_format($threats['all'] ?? 0) }}</p>
                        <p class="text-xs text-gray-500">Threats Blocked</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <p class="text-2xl font-bold text-gray-900">{{ number_format($pageviews['all'] ?? 0) }}</p>
                        <p class="text-xs text-gray-500">Page Views</p>
                    </x-ui.card>
                </div>

                {{-- Country breakdown --}}
                @if(!empty($requests['country'] ?? []))
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Requests by Country</h3>
                        <div class="space-y-2">
                            @php
                                $countries = collect($requests['country'])->sortByDesc(fn ($v) => $v)->take(10);
                                $maxCount = $countries->first() ?: 1;
                            @endphp
                            @foreach($countries as $country => $count)
                                <div class="flex items-center gap-3">
                                    <span class="w-8 text-xs font-medium text-gray-600">{{ $country }}</span>
                                    <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full bg-purple-500" style="width: {{ round(($count / $maxCount) * 100) }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 w-16 text-right">{{ number_format($count) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            @else
                <x-ui.card>
                    <x-ui.empty-state
                        title="No analytics data"
                        description="Analytics data is not available for this period."
                        icon="bar-chart-2"
                    />
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
