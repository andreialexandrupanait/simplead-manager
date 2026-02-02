<x-ui.modal name="configure-monitor" maxWidth="2xl">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $monitorId ? 'Edit Monitor' : 'Add Monitor' }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">Configure uptime monitoring for a site.</p>

        <div class="mt-6 space-y-5">
            {{-- Site selection --}}
            @if(!$siteId)
                <div>
                    <label class="block text-sm font-medium text-gray-700">Site</label>
                    <x-ui.select wire:model.live="siteId" class="mt-1">
                        <option value="">Select a site...</option>
                        @foreach($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->url }})</option>
                        @endforeach
                    </x-ui.select>
                    @error('siteId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif

            {{-- Monitor type --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Monitor Type</label>
                    <x-ui.select wire:model.live="type" class="mt-1">
                        <option value="http">HTTP(S)</option>
                        <option value="keyword">Keyword</option>
                        <option value="ping">Ping</option>
                    </x-ui.select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">HTTP Method</label>
                    <x-ui.select wire:model="http_method" class="mt-1">
                        @foreach(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method)
                            <option value="{{ $method }}">{{ $method }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </div>

            {{-- URL --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">URL</label>
                <x-ui.input wire:model="url" type="url" placeholder="https://example.com" class="mt-1" />
                @error('url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if($urlAutoFilled)
                    <p class="mt-1 text-xs text-gray-400">Auto-filled from site. Edit if you want to monitor a specific path.</p>
                @endif
            </div>

            {{-- Interval & Timeout --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Check Interval</label>
                    <x-ui.select wire:model="interval" class="mt-1">
                        <option value="60">1 minute</option>
                        <option value="120">2 minutes</option>
                        <option value="300">5 minutes</option>
                        <option value="600">10 minutes</option>
                        <option value="900">15 minutes</option>
                        <option value="1800">30 minutes</option>
                        <option value="3600">1 hour</option>
                    </x-ui.select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Timeout (seconds)</label>
                    <x-ui.input wire:model="timeout" type="number" min="5" max="120" class="mt-1" />
                </div>
            </div>

            {{-- Keyword section --}}
            @if($type === 'keyword')
                <div class="rounded-lg border border-gray-200 p-4 space-y-3">
                    <h4 class="text-sm font-medium text-gray-700">Keyword Configuration</h4>
                    <div>
                        <x-ui.input wire:model="keyword" placeholder="Enter keyword to check" />
                    </div>
                    <div class="flex items-center gap-4">
                        <x-ui.select wire:model="keyword_type" class="flex-1">
                            <option value="exists">Should exist</option>
                            <option value="not_exists">Should NOT exist</option>
                        </x-ui.select>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" wire:model="keyword_case_sensitive" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            Case sensitive
                        </label>
                    </div>
                </div>
            @endif

            {{-- Authentication --}}
            <div class="rounded-lg border border-gray-200 p-4 space-y-3">
                <h4 class="text-sm font-medium text-gray-700">Authentication</h4>
                <x-ui.select wire:model.live="auth_type">
                    <option value="none">None</option>
                    <option value="basic">Basic Auth</option>
                    <option value="bearer">Bearer Token</option>
                </x-ui.select>

                @if($auth_type === 'basic')
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.input wire:model="auth_username" placeholder="Username" />
                        <x-ui.input wire:model="auth_password" type="password" placeholder="Password" />
                    </div>
                @elseif($auth_type === 'bearer')
                    <x-ui.input wire:model="auth_token" type="password" placeholder="Bearer token" />
                @endif
            </div>

            {{-- SSL & Alerts --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="check_ssl" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        Monitor SSL certificate
                    </label>
                    @if($check_ssl)
                        <div class="mt-2">
                            <label class="text-xs text-gray-500">Warn before expiry (days)</label>
                            <x-ui.input wire:model="ssl_expiry_threshold" type="number" min="1" max="90" class="mt-1" />
                        </div>
                    @endif
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Alert after failures</label>
                    <x-ui.input wire:model="alert_after_failures" type="number" min="1" max="10" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-400">Number of consecutive failures before alerting</p>
                </div>
            </div>

            {{-- Advanced: Headers --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">Custom HTTP Headers (JSON)</label>
                <textarea
                    wire:model="http_headers_text"
                    rows="3"
                    placeholder='{"X-Custom-Header": "value"}'
                    class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                ></textarea>
            </div>

            {{-- Notification channels --}}
            @if($this->channels->isNotEmpty())
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Alert Channels</label>
                    <div class="space-y-2">
                        @foreach($this->channels as $channel)
                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" wire:model="alert_contacts" value="{{ $channel->id }}" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                {{ $channel->name }} ({{ $channel->type }})
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Leave empty to use default channels</p>
                </div>
            @endif
        </div>

        {{-- Actions --}}
        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-configure-monitor')">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit">
                {{ $monitorId ? 'Update Monitor' : 'Create Monitor' }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
