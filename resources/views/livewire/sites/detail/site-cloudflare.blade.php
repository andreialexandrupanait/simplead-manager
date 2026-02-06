<div>
    @if(session('cf-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('cf-success') }}</div>
    @endif
    @if(session('cf-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('cf-error') }}</div>
    @endif

    @if(!$this->siteCloudflare)
        {{-- Not connected --}}
        <x-ui.page-header title="Cloudflare" subtitle="Connect this site to a Cloudflare zone to manage DNS, cache, security, and analytics" />

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
                        <x-ui.select wire:model.live="selectedConnectionId">
                            <option value="">Select a connection...</option>
                            @foreach($this->connections as $conn)
                                <option value="{{ $conn->id }}">{{ $conn->account_email ?: 'Connection #' . $conn->id }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    @if($selectedConnectionId)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Zone</label>
                            <x-ui.select wire:model="selectedZoneId">
                                <option value="">Select a zone...</option>
                                @foreach($this->availableZones as $zone)
                                    <option value="{{ $zone['id'] }}">{{ $zone['name'] }} ({{ $zone['status'] }})</option>
                                @endforeach
                            </x-ui.select>
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
                        <x-ui.select wire:model="dnsType">
                            @foreach(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'] as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                        <x-ui.input type="text" wire:model="dnsName" placeholder="@ or subdomain" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Content</label>
                        <x-ui.input type="text" wire:model="dnsContent" placeholder="IP address or value" />
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
                                <x-ui.select wire:model="editDnsType">
                                    @foreach(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'] as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                                <x-ui.input type="text" wire:model="editDnsName" />
                                @error('editDnsName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Content</label>
                                <x-ui.input type="text" wire:model="editDnsContent" />
                                @error('editDnsContent') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">TTL</label>
                                <x-ui.select wire:model="editDnsTtl">
                                    <option value="1">Auto</option>
                                    <option value="120">2 min</option>
                                    <option value="300">5 min</option>
                                    <option value="600">10 min</option>
                                    <option value="900">15 min</option>
                                    <option value="1800">30 min</option>
                                    <option value="3600">1 hour</option>
                                    <option value="86400">1 day</option>
                                </x-ui.select>
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
                        <x-ui.select wire:model="newSecurityLevel">
                            @foreach(['essentially_off' => 'Essentially Off', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'under_attack' => 'Under Attack'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
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
                            <x-ui.input type="text" wire:model="blockIp" placeholder="IP address (e.g. 192.168.1.1)" />
                            @error('blockIp') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-ui.input type="text" wire:model="blockNote" placeholder="Note (optional)" />
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
                                <x-ui.input type="text" wire:model="fwDescription" placeholder="Rule description" />
                                @error('fwDescription') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Filter Expression</label>
                                <textarea wire:model="fwExpression" rows="3" class="w-full rounded-lg border-gray-300 font-mono text-sm focus:border-purple-500 focus:ring-purple-500" placeholder='(ip.src eq 192.168.1.1) or (http.request.uri.path contains "/admin")'></textarea>
                                @error('fwExpression') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Action</label>
                                <x-ui.select wire:model="fwAction">
                                    <option value="block">Block</option>
                                    <option value="challenge">Challenge (CAPTCHA)</option>
                                    <option value="js_challenge">JS Challenge</option>
                                    <option value="managed_challenge">Managed Challenge</option>
                                    <option value="allow">Allow</option>
                                    <option value="log">Log</option>
                                    <option value="bypass">Bypass</option>
                                </x-ui.select>
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
                <x-ui.select wire:model.live="analyticsPeriod">
                    <option value="-30">Last 30 minutes</option>
                    <option value="-360">Last 6 hours</option>
                    <option value="-1440">Last 24 hours</option>
                    <option value="-10080">Last 7 days</option>
                    <option value="-43200">Last 30 days</option>
                </x-ui.select>
            </div>

            @if(!empty($this->analytics))
                @php
                    $totals = $this->analytics['totals'] ?? [];
                    $timeseries = $this->analytics['timeseries'] ?? [];
                    $requests = $totals['requests'] ?? [];
                    $bandwidth = $totals['bandwidth'] ?? [];
                    $threats = $totals['threats'] ?? [];
                    $pageviews = $totals['pageviews'] ?? [];
                    $uniques = $totals['uniques'] ?? [];
                    $totalReqs = $requests['all'] ?? 0;
                    $cachedReqs = $requests['cached'] ?? 0;
                    $uncachedReqs = $totalReqs - $cachedReqs;
                    $cachePercent = $totalReqs > 0 ? round(($cachedReqs / $totalReqs) * 100, 1) : 0;
                    $totalBw = $bandwidth['all'] ?? 0;
                    $cachedBw = $bandwidth['cached'] ?? 0;
                    $uncachedBw = $totalBw - $cachedBw;
                    $bwCachePercent = $totalBw > 0 ? round(($cachedBw / $totalBw) * 100, 1) : 0;
                @endphp

                {{-- Stats Cards --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100">
                                <svg class="h-5 w-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            </div>
                        </div>
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ number_format($totalReqs) }}</p>
                        <p class="text-xs text-gray-500">Total Requests</p>
                        <p class="text-xs text-green-600 mt-1">{{ number_format($cachedReqs) }} cached ({{ $cachePercent }}%)</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100">
                                <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            </div>
                        </div>
                        @php
                            $bwFormatted = $totalBw > 1073741824 ? round($totalBw / 1073741824, 2) . ' GB' : round($totalBw / 1048576, 2) . ' MB';
                            $cachedBwFormatted = $cachedBw > 1073741824 ? round($cachedBw / 1073741824, 2) . ' GB' : round($cachedBw / 1048576, 2) . ' MB';
                        @endphp
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ $bwFormatted }}</p>
                        <p class="text-xs text-gray-500">Bandwidth</p>
                        <p class="text-xs text-green-600 mt-1">{{ $cachedBwFormatted }} saved ({{ $bwCachePercent }}%)</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-100">
                                <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </div>
                        </div>
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ number_format($threats['all'] ?? 0) }}</p>
                        <p class="text-xs text-gray-500">Threats Blocked</p>
                    </x-ui.card>

                    <x-ui.card class="!p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100">
                                <svg class="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                        </div>
                        <p class="mt-3 text-2xl font-bold text-gray-900">{{ number_format($uniques['all'] ?? $pageviews['all'] ?? 0) }}</p>
                        <p class="text-xs text-gray-500">Unique Visitors</p>
                        @if(!empty($pageviews['all']))
                            <p class="text-xs text-gray-400 mt-1">{{ number_format($pageviews['all']) }} page views</p>
                        @endif
                    </x-ui.card>
                </div>

                {{-- Requests Over Time Chart --}}
                @if(!empty($timeseries))
                    <x-ui.card class="mb-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Requests Over Time</h3>
                        <div x-data="{
                            timeseries: @js(collect($timeseries)->map(fn($t) => [
                                'since' => $t['since'] ?? '',
                                'requests' => $t['requests']['all'] ?? 0,
                                'cached' => $t['requests']['cached'] ?? 0,
                            ])->values()->toArray()),
                            get maxVal() {
                                return Math.max(...this.timeseries.map(t => t.requests), 1);
                            },
                            formatNum(n) {
                                if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
                                if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
                                return n.toString();
                            },
                            formatTime(iso) {
                                if (!iso) return '';
                                let d = new Date(iso);
                                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' +
                                       d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
                            }
                        }" class="relative">
                            {{-- Chart --}}
                            <div class="flex items-end gap-px" style="height: 200px;">
                                <template x-for="(point, index) in timeseries" :key="index">
                                    <div class="relative flex-1 flex flex-col items-stretch justify-end group" style="min-width: 2px;">
                                        {{-- Tooltip --}}
                                        <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover:block z-10 pointer-events-none">
                                            <div class="rounded-lg bg-gray-900 px-3 py-2 text-xs text-white shadow-lg whitespace-nowrap">
                                                <div class="font-medium" x-text="formatTime(point.since)"></div>
                                                <div class="mt-1 flex items-center gap-1.5">
                                                    <span class="h-2 w-2 rounded-full bg-purple-400"></span>
                                                    <span>Total: <span x-text="formatNum(point.requests)"></span></span>
                                                </div>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="h-2 w-2 rounded-full bg-green-400"></span>
                                                    <span>Cached: <span x-text="formatNum(point.cached)"></span></span>
                                                </div>
                                            </div>
                                        </div>
                                        {{-- Bar (uncached background + cached overlay) --}}
                                        <div class="w-full rounded-t bg-purple-200 transition-all group-hover:bg-purple-300"
                                             :style="'height: ' + Math.max((point.requests / maxVal) * 100, 1) + '%'">
                                            <div class="w-full rounded-t bg-purple-500 transition-all"
                                                 :style="'height: ' + (point.requests > 0 ? (point.cached / point.requests) * 100 : 0) + '%'">
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            {{-- Legend --}}
                            <div class="mt-3 flex items-center justify-center gap-4 text-xs text-gray-500">
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-sm bg-purple-500"></span>
                                    Cached
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-sm bg-purple-200"></span>
                                    Uncached
                                </div>
                            </div>
                        </div>
                    </x-ui.card>
                @endif

                {{-- Cached vs Uncached Breakdown --}}
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-6">
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Request Cache Ratio</h3>
                        <div x-data="{ cached: {{ $cachedReqs }}, uncached: {{ $uncachedReqs }}, total: {{ $totalReqs }} }">
                            {{-- Donut-style bar --}}
                            <div class="relative h-4 w-full overflow-hidden rounded-full bg-gray-100">
                                @if($totalReqs > 0)
                                    <div class="absolute inset-y-0 left-0 rounded-full bg-green-500 transition-all" style="width: {{ $cachePercent }}%"></div>
                                @endif
                            </div>
                            <div class="mt-3 flex items-center justify-between text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-green-500"></span>
                                    <span class="text-gray-600">Cached</span>
                                    <span class="font-medium text-gray-900">{{ number_format($cachedReqs) }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-gray-300"></span>
                                    <span class="text-gray-600">Uncached</span>
                                    <span class="font-medium text-gray-900">{{ number_format($uncachedReqs) }}</span>
                                </div>
                            </div>
                            <p class="mt-2 text-center text-lg font-bold text-green-600">{{ $cachePercent }}% <span class="text-xs font-normal text-gray-500">cache hit rate</span></p>
                        </div>
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Bandwidth Cache Ratio</h3>
                        <div>
                            <div class="relative h-4 w-full overflow-hidden rounded-full bg-gray-100">
                                @if($totalBw > 0)
                                    <div class="absolute inset-y-0 left-0 rounded-full bg-blue-500 transition-all" style="width: {{ $bwCachePercent }}%"></div>
                                @endif
                            </div>
                            <div class="mt-3 flex items-center justify-between text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-blue-500"></span>
                                    <span class="text-gray-600">Cached</span>
                                    <span class="font-medium text-gray-900">{{ $cachedBwFormatted }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full bg-gray-300"></span>
                                    <span class="text-gray-600">Uncached</span>
                                    @php $uncachedBwFormatted = $uncachedBw > 1073741824 ? round($uncachedBw / 1073741824, 2) . ' GB' : round($uncachedBw / 1048576, 2) . ' MB'; @endphp
                                    <span class="font-medium text-gray-900">{{ $uncachedBwFormatted }}</span>
                                </div>
                            </div>
                            <p class="mt-2 text-center text-lg font-bold text-blue-600">{{ $bwCachePercent }}% <span class="text-xs font-normal text-gray-500">bandwidth saved</span></p>
                        </div>
                    </x-ui.card>
                </div>

                {{-- Country breakdown --}}
                @if(!empty($requests['country'] ?? []))
                    <x-ui.card>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Top 10 Countries by Requests</h3>
                        @php
                            $countries = collect($requests['country'])->sortByDesc(fn ($v) => $v)->take(10);
                            $maxCount = $countries->first() ?: 1;
                            $totalCountryReqs = collect($requests['country'])->sum();
                        @endphp
                        <div class="space-y-3">
                            @foreach($countries as $country => $count)
                                @php $pct = $totalCountryReqs > 0 ? round(($count / $totalCountryReqs) * 100, 1) : 0; @endphp
                                <div class="flex items-center gap-3">
                                    <span class="w-8 text-sm font-medium text-gray-700">{{ $country }}</span>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 h-3 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full rounded-full bg-purple-500 transition-all" style="width: {{ round(($count / $maxCount) * 100) }}%"></div>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="text-sm font-medium text-gray-900 w-20 text-right">{{ number_format($count) }}</span>
                                                <span class="text-xs text-gray-400 w-12 text-right">{{ $pct }}%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                {{-- Content Type & Status Codes (from timeseries) --}}
                @if(!empty($totals['requests']['http_status'] ?? []))
                    <x-ui.card class="mt-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">HTTP Status Codes</h3>
                        @php
                            $httpStatuses = collect($totals['requests']['http_status'])->sortByDesc(fn ($v) => $v)->take(10);
                            $maxStatus = $httpStatuses->first() ?: 1;
                        @endphp
                        <div class="space-y-2">
                            @foreach($httpStatuses as $code => $count)
                                @php
                                    $statusColor = match(true) {
                                        $code >= 200 && $code < 300 => 'bg-green-500',
                                        $code >= 300 && $code < 400 => 'bg-blue-500',
                                        $code >= 400 && $code < 500 => 'bg-yellow-500',
                                        $code >= 500 => 'bg-red-500',
                                        default => 'bg-gray-500',
                                    };
                                @endphp
                                <div class="flex items-center gap-3">
                                    <span class="w-10 text-xs font-mono font-medium text-gray-600">{{ $code }}</span>
                                    <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $statusColor }}" style="width: {{ round(($count / $maxStatus) * 100) }}%"></div>
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
