<div>
    {{-- Flash Messages --}}
    @if(session('fetch-dispatched'))
        <div class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">{{ session('fetch-dispatched') }}</div>
    @endif

    {{-- Header with Stats --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Firewall</h1>
            <p class="mt-1 text-sm text-gray-500">Manage firewall rules and block malicious traffic</p>
        </div>
        <div class="flex items-center gap-4 text-sm text-gray-500">
            <span>{{ $this->stats['total_rules'] }} rules</span>
            <span>{{ $this->stats['blocked_today'] }} blocked today</span>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.card class="!p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-50">
                    <x-icons.shield class="h-5 w-5 text-purple-600" />
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->stats['total_rules'] }}</p>
                    <p class="text-xs text-gray-500">Active Rules</p>
                </div>
            </div>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-50">
                    <x-icons.shield-alert class="h-5 w-5 text-red-600" />
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->stats['total_blocked'] }}</p>
                    <p class="text-xs text-gray-500">Total Blocked</p>
                </div>
            </div>
        </x-ui.card>
        <x-ui.card class="!p-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-yellow-50">
                    <x-icons.alert-triangle class="h-5 w-5 text-yellow-600" />
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $this->stats['blocked_today'] }}</p>
                    <p class="text-xs text-gray-500">Blocked Today</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Tabs --}}
    <div class="mb-6 flex gap-1 rounded-lg bg-gray-100 p-1">
        <button wire:click="$set('tab', 'block')"
                class="flex-1 rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $tab === 'block' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
            Block List
        </button>
        <button wire:click="$set('tab', 'allow')"
                class="flex-1 rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $tab === 'allow' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
            Allow List
        </button>
    </div>

    {{-- Add Rule Form --}}
    <div class="mb-6">
        <x-ui.card>
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Add {{ $tab === 'block' ? 'Block' : 'Allow' }} Rule</h3>
            <form wire:submit="addRule" class="flex flex-col gap-3 sm:flex-row">
                <div class="flex-1">
                    <input type="text"
                           wire:model="newIp"
                           placeholder="IP address or CIDR range (e.g. 192.168.1.0/24)"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder:text-gray-400 focus:border-purple-500 focus:ring-purple-500">
                    @error('newIp') <span class="text-xs text-red-600 mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="sm:w-48">
                    <input type="text"
                           wire:model="newReason"
                           placeholder="Reason (optional)"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder:text-gray-400 focus:border-purple-500 focus:ring-purple-500">
                </div>
                <div class="sm:w-44">
                    <input type="datetime-local"
                           wire:model="newExpiry"
                           placeholder="Expiry (optional)"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder:text-gray-400 focus:border-purple-500 focus:ring-purple-500">
                    @error('newExpiry') <span class="text-xs text-red-600 mt-1">{{ $message }}</span> @enderror
                </div>
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
                    <svg class="h-3.5 w-3.5 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="fetchBlocked" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
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
