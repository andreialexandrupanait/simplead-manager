<x-ui.modal name="channel-form" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $channelId ? 'Edit Channel' : 'Add Notification Channel' }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">Configure how you want to receive alerts.</p>

        <div class="mt-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Channel Name</label>
                <x-ui.input wire:model="form.name" placeholder="e.g. Ops Team Email" class="mt-1" />
                @error('form.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Type</label>
                <x-ui.select wire:model.live="form.type" class="mt-1">
                    <option value="email">Email</option>
                    <option value="slack">Slack</option>
                    <option value="discord">Discord</option>
                    <option value="telegram">Telegram</option>
                    <option value="webhook">Webhook</option>
                </x-ui.select>
            </div>

            {{-- Email fields --}}
            @if($form->type === 'email')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Email Address</label>
                    <x-ui.input wire:model="form.emailAddress" type="email" placeholder="alerts@example.com" class="mt-1" />
                </div>
            @endif

            {{-- Slack / Discord fields --}}
            @if(in_array($form->type, ['slack', 'discord']))
                <div>
                    <label class="block text-sm font-medium text-gray-700">Webhook URL</label>
                    <x-ui.input wire:model="form.webhookUrl" type="url" placeholder="https://hooks.slack.com/..." class="mt-1" />
                </div>
            @endif

            {{-- Telegram fields --}}
            @if($form->type === 'telegram')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Bot Token</label>
                    <x-ui.input wire:model="form.telegramBotToken" type="password" placeholder="123456:ABC-DEF..." class="mt-1" />
                    <p class="mt-1 text-xs text-gray-400">Get a bot token from @BotFather on Telegram</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Chat ID</label>
                    <x-ui.input wire:model="form.telegramChatId" placeholder="-1001234567890" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-400">Channel, group, or user chat ID</p>
                </div>
            @endif

            {{-- Generic webhook fields --}}
            @if($form->type === 'webhook')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Webhook URL</label>
                    <x-ui.input wire:model="form.webhookUrl" type="url" placeholder="https://api.example.com/webhook" class="mt-1" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">HTTP Method</label>
                    <x-ui.select wire:model="form.webhookMethod" class="mt-1">
                        <option value="POST">POST</option>
                        <option value="PUT">PUT</option>
                        <option value="PATCH">PATCH</option>
                    </x-ui.select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Custom Headers (JSON)</label>
                    <textarea
                        wire:model="form.webhookHeaders"
                        rows="3"
                        placeholder='{"Authorization": "Bearer token"}'
                        class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                    ></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Signing Secret</label>
                    <x-ui.input wire:model="form.webhookSigningSecret" type="password" placeholder="Optional HMAC signing secret" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-400">If set, an X-Signature header (HMAC-SHA256) is included with each request.</p>
                </div>
            @endif

            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="form.is_default" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                <span class="text-sm text-gray-700">Use as default channel for all monitors</span>
            </label>

            {{-- Event Subscriptions --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Event Subscriptions</label>
                <p class="text-xs text-gray-400 mb-3">Leave all unchecked to receive all events, or select specific events.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach([
                        'site_down' => 'Site Down',
                        'site_up' => 'Site Recovered',
                        'domain_expiring' => 'Domain Expiring',
                        'backup_failed' => 'Backup Failed',
                        'performance_drop' => 'Performance Drop',
                        'budget_violation' => 'Budget Violation',
                        'app_backup_completed' => 'App Backup Completed',
                        'app_backup_failed' => 'App Backup Failed',
                        'horizon_stopped' => 'Horizon Stopped',
                        'horizon_long_wait' => 'Horizon Long Wait',
                        'job_failures' => 'Repeated Job Failures',
                        'test' => 'Test Notification',
                    ] as $eventKey => $eventLabel)
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="form.eventSubscriptions" value="{{ $eventKey }}" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            <span class="text-xs text-gray-600">{{ $eventLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-channel-form')">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit">
                {{ $channelId ? 'Update Channel' : 'Create Channel' }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
