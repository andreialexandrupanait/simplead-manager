<div>
    @include('livewire.settings.partials.settings-tabs')

    <div class="space-y-6">
        {{-- Notification Channels --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Notification Channels</h3>
                <x-ui.button wire:click="$dispatch('open-channel-form')">
                    <x-icons.plus class="mr-1.5 h-4 w-4" />
                    Add Channel
                </x-ui.button>
            </div>

            @if($this->channels->isEmpty())
                <p class="py-6 text-center text-sm text-gray-400">No notification channels configured.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->channels as $channel)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                {{-- Type icon --}}
                                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100">
                                    @php
                                        $typeIcon = match($channel->type) {
                                            'email' => "\u{2709}",
                                            'slack' => "\u{1F4AC}",
                                            'discord' => "\u{1F3AE}",
                                            'telegram' => "\u{2708}",
                                            'webhook' => "\u{1F517}",
                                            default => "\u{1F4E2}",
                                        };
                                    @endphp
                                    <span class="text-sm">{{ $typeIcon }}</span>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $channel->name }}</p>
                                    <div class="flex items-center gap-2 text-xs text-gray-500">
                                        <span>{{ ucfirst($channel->type) }}</span>
                                        @if($channel->is_default)
                                            <x-ui.badge variant="purple">Default</x-ui.badge>
                                        @endif
                                        @if(!$channel->is_active)
                                            <x-ui.badge variant="gray">Disabled</x-ui.badge>
                                        @endif
                                    </div>
                                    @if($channel->last_error)
                                        <p class="mt-0.5 text-xs text-red-500" title="{{ $channel->last_error_at?->diffForHumans() }}">
                                            Error: {{ \Illuminate\Support\Str::limit($channel->last_error, 60) }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="testChannel({{ $channel->id }})" wire:loading.attr="disabled" wire:target="testChannel({{ $channel->id }})" class="rounded-lg px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="testChannel({{ $channel->id }})">Test</span>
                                    <span wire:loading wire:target="testChannel({{ $channel->id }})">Testing...</span>
                                </button>
                                <button wire:click="toggleChannel({{ $channel->id }})" wire:loading.attr="disabled" wire:target="toggleChannel({{ $channel->id }})" class="rounded-lg px-2 py-1 text-xs {{ $channel->is_active ? 'text-yellow-600 hover:bg-yellow-50' : 'text-green-600 hover:bg-green-50' }} disabled:opacity-50">
                                    {{ $channel->is_active ? 'Disable' : 'Enable' }}
                                </button>
                                <button wire:click="$dispatch('open-channel-form', { channelId: {{ $channel->id }} })" class="rounded-lg px-2 py-1 text-xs text-gray-500 hover:bg-gray-100">
                                    Edit
                                </button>
                                <button wire:click="deleteChannel({{ $channel->id }})"
                                        wire:confirm="Delete this notification channel?"
                                        wire:loading.attr="disabled"
                                        class="rounded-lg px-2 py-1 text-xs text-red-500 hover:bg-red-50 disabled:opacity-50">
                                    Delete
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        {{-- Notification Preferences --}}
        <form wire:submit="savePreferences">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">Notification Preferences</h3>
                <div class="space-y-4">
                    <label class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-700">Site Down</p>
                            <p class="text-xs text-gray-400">Notify when a site goes down</p>
                        </div>
                        <input type="checkbox" wire:model="notifyDown" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    </label>

                    <label class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-700">Site Recovery</p>
                            <p class="text-xs text-gray-400">Notify when a site recovers</p>
                        </div>
                        <input type="checkbox" wire:model="notifyRecovery" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    </label>

                    <label class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-700">SSL Expiring</p>
                            <p class="text-xs text-gray-400">Notify when SSL certificate is expiring soon</p>
                        </div>
                        <input type="checkbox" wire:model="notifySslExpiring" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    </label>

                    <label class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-700">Degraded Performance</p>
                            <p class="text-xs text-gray-400">Notify when a site shows degraded performance</p>
                        </div>
                        <input type="checkbox" wire:model="notifyDegraded" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    </label>
                </div>
            </x-ui.card>

            {{-- Quiet Hours --}}
            <x-ui.card class="mt-6">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Quiet Hours</h3>
                <p class="text-sm text-gray-500 mb-4">Suppress notifications during specified hours.</p>

                <div class="mb-4">
                    <x-ui.checkbox wire:model.live="quietHoursEnabled" label="Enable quiet hours" />
                </div>

                @if($quietHoursEnabled)
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Start Time</label>
                            <x-ui.input wire:model="quietHoursStart" type="time" class="mt-1" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">End Time</label>
                            <x-ui.input wire:model="quietHoursEnd" type="time" class="mt-1" />
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <div class="mt-6 flex justify-end">
                <x-ui.button type="submit">Save Preferences</x-ui.button>
            </div>
        </form>
    </div>

    {{-- Channel Form Modal --}}
    <livewire:settings.components.channel-form />
</div>
