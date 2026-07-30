<div class="space-y-6">
    {{-- Channels ------------------------------------------------------- --}}
    <x-settings.section id="channels"
                        :title="__('Channels')"
                        :subtitle="__('Where alerts are delivered.')">
        <x-slot:actions>
            <x-ui.button size="sm" wire:click="$dispatch('open-channel-form')">
                <x-icons.plus class="h-4 w-4" aria-hidden="true" />
                {{ __('Add Channel') }}
            </x-ui.button>
        </x-slot:actions>

        @if($this->channels->isEmpty())
            <x-ui.empty-state icon="bell"
                              :title="__('No notification channels')"
                              :description="__('Nothing is being notified yet. Add an email address, a Slack or Discord webhook, a Telegram bot or a custom webhook.')">
                <x-slot:action>
                    <x-ui.button size="sm" wire:click="$dispatch('open-channel-form')">
                        <x-icons.plus class="h-4 w-4" aria-hidden="true" />
                        {{ __('Add Channel') }}
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($this->channels as $channel)
                    @php
                        // Slack and Discord are webhook URLs, Telegram is a bot,
                        // email is email — three shapes, three icons.
                        $typeIcon = match($channel->type) {
                            'email' => 'mail',
                            'slack', 'discord', 'webhook' => 'link',
                            'telegram' => 'bell',
                            default => 'bell',
                        };
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
                                <x-dynamic-component :component="'icons.' . $typeIcon" class="h-4 w-4" aria-hidden="true" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900">{{ $channel->name }}</p>
                                <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                    <span>{{ ucfirst($channel->type) }}</span>
                                    @if($channel->is_default)
                                        <x-ui.badge variant="blue">{{ __('Default') }}</x-ui.badge>
                                    @endif
                                    @unless($channel->is_active)
                                        <x-ui.badge variant="gray">{{ __('Disabled') }}</x-ui.badge>
                                    @endunless
                                </div>
                                @if($channel->last_error)
                                    <p class="mt-0.5 text-xs text-red-600" title="{{ $channel->last_error_at?->diffForHumans() }}">
                                        {{ __('Error') }}: {{ \Illuminate\Support\Str::limit($channel->last_error, 60) }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button variant="ghost" size="xs"
                                         wire:click="testChannel({{ $channel->id }})"
                                         wire:loading.attr="disabled"
                                         wire:target="testChannel({{ $channel->id }})">
                                <x-ui.spinner size="xs" class="hidden"
                                              wire:loading.class.remove="hidden"
                                              wire:target="testChannel({{ $channel->id }})" />
                                {{ __('Test') }}
                            </x-ui.button>

                            <x-ui.button variant="ghost" size="xs"
                                         wire:click="toggleChannel({{ $channel->id }})"
                                         wire:loading.attr="disabled"
                                         wire:target="toggleChannel({{ $channel->id }})">
                                <x-ui.spinner size="xs" class="hidden"
                                              wire:loading.class.remove="hidden"
                                              wire:target="toggleChannel({{ $channel->id }})" />
                                {{ $channel->is_active ? __('Disable') : __('Enable') }}
                            </x-ui.button>

                            <x-ui.button variant="ghost" size="xs"
                                         wire:click="$dispatch('open-channel-form', { channelId: {{ $channel->id }} })">
                                {{ __('Edit') }}
                            </x-ui.button>

                            <x-ui.button variant="danger" size="xs"
                                         wire:click="deleteChannel({{ $channel->id }})"
                                         wire:confirm="{{ __('Delete this notification channel?') }}"
                                         wire:loading.attr="disabled"
                                         wire:target="deleteChannel({{ $channel->id }})">
                                {{ __('Delete') }}
                            </x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-settings.section>

    {{-- Preferences ---------------------------------------------------- --}}
    <x-settings.section id="preferences"
                        :title="__('Preferences')"
                        :subtitle="__('Which events are worth a notification.')">
        <div class="space-y-3">
            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Site Down') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Notify when a site goes down') }}</p>
                </div>
                <x-ui.toggle wire:model="notifyDown" :enabled="$notifyDown" />
            </div>

            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Site Recovery') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Notify when a site recovers') }}</p>
                </div>
                <x-ui.toggle wire:model="notifyRecovery" :enabled="$notifyRecovery" />
            </div>

            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('SSL Expiring') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Notify when SSL certificate is expiring soon') }}</p>
                </div>
                <x-ui.toggle wire:model="notifySslExpiring" :enabled="$notifySslExpiring" />
            </div>
        </div>
    </x-settings.section>

    {{-- Quiet hours ---------------------------------------------------- --}}
    <x-settings.section id="quiet-hours"
                        :title="__('Quiet Hours')"
                        :subtitle="__('Suppress notifications during specified hours.')">
        <x-slot:actions>
            @if($quietHoursEnabled)
                <x-ui.badge variant="blue">{{ $quietHoursStart }}&ndash;{{ $quietHoursEnd }}</x-ui.badge>
            @else
                <x-ui.badge variant="gray">{{ __('Off') }}</x-ui.badge>
            @endif
        </x-slot:actions>

        <div class="space-y-4">
            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Enable quiet hours') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Alerts raised inside this window are held back.') }}</p>
                </div>
                <x-ui.toggle wire:model.live="quietHoursEnabled" :enabled="$quietHoursEnabled" />
            </div>

            @if($quietHoursEnabled)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Start Time')" for="quiet-hours-start" error="quietHoursStart">
                        <x-ui.input id="quiet-hours-start" type="time" wire:model="quietHoursStart" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('End Time')" for="quiet-hours-end" error="quietHoursEnd">
                        <x-ui.input id="quiet-hours-end" type="time" wire:model="quietHoursEnd" />
                    </x-ui.form-group>
                </div>
            @endif
        </div>

        {{-- One save for both sections: preferences and quiet hours are stored
             by the same call, so there is one button rather than two that each
             silently write the other's fields. --}}
        <x-ui.save-bar target="savePreferences"
                       :message="__('Preferences and quiet hours are saved together.')"
                       :label="__('Save Preferences')" />
    </x-settings.section>

    {{-- Escalation ----------------------------------------------------- --}}
    <x-settings.section id="escalation"
                        :title="__('Escalation')"
                        :subtitle="__('If a critical alert is not acknowledged within the delay, escalate it to another channel.')">
        <x-slot:actions>
            <x-ui.badge variant="{{ $this->escalationRules->isNotEmpty() ? 'blue' : 'gray' }}">
                {{ trans_choice(':count rule|:count rules', $this->escalationRules->count(), ['count' => $this->escalationRules->count()]) }}
            </x-ui.badge>
        </x-slot:actions>

        @if($this->escalationRules->isNotEmpty())
            <ul class="mb-4 divide-y divide-gray-100">
                @foreach($this->escalationRules as $rule)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <p class="min-w-0 truncate text-sm text-gray-700">
                            {{ $rule->sourceChannel->name }}
                            <span class="mx-1 text-gray-400" aria-hidden="true">&rarr;</span>
                            {{ $rule->escalationChannel->name }}
                            <span class="text-xs text-gray-500">({{ $rule->delay_minutes }} {{ __('min') }})</span>
                        </p>
                        <x-ui.button variant="ghost" size="xs"
                                     wire:click="deleteEscalationRule({{ $rule->id }})"
                                     wire:confirm="{{ __('Remove this escalation rule?') }}"
                                     wire:loading.attr="disabled"
                                     wire:target="deleteEscalationRule({{ $rule->id }})">
                            <x-icons.trash class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Remove') }}
                        </x-ui.button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if($this->channels->count() < 2)
            <p class="text-sm text-gray-500">{{ __('Escalation needs at least two channels — one to alert first, one to escalate to.') }}</p>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_8rem_auto] lg:items-start">
                <x-ui.form-group :label="__('From channel')" for="escalation-source" error="escalationSourceId">
                    <x-ui.select id="escalation-source" wire:model="escalationSourceId">
                        <option value="">{{ __('Select...') }}</option>
                        @foreach($this->channels as $ch)
                            <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Escalate to')" for="escalation-target" error="escalationTargetId">
                    <x-ui.select id="escalation-target" wire:model="escalationTargetId">
                        <option value="">{{ __('Select...') }}</option>
                        @foreach($this->channels as $ch)
                            <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Delay (min)')" for="escalation-delay" error="escalationDelay">
                    <x-ui.input id="escalation-delay" type="number" min="5" max="120" wire:model="escalationDelay" />
                </x-ui.form-group>

                <div class="lg:pt-[26px]">
                    <x-ui.button wire:click="addEscalationRule"
                                 wire:loading.attr="disabled"
                                 wire:target="addEscalationRule">
                        <x-ui.spinner size="sm" class="hidden"
                                      wire:loading.class.remove="hidden"
                                      wire:target="addEscalationRule" />
                        {{ __('Add Rule') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-settings.section>

    {{-- Templates ------------------------------------------------------ --}}
    <x-settings.section id="templates"
                        :title="__('Templates')"
                        :subtitle="__('Customize the notification wording per event. Without a template the default message is used.')">
        <x-slot:actions>
            <x-ui.button size="sm" wire:click="editTemplate">
                <x-icons.plus class="h-4 w-4" aria-hidden="true" />
                {{ __('Add Template') }}
            </x-ui.button>
        </x-slot:actions>

        @if($this->notificationTemplates->isEmpty())
            <x-ui.empty-state icon="file-text"
                              :title="__('No custom templates')"
                              :description="__('Default messages will be used.')" />
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($this->notificationTemplates as $tmpl)
                    <li class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900">
                                    {{ \App\Models\NotificationTemplate::EVENTS[$tmpl->event] ?? $tmpl->event }}
                                </span>
                                @unless($tmpl->is_active)
                                    <x-ui.badge variant="gray">{{ __('Disabled') }}</x-ui.badge>
                                @endunless
                            </div>
                            <p class="truncate text-xs text-gray-500">{{ $tmpl->title_template }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button variant="ghost" size="xs" wire:click="editTemplate({{ $tmpl->id }})">
                                {{ __('Edit') }}
                            </x-ui.button>
                            <x-ui.button variant="danger" size="xs"
                                         wire:click="deleteTemplate({{ $tmpl->id }})"
                                         wire:confirm="{{ __('Delete this template?') }}"
                                         wire:loading.attr="disabled"
                                         wire:target="deleteTemplate({{ $tmpl->id }})">
                                {{ __('Delete') }}
                            </x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-settings.section>

    {{-- Channel form modal --}}
    <livewire:settings.components.channel-form />

    {{-- Template form modal --}}
    <x-ui.modal name="notification-template" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $editingTemplateId ? __('Edit Template') : __('New Template') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">
            {{ __('Placeholders:') }}
            <code class="rounded bg-gray-100 px-1 text-xs">{site_name}</code>,
            <code class="rounded bg-gray-100 px-1 text-xs">{site_url}</code>,
            <code class="rounded bg-gray-100 px-1 text-xs">{details}</code>
        </p>

        <div class="mt-6 space-y-4">
            <x-ui.form-group :label="__('Event')" for="template-event" error="templateEvent" required>
                <x-ui.select id="template-event" wire:model="templateEvent">
                    <option value="">{{ __('Select event...') }}</option>
                    @foreach(\App\Models\NotificationTemplate::EVENTS as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.form-group>

            <x-ui.form-group :label="__('Title Template')" for="template-title" error="templateTitle" required>
                <x-ui.input id="template-title" type="text" wire:model="templateTitle"
                            placeholder="{site_name} is down!" />
            </x-ui.form-group>

            <x-ui.form-group :label="__('Message Template')" for="template-message" error="templateMessage" required>
                <textarea id="template-message" wire:model="templateMessage" rows="3"
                          placeholder="{site_name} ({site_url}) — {details}"
                          class="block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-[13px] placeholder:text-gray-400 focus:border-accent-500 focus:outline-none focus:ring-0"></textarea>
            </x-ui.form-group>

            <x-ui.checkbox wire:model="templateIsActive" :label="__('Active')" />
        </div>

        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-notification-template')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="button" wire:click="saveTemplate"
                         wire:loading.attr="disabled" wire:target="saveTemplate">
                <x-ui.spinner size="sm" class="hidden"
                              wire:loading.class.remove="hidden" wire:target="saveTemplate" />
                {{ __('Save Template') }}
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
