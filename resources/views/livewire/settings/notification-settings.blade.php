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

    {{-- Message Templates --}}
    <x-ui.card class="mt-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Message Templates</h3>
                <p class="text-sm text-gray-500">Customize notification messages per event type. Use placeholders: <code class="text-xs bg-gray-100 px-1 rounded">{site_name}</code>, <code class="text-xs bg-gray-100 px-1 rounded">{site_url}</code>, <code class="text-xs bg-gray-100 px-1 rounded">{details}</code></p>
            </div>
            <x-ui.button size="sm" wire:click="editTemplate">Add Template</x-ui.button>
        </div>

        @if($this->notificationTemplates->isEmpty())
            <p class="text-sm text-gray-400 italic">No custom templates. Default messages will be used.</p>
        @else
            <div class="divide-y divide-gray-100">
                @foreach($this->notificationTemplates as $tmpl)
                    <div class="flex items-center justify-between py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900">{{ \App\Models\NotificationTemplate::EVENTS[$tmpl->event] ?? $tmpl->event }}</span>
                                @if(!$tmpl->is_active)
                                    <x-ui.badge variant="gray">Disabled</x-ui.badge>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 truncate">{{ $tmpl->title_template }}</p>
                        </div>
                        <div class="flex gap-2 ml-4">
                            <button wire:click="editTemplate({{ $tmpl->id }})" class="text-xs text-purple-600 hover:text-purple-800">Edit</button>
                            <button wire:click="deleteTemplate({{ $tmpl->id }})" wire:confirm="Delete this template?" class="text-xs text-red-600 hover:text-red-800">Delete</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    {{-- Escalation Rules --}}
    <x-ui.card class="mt-6">
        <h3 class="text-base font-semibold text-gray-900 mb-2">Escalation Rules</h3>
        <p class="text-sm text-gray-500 mb-4">If a critical alert isn't acknowledged within the delay, escalate to another channel.</p>

        @if($this->escalationRules->isNotEmpty())
            <div class="divide-y divide-gray-100 mb-4">
                @foreach($this->escalationRules as $rule)
                    <div class="flex items-center justify-between py-2">
                        <span class="text-sm text-gray-700">
                            {{ $rule->sourceChannel->name }}
                            <span class="text-gray-400 mx-1">&rarr;</span>
                            {{ $rule->escalationChannel->name }}
                            <span class="text-xs text-gray-400">({{ $rule->delay_minutes }}min)</span>
                        </span>
                        <button wire:click="deleteEscalationRule({{ $rule->id }})" class="text-xs text-red-600 hover:text-red-800">Remove</button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex items-end gap-3">
            <div class="flex-1">
                <label class="block text-xs text-gray-600 mb-1">From channel</label>
                <x-ui.select wire:model="escalationSourceId" class="text-sm">
                    <option value="">Select...</option>
                    @foreach($this->channels as $ch)
                        <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="flex-1">
                <label class="block text-xs text-gray-600 mb-1">Escalate to</label>
                <x-ui.select wire:model="escalationTargetId" class="text-sm">
                    <option value="">Select...</option>
                    @foreach($this->channels as $ch)
                        <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-24">
                <label class="block text-xs text-gray-600 mb-1">Delay (min)</label>
                <x-ui.input type="number" wire:model="escalationDelay" min="5" max="120" class="text-sm" />
            </div>
            <x-ui.button wire:click="addEscalationRule" size="sm">Add</x-ui.button>
        </div>
        @error('escalationSourceId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('escalationTargetId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </x-ui.card>

    {{-- Channel Form Modal --}}
    <livewire:settings.components.channel-form />

    {{-- Template Form Modal --}}
    <x-ui.modal name="notification-template" maxWidth="lg">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ $editingTemplateId ? 'Edit Template' : 'New Template' }}</h3>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Event</label>
                <x-ui.select wire:model="templateEvent">
                    <option value="">Select event...</option>
                    @foreach(\App\Models\NotificationTemplate::EVENTS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                @error('templateEvent') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Title Template</label>
                <x-ui.input type="text" wire:model="templateTitle" placeholder="e.g. {site_name} is down!" />
                @error('templateTitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Message Template</label>
                <textarea wire:model="templateMessage" rows="3" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="e.g. {site_name} ({site_url}) is currently experiencing issues. {details}"></textarea>
                @error('templateMessage') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="templateIsActive" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                <span class="text-sm text-gray-700">Active</span>
            </label>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-notification-template')">Cancel</x-ui.button>
            <x-ui.button wire:click="saveTemplate">Save Template</x-ui.button>
        </div>
    </x-ui.modal>
</div>
