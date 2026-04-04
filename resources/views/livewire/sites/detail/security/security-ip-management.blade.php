<div>
    <x-ui.page-header title="{{ __('IP Management') }}" subtitle="{{ __('Manage whitelists, blocklists, and banned IPs') }}">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" wire:click="verifySettings" wire:loading.attr="disabled" wire:target="verifySettings">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="verifySettings" />
                {{ __('Verify') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="ip-success" />
    <x-ui.flash-alert type="error" key="verify-error" />

    {{-- Sub-tabs --}}
    <div class="mb-6 flex gap-2">
        @foreach(['whitelist' => __('Whitelist'), 'blocklist' => __('Blocklist'), 'banned' => __('Banned IPs'), 'settings' => __('Settings')] as $key => $label)
            <button wire:click="$set('subTab', '{{ $key }}')"
                class="rounded-full px-3 py-1 text-xs font-medium {{ $subTab === $key ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
                @if($key === 'whitelist')
                    ({{ $this->whitelist->count() }})
                @elseif($key === 'blocklist')
                    ({{ $this->blocklist->count() }})
                @elseif($key === 'banned')
                    ({{ $this->bannedIps->count() }})
                @endif
            </button>
        @endforeach
    </div>

    @if($subTab === 'whitelist' || $subTab === 'blocklist')
        {{-- Add IP Form --}}
        <x-ui.card class="mb-6">
            <h4 class="text-sm font-semibold text-gray-900 mb-3">{{ __('Add to') }} {{ ucfirst($subTab) }}</h4>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-48">
                    <x-ui.form-group label="IP / CIDR" for="newIp" error="{{ $errors->first('newIp') }}">
                        <x-ui.input type="text" id="newIp" wire:model="newIp" placeholder="192.168.1.0/24" class="text-sm" />
                    </x-ui.form-group>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <x-ui.form-group label="{{ __('Reason') }}" for="newReason">
                        <x-ui.input type="text" id="newReason" wire:model="newReason" placeholder="{{ __('Optional reason') }}" class="text-sm" />
                    </x-ui.form-group>
                </div>
                <div class="w-44">
                    <x-ui.form-group label="{{ __('Expires') }}" for="newExpiresAt">
                        <x-ui.input type="datetime-local" id="newExpiresAt" wire:model="newExpiresAt" class="text-sm" />
                    </x-ui.form-group>
                </div>
                <x-ui.button wire:click="addIp" wire:loading.attr="disabled" size="sm">{{ __('Add') }}</x-ui.button>
            </div>
        </x-ui.card>

        {{-- IP List --}}
        <x-ui.card>
            @php
                $items = $subTab === 'whitelist' ? $this->whitelist : $this->blocklist;
            @endphp

            @if($items->isEmpty())
                <x-ui.empty-state
                    title="{{ __('No :list entries', ['list' => $subTab]) }}"
                    description="{{ __('Add IP addresses to the :list above.', ['list' => $subTab]) }}"
                    icon="globe"
                />
            @else
                {{-- Mobile cards --}}
                <div class="md:hidden space-y-2">
                    @foreach($items as $item)
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-sm text-gray-900">{{ $item->ip_address }}</span>
                                <span class="text-xs {{ $item->site_id ? 'text-gray-500' : 'text-purple-600 font-medium' }}">
                                    {{ $item->site_id ? __('Site') : __('Global') }}
                                </span>
                            </div>
                            @if($item->reason)
                                <p class="mt-1 text-sm text-gray-500">{{ $item->reason }}</p>
                            @endif
                            <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                                <span>{{ __('Expires') }}: <span class="text-gray-700">{{ $item->expires_at?->format('M d, Y') ?? __('Never') }}</span></span>
                                <span>{{ __('Added') }}: <span class="text-gray-700">{{ $item->created_at->diffForHumans() }}</span></span>
                            </div>
                            <div class="mt-2">
                                <button wire:click="removeIp({{ $item->id }})" wire:confirm="{{ __('Remove this IP?') }}" class="text-xs text-red-500 hover:text-red-700">
                                    {{ __('Remove') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop table --}}
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                                <th class="pb-2 pr-4">{{ __('IP Address') }}</th>
                                <th class="pb-2 pr-4">{{ __('Reason') }}</th>
                                <th class="pb-2 pr-4">{{ __('Scope') }}</th>
                                <th class="pb-2 pr-4">{{ __('Expires') }}</th>
                                <th class="pb-2 pr-4">{{ __('Added') }}</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($items as $item)
                                <tr>
                                    <td class="py-2 pr-4 font-mono text-sm">{{ $item->ip_address }}</td>
                                    <td class="py-2 pr-4 text-gray-500 text-sm">{{ $item->reason ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="text-xs {{ $item->site_id ? 'text-gray-500' : 'text-purple-600 font-medium' }}">
                                            {{ $item->site_id ? __('Site') : __('Global') }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 text-xs text-gray-500">
                                        {{ $item->expires_at?->format('M d, Y') ?? __('Never') }}
                                    </td>
                                    <td class="py-2 pr-4 text-xs text-gray-500">
                                        {{ $item->created_at->diffForHumans() }}
                                    </td>
                                    <td class="py-2">
                                        <button wire:click="removeIp({{ $item->id }})" wire:confirm="{{ __('Remove this IP?') }}" class="text-red-500 hover:text-red-700">
                                            <x-icons.x class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

    @elseif($subTab === 'banned')
        <x-ui.card>
            @if($this->bannedIps->isEmpty())
                <x-ui.empty-state
                    title="{{ __('No banned IPs') }}"
                    description="{{ __('IPs automatically banned by brute force protection will appear here.') }}"
                    icon="shield-alert"
                />
            @else
                {{-- Mobile cards --}}
                <div class="md:hidden space-y-2">
                    @foreach($this->bannedIps as $ban)
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-sm text-gray-900">{{ $ban->ip_address }}</span>
                                <span class="text-xs font-medium text-gray-700">{{ $ban->blocked_attempts }} {{ __('attempts') }}</span>
                            </div>
                            @if($ban->reason)
                                <p class="mt-1 text-sm text-gray-500">{{ $ban->reason }}</p>
                            @endif
                            <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                                <span>{{ __('Banned') }}: <span class="text-gray-700">{{ $ban->banned_at->diffForHumans() }}</span></span>
                                <span>{{ __('Expires') }}: <span class="text-gray-700">{{ $ban->expires_at?->format('M d, Y H:i') ?? __('Never') }}</span></span>
                            </div>
                            <div class="mt-2">
                                <button wire:click="unbanIp({{ $ban->id }})" wire:confirm="{{ __('Unban this IP?') }}" class="text-xs text-purple-600 hover:text-purple-800">
                                    {{ __('Unban') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop table --}}
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                                <th class="pb-2 pr-4">{{ __('IP Address') }}</th>
                                <th class="pb-2 pr-4">{{ __('Reason') }}</th>
                                <th class="pb-2 pr-4">{{ __('Attempts') }}</th>
                                <th class="pb-2 pr-4">{{ __('Banned At') }}</th>
                                <th class="pb-2 pr-4">{{ __('Expires') }}</th>
                                <th class="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($this->bannedIps as $ban)
                                <tr>
                                    <td class="py-2 pr-4 font-mono text-sm">{{ $ban->ip_address }}</td>
                                    <td class="py-2 pr-4 text-gray-500 text-sm">{{ $ban->reason ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-sm">{{ $ban->blocked_attempts }}</td>
                                    <td class="py-2 pr-4 text-xs text-gray-500">{{ $ban->banned_at->diffForHumans() }}</td>
                                    <td class="py-2 pr-4 text-xs text-gray-500">
                                        {{ $ban->expires_at?->format('M d, Y H:i') ?? __('Never') }}
                                    </td>
                                    <td class="py-2">
                                        <button wire:click="unbanIp({{ $ban->id }})" wire:confirm="{{ __('Unban this IP?') }}" class="text-xs text-purple-600 hover:text-purple-800">
                                            {{ __('Unban') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

    @elseif($subTab === 'settings')
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Firewall Settings') }}</h3>
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ __('Enable Firewall') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Activate IP-based blocking and filtering on the WordPress site.') }}</p>
                    </div>
                    <x-ui.toggle :enabled="$firewallEnabled" wire:click="$toggle('firewallEnabled')" />
                </div>

                <x-ui.form-group label="{{ __('IP Header Override') }}" for="ipHeaderOverride">
                    <x-ui.input type="text" id="ipHeaderOverride" wire:model="ipHeaderOverride"
                        placeholder="{{ __('e.g. X-Forwarded-For, CF-Connecting-IP') }}" class="text-sm max-w-md" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Use when behind a reverse proxy or CDN. Leave empty for auto-detection.') }}</p>
                </x-ui.form-group>

                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ __('Role-Based Whitelist') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Automatically whitelist admin-role user IPs from blocking.') }}</p>
                    </div>
                    <x-ui.toggle :enabled="$roleWhitelist" wire:click="$toggle('roleWhitelist')" />
                </div>

                <div class="flex justify-end">
                    <x-ui.button wire:click="saveFirewallSettings" wire:loading.attr="disabled">
                        {{ __('Save Settings') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif
</div>
