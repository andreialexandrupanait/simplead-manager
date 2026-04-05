<div>
    @include('livewire.settings.partials.settings-tabs')

    <form wire:submit="save" class="space-y-6" x-data="{ ready: false }" x-init="$nextTick(() => ready = true)" x-show="ready" x-cloak>
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 shadow-sm ring-1 ring-blue-200">
                        <x-icons.mail class="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('SMTP Connection') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Mail server configuration') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="{{ $host ? 'green' : 'red' }}">{{ $host ? __('Configured') : __('Not set') }}</x-ui.badge>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Mailer') }}</label>
                        <x-ui.select wire:model.live="mailer" class="mt-1">
                            <option value="smtp">SMTP</option>
                            <option value="log">{{ __('Log (no emails sent)') }}</option>
                        </x-ui.select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Encryption') }}</label>
                        <x-ui.select wire:model="encryption" class="mt-1">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                            <option value="none">None</option>
                        </x-ui.select>
                        @error('encryption') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('SMTP Host') }}</label>
                        <x-ui.input wire:model="host" placeholder="smtp.example.com" class="mt-1" />
                        @error('host') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('SMTP Port') }}</label>
                        <x-ui.input wire:model="port" type="number" min="1" max="65535" placeholder="587" class="mt-1" />
                        @error('port') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Username') }}</label>
                        <x-ui.input wire:model="username" autocomplete="off" class="mt-1" />
                        @error('username') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                        <x-ui.input wire:model="password" type="password" autocomplete="new-password" placeholder="{{ $hasPassword ? '••••••••' : '' }}" class="mt-1" />
                        @if($hasPassword && $password === '')
                            <p class="mt-1 text-xs text-gray-400">{{ __('Leave blank to keep current password.') }}</p>
                        @endif
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-50 shadow-sm ring-1 ring-purple-200">
                        <x-icons.inbox class="h-5 w-5 text-purple-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Sender Identity') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('From address & name for outgoing emails') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="{{ $fromAddress ? 'green' : 'gray' }}">{{ $fromAddress ? __('Set') : __('Not set') }}</x-ui.badge>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('From Address') }}</label>
                        <x-ui.input wire:model="fromAddress" type="email" placeholder="noreply@example.com" class="mt-1" />
                        @error('fromAddress') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('From Name') }}</label>
                        <x-ui.input wire:model="fromName" placeholder="SimpleAD Manager" class="mt-1" />
                        @error('fromName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-between">
            <button type="button" wire:click="sendTestEmail" wire:loading.attr="disabled" wire:target="sendTestEmail"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50">
                <x-icons.mail class="h-4 w-4 text-gray-400" />
                <span wire:loading.remove wire:target="sendTestEmail">{{ __('Send Test Email') }}</span>
                <span wire:loading wire:target="sendTestEmail">{{ __('Sending...') }}</span>
            </button>

            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ __('Save Settings') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
            </x-ui.button>
        </div>
    </form>
</div>
