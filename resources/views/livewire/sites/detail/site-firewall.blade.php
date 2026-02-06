<div>
    {{-- Flash Messages --}}
    <x-ui.flash-alert type="info" key="fetch-dispatched" />

    {{-- Header with Stats --}}
    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="Firewall" subtitle="Manage firewall rules and block malicious traffic" />
        <div class="flex items-center gap-4 text-sm text-gray-500">
            <span>{{ $this->stats['total_rules'] }} rules</span>
            <span>{{ $this->stats['blocked_today'] }} blocked today</span>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Active Rules" :value="$this->stats['total_rules']" icon="shield" color="purple" />
        <x-ui.stat-card label="Total Blocked" :value="$this->stats['total_blocked']" icon="shield-alert" color="red" />
        <x-ui.stat-card label="Blocked Today" :value="$this->stats['blocked_today']" icon="alert-triangle" color="yellow" />
    </div>

    {{-- Tabs --}}
    <x-ui.filter-tabs
        :options="['block' => 'Block List', 'allow' => 'Allow List']"
        :selected="$tab"
        wire="tab"
        class="mb-6"
    />

    {{-- Add Rule Form --}}
    <div class="mb-6">
        <x-ui.card>
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Add {{ $tab === 'block' ? 'Block' : 'Allow' }} Rule</h3>
            <form wire:submit="addRule" class="flex flex-col gap-3 sm:flex-row">
                <x-ui.form-group error="newIp" class="flex-1">
                    <x-ui.input wire:model="newIp" placeholder="IP address or CIDR range (e.g. 192.168.1.0/24)" />
                </x-ui.form-group>
                <div class="sm:w-48">
                    <x-ui.input wire:model="newReason" placeholder="Reason (optional)" />
                </div>
                <x-ui.form-group error="newExpiry" class="sm:w-44">
                    <x-ui.input type="datetime-local" wire:model="newExpiry" />
                </x-ui.form-group>
                <x-ui.button variant="primary" size="sm" type="submit" wire:loading.attr="disabled">
                    Add Rule
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>

    {{-- Rules Table --}}
    <x-ui.card>
        @php
            $rules = $tab === 'block' ? $this->blockRules : $this->allowRules;
        @endphp

        @if($rules->count() > 0)
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>IP / Range</x-ui.th>
                    <x-ui.th>Reason</x-ui.th>
                    <x-ui.th>Hits</x-ui.th>
                    <x-ui.th>Last Hit</x-ui.th>
                    <x-ui.th>Expires</x-ui.th>
                    <x-ui.th>Created</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach($rules as $rule)
                    <tr>
                        <x-ui.td>
                            <span class="font-mono text-sm">{{ $rule->ip_address }}</span>
                            @if($rule->site_id === null)
                                <x-ui.badge variant="purple" class="ml-2">Global</x-ui.badge>
                            @endif
                        </x-ui.td>
                        <x-ui.td>{{ $rule->reason ?? '—' }}</x-ui.td>
                        <x-ui.td>{{ number_format($rule->hits_count) }}</x-ui.td>
                        <x-ui.td>{{ $rule->last_hit_at?->diffForHumans() ?? 'Never' }}</x-ui.td>
                        <x-ui.td>{{ $rule->expires_at?->format('M d, Y H:i') ?? 'Never' }}</x-ui.td>
                        <x-ui.td>
                            <div>{{ $rule->created_at->format('M d, Y') }}</div>
                            @if($rule->createdBy)
                                <div class="text-xs text-gray-400">{{ $rule->createdBy->name }}</div>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            <x-ui.button variant="secondary" size="sm" wire:click="removeRule({{ $rule->id }})" wire:loading.attr="disabled"
                                         wire:confirm="Are you sure you want to remove this rule?">
                                Remove
                            </x-ui.button>
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @else
            <x-ui.empty-state
                title="No {{ $tab }} rules"
                description="Add an IP address above to create a {{ $tab }} rule."
                icon="shield"
            />
        @endif
    </x-ui.card>

    {{-- Blocked Requests Section --}}
    <div class="mt-6">
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Recent Blocked Requests</h3>
                <x-ui.button variant="secondary" size="sm" wire:click="fetchBlocked" wire:loading.attr="disabled">
                    <x-ui.spinner size="xs" class="hidden" wire:loading.class.remove="hidden" wire:target="fetchBlocked" />
                    Fetch Latest
                </x-ui.button>
            </div>

            @if($this->blockedRequests->count() > 0)
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-slot:head>
                            <x-ui.th>Timestamp</x-ui.th>
                            <x-ui.th>IP Address</x-ui.th>
                            <x-ui.th>URL</x-ui.th>
                            <x-ui.th>User Agent</x-ui.th>
                            <x-ui.th>Matched Rule</x-ui.th>
                        </x-slot:head>
                        @foreach($this->blockedRequests as $req)
                            <tr>
                                <x-ui.td class="whitespace-nowrap">
                                    {{ $req->blocked_at?->format('M d, H:i:s') ?? '—' }}
                                </x-ui.td>
                                <x-ui.td class="font-mono text-xs">{{ $req->ip_address }}</x-ui.td>
                                <x-ui.td class="max-w-xs truncate">{{ $req->request_url ?? '—' }}</x-ui.td>
                                <x-ui.td class="max-w-xs truncate text-xs text-gray-400">{{ Str::limit($req->user_agent, 60) ?? '—' }}</x-ui.td>
                                <x-ui.td>
                                    @if($req->ipRule)
                                        <span class="font-mono text-xs">{{ $req->ipRule->ip_address }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </div>
            @else
                <x-ui.empty-state
                    title="No blocked requests"
                    description="No blocked requests recorded yet."
                    icon="shield"
                />
            @endif
        </x-ui.card>
    </div>
</div>
