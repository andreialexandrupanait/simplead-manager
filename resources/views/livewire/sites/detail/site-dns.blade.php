<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">DNS Records</h1>
            <p class="mt-1 text-sm text-gray-500">
                View and manage DNS records for {{ $site->domain }}
                @if($this->dnsCache?->checked_at)
                    &middot; Last checked {{ $this->dnsCache->checked_at->diffForHumans() }}
                @endif
            </p>
        </div>
        <x-ui.button wire:click="refresh" wire:loading.attr="disabled">
            <x-icons.refresh-cw class="mr-1.5 h-4 w-4" wire:loading.class="animate-spin" wire:target="refresh" />
            Refresh
        </x-ui.button>
    </div>

    @if($this->dnsCache)
        {{-- Stats Cards --}}
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Total Records --}}
            <x-ui.stat-card label="Total Records" :value="$this->stats['total_records']" icon="layers" color="purple" />

            {{-- CDN / DNS Provider --}}
            <x-ui.card class="!p-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg {{ $this->stats['uses_cloudflare'] ? 'bg-orange-50' : 'bg-gray-50' }}">
                        <x-icons.cloud class="h-5 w-5 {{ $this->stats['uses_cloudflare'] ? 'text-orange-600' : 'text-gray-400' }}" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $this->stats['uses_cloudflare'] ? 'Cloudflare' : 'Other' }}</p>
                        <p class="text-xs text-gray-500">DNS Provider</p>
                    </div>
                </div>
            </x-ui.card>

            {{-- Mail Provider --}}
            <x-ui.card class="!p-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50">
                        <x-icons.mail class="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $this->stats['mail_provider'] ?? 'None detected' }}</p>
                        <p class="text-xs text-gray-500">Mail Provider</p>
                    </div>
                </div>
            </x-ui.card>

            {{-- Email Security Score --}}
            <x-ui.card class="!p-4">
                <div class="flex items-center gap-3">
                    @php
                        $emailScore = $this->emailHealth?->score ?? $this->stats['email_security_score'];
                        $emailScoreColor = $emailScore >= 80 ? 'green' : ($emailScore >= 34 ? 'yellow' : 'red');
                    @endphp
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg
                        {{ $emailScoreColor === 'green' ? 'bg-green-50' : ($emailScoreColor === 'yellow' ? 'bg-yellow-50' : 'bg-red-50') }}">
                        <x-icons.shield class="h-5 w-5
                            {{ $emailScoreColor === 'green' ? 'text-green-600' : ($emailScoreColor === 'yellow' ? 'text-yellow-600' : 'text-red-600') }}" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900">{{ $emailScore }}<span class="text-sm font-normal text-gray-400">/100</span></p>
                        <p class="text-xs text-gray-500">Email Security</p>
                    </div>
                </div>
            </x-ui.card>
        </div>

        {{-- Email Deliverability Section --}}
        <x-ui.card class="mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Email Deliverability</h3>
                <div class="flex items-center gap-3">
                    @if($this->emailHealth?->checked_at)
                        <span class="text-xs text-gray-500">Last checked {{ $this->emailHealth->checked_at->diffForHumans() }}</span>
                    @endif
                    <x-ui.button variant="secondary" size="sm" wire:click="checkEmailHealth" wire:loading.attr="disabled" wire:target="checkEmailHealth">
                        <x-icons.refresh-cw class="mr-1 h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="checkEmailHealth" />
                        Check Deliverability
                    </x-ui.button>
                </div>
            </div>

            {{-- SPF / DMARC / DKIM enriched indicators --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 mb-4">
                {{-- SPF --}}
                @php
                    $spfOk = $this->emailHealth ? $this->emailHealth->spf_exists : $this->dnsCache->has_spf;
                    $spfStatus = $this->emailHealth?->spf_status;
                @endphp
                <div class="rounded-lg border p-3 {{ $spfOk ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }}">
                    <div class="flex items-center gap-2 mb-1">
                        @if($spfOk)
                            <x-icons.check-circle class="h-5 w-5 text-green-600 shrink-0" />
                        @else
                            <x-icons.x-circle class="h-5 w-5 text-red-600 shrink-0" />
                        @endif
                        <p class="text-sm font-medium {{ $spfOk ? 'text-green-800' : 'text-red-800' }}">SPF Record</p>
                        @if($spfStatus)
                            <x-ui.badge :variant="$spfStatus === 'valid' ? 'green' : ($spfStatus === 'invalid' ? 'red' : 'gray')" class="ml-auto">
                                {{ ucfirst($spfStatus) }}
                            </x-ui.badge>
                        @endif
                    </div>
                    @if($this->emailHealth?->spf_record)
                        <p class="mt-1 text-xs font-mono text-gray-600 break-all">{{ $this->emailHealth->spf_record }}</p>
                    @else
                        <p class="text-xs {{ $spfOk ? 'text-green-600' : 'text-red-600' }}">
                            {{ $spfOk ? 'Configured' : 'Not found' }}
                        </p>
                    @endif
                </div>

                {{-- DMARC --}}
                @php
                    $dmarcOk = $this->emailHealth ? $this->emailHealth->dmarc_exists : $this->dnsCache->has_dmarc;
                    $dmarcPolicy = $this->emailHealth?->dmarc_policy;
                @endphp
                <div class="rounded-lg border p-3 {{ $dmarcOk ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }}">
                    <div class="flex items-center gap-2 mb-1">
                        @if($dmarcOk)
                            <x-icons.check-circle class="h-5 w-5 text-green-600 shrink-0" />
                        @else
                            <x-icons.x-circle class="h-5 w-5 text-red-600 shrink-0" />
                        @endif
                        <p class="text-sm font-medium {{ $dmarcOk ? 'text-green-800' : 'text-red-800' }}">DMARC Record</p>
                        @if($dmarcPolicy)
                            <x-ui.badge :variant="$dmarcPolicy === 'reject' ? 'green' : ($dmarcPolicy === 'quarantine' ? 'yellow' : 'gray')" class="ml-auto">
                                p={{ $dmarcPolicy }}
                            </x-ui.badge>
                        @endif
                    </div>
                    @if($this->emailHealth?->dmarc_record)
                        <p class="mt-1 text-xs font-mono text-gray-600 break-all">{{ $this->emailHealth->dmarc_record }}</p>
                    @else
                        <p class="text-xs {{ $dmarcOk ? 'text-green-600' : 'text-red-600' }}">
                            {{ $dmarcOk ? 'Configured' : 'Not found' }}
                        </p>
                    @endif
                </div>

                {{-- DKIM --}}
                @php
                    $dkimOk = $this->emailHealth ? $this->emailHealth->dkim_exists : $this->dnsCache->has_dkim;
                    $dkimSelector = $this->emailHealth?->dkim_selector;
                @endphp
                <div class="rounded-lg border p-3 {{ $dkimOk ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }}">
                    <div class="flex items-center gap-2 mb-1">
                        @if($dkimOk)
                            <x-icons.check-circle class="h-5 w-5 text-green-600 shrink-0" />
                        @else
                            <x-icons.x-circle class="h-5 w-5 text-red-600 shrink-0" />
                        @endif
                        <p class="text-sm font-medium {{ $dkimOk ? 'text-green-800' : 'text-red-800' }}">DKIM Record</p>
                        @if($dkimOk && $dkimSelector)
                            <x-ui.badge variant="green" class="ml-auto">
                                {{ $dkimSelector }}
                            </x-ui.badge>
                        @endif
                    </div>
                    <p class="text-xs {{ $dkimOk ? 'text-green-600' : 'text-red-600' }}">
                        @if($dkimOk)
                            Found via selector: {{ $dkimSelector ?? 'unknown' }}
                        @else
                            Not found
                        @endif
                    </p>
                </div>
            </div>

            {{-- Blacklist status --}}
            @if($this->emailHealth)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-4">
                    {{-- Blacklist card --}}
                    <div class="rounded-lg border p-4 {{ $this->emailHealth->blacklists_listed > 0 ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50' }}">
                        <h4 class="text-sm font-semibold {{ $this->emailHealth->blacklists_listed > 0 ? 'text-red-800' : 'text-green-800' }} mb-2">
                            Blacklist Status
                        </h4>
                        @if($this->emailHealth->blacklists_listed > 0)
                            <p class="text-sm text-red-700 mb-2">Listed on {{ $this->emailHealth->blacklists_listed }} blacklist(s)</p>
                        @else
                            <p class="text-sm text-green-700 mb-2">Clean on {{ $this->emailHealth->blacklists_clean }}/{{ $this->emailHealth->blacklists_clean + $this->emailHealth->blacklists_listed }} lists</p>
                        @endif
                        @if($this->emailHealth->blacklists_checked)
                            <div class="space-y-1">
                                @foreach($this->emailHealth->blacklists_checked as $bl)
                                    <div class="flex items-center gap-2 text-xs">
                                        @if($bl['listed'])
                                            <x-icons.x-circle class="h-3.5 w-3.5 text-red-500 shrink-0" />
                                            <span class="text-red-700">{{ $bl['name'] }}</span>
                                        @else
                                            <x-icons.check-circle class="h-3.5 w-3.5 text-green-500 shrink-0" />
                                            <span class="text-green-700">{{ $bl['name'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- MX Records mini-table --}}
                    <div class="rounded-lg border p-4">
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">MX Records</h4>
                        @if($this->emailHealth->mx_records && count($this->emailHealth->mx_records) > 0)
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-100">
                                        <th class="pb-1 text-left text-xs font-medium text-gray-500">Priority</th>
                                        <th class="pb-1 text-left text-xs font-medium text-gray-500">Host</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->emailHealth->mx_records as $mx)
                                        <tr>
                                            <td class="py-1 text-gray-500">{{ $mx['priority'] }}</td>
                                            <td class="py-1 font-mono text-xs text-gray-900">{{ $mx['host'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="text-xs text-gray-500">No MX records found.</p>
                        @endif
                    </div>
                </div>

                {{-- Recommendations --}}
                @if(count($this->emailRecommendations) > 0)
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <h4 class="text-sm font-semibold text-blue-800 mb-2">Recommendations</h4>
                        <ul class="space-y-1.5">
                            @foreach($this->emailRecommendations as $rec)
                                <li class="flex items-start gap-2 text-sm text-blue-700">
                                    <svg class="h-4 w-4 shrink-0 mt-0.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ $rec }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </x-ui.card>

        {{-- WWW Detection --}}
        @if($this->dnsCache->has_www)
            <div class="mb-6 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5">
                <x-icons.check-circle class="h-4 w-4 text-blue-600 shrink-0" />
                <p class="text-sm text-blue-700">www subdomain is configured for this domain.</p>
            </div>
        @endif

        {{-- DNS Records Tables --}}
        <div class="space-y-6">
            @foreach($this->recordGroups as $type => $records)
                <x-ui.card>
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ $type }} Records</h3>
                        <x-ui.badge variant="gray">{{ count($records) }}</x-ui.badge>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100">
                                    @if($type === 'A')
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">IP Address</th>
                                        <th class="pb-2 text-left text-xs font-medium text-gray-500">TTL</th>
                                    @elseif($type === 'AAAA')
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">IPv6 Address</th>
                                        <th class="pb-2 text-left text-xs font-medium text-gray-500">TTL</th>
                                    @elseif($type === 'CNAME')
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">Target</th>
                                        <th class="pb-2 text-left text-xs font-medium text-gray-500">TTL</th>
                                    @elseif($type === 'MX')
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">Priority</th>
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">Host</th>
                                        <th class="pb-2 text-left text-xs font-medium text-gray-500">TTL</th>
                                    @elseif($type === 'TXT')
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">Value</th>
                                        <th class="pb-2 text-left text-xs font-medium text-gray-500">TTL</th>
                                    @elseif($type === 'NS')
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">Nameserver</th>
                                        <th class="pb-2 text-left text-xs font-medium text-gray-500">TTL</th>
                                    @elseif($type === 'SOA')
                                        <th class="pb-2 pr-4 text-left text-xs font-medium text-gray-500">Field</th>
                                        <th class="pb-2 text-left text-xs font-medium text-gray-500">Value</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @if($type === 'A')
                                    @foreach($records as $record)
                                        <tr>
                                            <td class="py-2 pr-4 font-mono text-gray-900">{{ $record['ip'] }}</td>
                                            <td class="py-2 text-gray-500">{{ $record['ttl'] }}s</td>
                                        </tr>
                                    @endforeach
                                @elseif($type === 'AAAA')
                                    @foreach($records as $record)
                                        <tr>
                                            <td class="py-2 pr-4 font-mono text-gray-900">{{ $record['ipv6'] }}</td>
                                            <td class="py-2 text-gray-500">{{ $record['ttl'] }}s</td>
                                        </tr>
                                    @endforeach
                                @elseif($type === 'CNAME')
                                    @foreach($records as $record)
                                        <tr>
                                            <td class="py-2 pr-4 font-mono text-gray-900">{{ $record['target'] }}</td>
                                            <td class="py-2 text-gray-500">{{ $record['ttl'] }}s</td>
                                        </tr>
                                    @endforeach
                                @elseif($type === 'MX')
                                    @foreach($records as $record)
                                        <tr>
                                            <td class="py-2 pr-4 text-gray-500">{{ $record['priority'] }}</td>
                                            <td class="py-2 pr-4 font-mono text-gray-900">{{ $record['host'] }}</td>
                                            <td class="py-2 text-gray-500">{{ $record['ttl'] }}s</td>
                                        </tr>
                                    @endforeach
                                @elseif($type === 'TXT')
                                    @foreach($records as $record)
                                        <tr>
                                            <td class="py-2 pr-4">
                                                <div class="max-w-xl break-all font-mono text-xs
                                                    @if(str_starts_with(strtolower($record['value']), 'v=spf1')) text-blue-700 bg-blue-50 rounded px-1.5 py-0.5
                                                    @elseif(str_starts_with(strtolower($record['value']), 'v=dmarc1')) text-green-700 bg-green-50 rounded px-1.5 py-0.5
                                                    @else text-gray-900
                                                    @endif
                                                ">{{ $record['value'] }}</div>
                                            </td>
                                            <td class="py-2 text-gray-500">{{ $record['ttl'] }}s</td>
                                        </tr>
                                    @endforeach
                                @elseif($type === 'NS')
                                    @foreach($records as $record)
                                        <tr>
                                            <td class="py-2 pr-4 font-mono text-gray-900">{{ $record['target'] }}</td>
                                            <td class="py-2 text-gray-500">{{ $record['ttl'] }}s</td>
                                        </tr>
                                    @endforeach
                                @elseif($type === 'SOA')
                                    @foreach($records as $record)
                                        <tr><td class="py-2 pr-4 text-gray-500">Primary NS</td><td class="py-2 font-mono text-gray-900">{{ $record['mname'] }}</td></tr>
                                        <tr><td class="py-2 pr-4 text-gray-500">Admin Email</td><td class="py-2 font-mono text-gray-900">{{ $record['rname'] }}</td></tr>
                                        <tr><td class="py-2 pr-4 text-gray-500">Serial</td><td class="py-2 font-mono text-gray-900">{{ $record['serial'] }}</td></tr>
                                        <tr><td class="py-2 pr-4 text-gray-500">Refresh</td><td class="py-2 text-gray-900">{{ $record['refresh'] }}s</td></tr>
                                        <tr><td class="py-2 pr-4 text-gray-500">Retry</td><td class="py-2 text-gray-900">{{ $record['retry'] }}s</td></tr>
                                        <tr><td class="py-2 pr-4 text-gray-500">Expire</td><td class="py-2 text-gray-900">{{ $record['expire'] }}s</td></tr>
                                        <tr><td class="py-2 pr-4 text-gray-500">Minimum TTL</td><td class="py-2 text-gray-900">{{ $record['minimum_ttl'] }}s</td></tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @else
        {{-- Empty State --}}
        <x-ui.card>
            <div class="py-12 text-center">
                <x-icons.globe class="mx-auto h-12 w-12 text-gray-300" />
                <h3 class="mt-3 text-sm font-semibold text-gray-900">No DNS data</h3>
                <p class="mt-1 text-sm text-gray-500">Click Refresh to fetch DNS records for this domain.</p>
            </div>
        </x-ui.card>
    @endif
</div>
