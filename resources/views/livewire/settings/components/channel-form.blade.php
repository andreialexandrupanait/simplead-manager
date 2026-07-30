<x-ui.modal name="channel-form" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $channelId ? __('Edit Channel') : __('Add Notification Channel') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('Configure how you want to receive alerts.') }}</p>

        <div class="mt-6 space-y-4">
            <x-ui.form-group :label="__('Channel Name')" for="channel-name" error="form.name" required>
                <x-ui.input id="channel-name" wire:model="form.name" :placeholder="__('e.g. Ops Team Email')" />
            </x-ui.form-group>

            <x-ui.form-group :label="__('Type')" for="channel-type" error="form.type" required>
                <x-ui.select id="channel-type" wire:model.live="form.type">
                    <option value="email">{{ __('Email') }}</option>
                    <option value="slack">{{ __('Slack') }}</option>
                    <option value="discord">{{ __('Discord') }}</option>
                    <option value="telegram">{{ __('Telegram') }}</option>
                    <option value="webhook">{{ __('Webhook') }}</option>
                </x-ui.select>
            </x-ui.form-group>

            {{-- Email --}}
            @if($form->type === 'email')
                <x-ui.form-group :label="__('Email Address')" for="channel-email" error="form.emailAddress" required>
                    <x-ui.input id="channel-email" type="email" wire:model="form.emailAddress" placeholder="alerts@example.com" />
                </x-ui.form-group>
            @endif

            {{-- Slack / Discord --}}
            @if(in_array($form->type, ['slack', 'discord'], true))
                <x-ui.form-group :label="__('Webhook URL')" for="channel-webhook-url" error="form.webhookUrl" required>
                    <x-ui.input id="channel-webhook-url" type="url" wire:model="form.webhookUrl" placeholder="https://hooks.slack.com/..." />
                </x-ui.form-group>
            @endif

            {{-- Telegram --}}
            @if($form->type === 'telegram')
                <x-ui.form-group :label="__('Bot Token')" for="channel-telegram-token" error="form.telegramBotToken"
                                 :hint="__('Get a bot token from @BotFather on Telegram.')" required>
                    <x-ui.input id="channel-telegram-token" type="password" wire:model="form.telegramBotToken"
                                autocomplete="new-password" placeholder="123456:ABC-DEF..." />
                </x-ui.form-group>

                <x-ui.form-group :label="__('Chat ID')" for="channel-telegram-chat" error="form.telegramChatId"
                                 :hint="__('Channel, group, or user chat ID.')" required>
                    <x-ui.input id="channel-telegram-chat" wire:model="form.telegramChatId" placeholder="-1001234567890" />
                </x-ui.form-group>
            @endif

            {{-- Generic webhook --}}
            @if($form->type === 'webhook')
                <x-ui.form-group :label="__('Webhook URL')" for="channel-webhook-url" error="form.webhookUrl"
                                 :hint="__('Must be a public address — internal and loopback targets are rejected.')" required>
                    <x-ui.input id="channel-webhook-url" type="url" wire:model="form.webhookUrl" placeholder="https://api.example.com/webhook" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('HTTP Method')" for="channel-webhook-method" error="form.webhookMethod" required>
                    <x-ui.select id="channel-webhook-method" wire:model="form.webhookMethod">
                        <option value="POST">POST</option>
                        <option value="PUT">PUT</option>
                        <option value="PATCH">PATCH</option>
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Custom Headers (JSON)')" for="channel-webhook-headers" error="form.webhookHeaders"
                                 :hint="__('Leave empty for none.')">
                    <textarea id="channel-webhook-headers"
                              wire:model="form.webhookHeaders"
                              rows="3"
                              placeholder='{"Authorization": "Bearer token"}'
                              class="block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 font-mono text-[13px] placeholder:text-gray-400 focus:border-accent-500 focus:outline-none focus:ring-0"></textarea>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Signing Secret')" for="channel-webhook-secret" error="form.webhookSigningSecret"
                                 :hint="__('If set, an X-Signature header (HMAC-SHA256) is included with each request.')">
                    <x-ui.input id="channel-webhook-secret" type="password" wire:model="form.webhookSigningSecret"
                                autocomplete="new-password" :placeholder="__('Optional HMAC signing secret')" />
                </x-ui.form-group>
            @endif

            <x-ui.checkbox wire:model="form.is_default" :label="__('Use as default channel for all monitors')" />

            {{-- Event subscriptions --}}
            <div>
                <p class="mb-1 text-sm font-medium text-gray-700">{{ __('Event Subscriptions') }}</p>
                <p class="mb-3 text-xs text-gray-500">{{ __('Leave all unchecked to receive all events, or select specific events.') }}</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {{-- Single source of truth: NotificationTemplate::EVENTS, so the
                         subscription list can never drift from the events actually emitted. --}}
                    @foreach(\App\Models\NotificationTemplate::EVENTS as $eventKey => $eventLabel)
                        <x-ui.checkbox wire:model="form.eventSubscriptions" value="{{ $eventKey }}" :label="__($eventLabel)" />
                    @endforeach
                </div>
                @error('form.eventSubscriptions') <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-channel-form')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                {{-- save() resolves the webhook host through SsrfGuard, which does a
                     DNS lookup — slow enough to need a visible pending state. --}}
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                {{ $channelId ? __('Update Channel') : __('Create Channel') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
