<div>
    {{-- No x-cloak gate here on purpose: the section used to be wrapped in
         x-show="ready" and only appeared once Alpine had booted, which cost a
         layout jump on every load and hid the whole form outright whenever
         Alpine failed. --}}
    <form wire:submit="save" class="space-y-6">
        <x-settings.section id="smtp"
                            :title="__('Email Delivery')"
                            :subtitle="__('Mail server used for notifications and reports.')">
            <x-slot:actions>
                <x-ui.badge variant="{{ $host ? 'green' : 'red' }}">
                    {{ $host ? __('Configured') : __('Not set') }}
                </x-ui.badge>
            </x-slot:actions>

            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Mailer')" for="mail-mailer" error="mailer">
                        <x-ui.select id="mail-mailer" wire:model.live="mailer">
                            <option value="smtp">SMTP</option>
                            <option value="log">{{ __('Log (no emails sent)') }}</option>
                        </x-ui.select>
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Encryption')" for="mail-encryption" error="encryption"
                                     :hint="__('STARTTLS is negotiated with the server; SMTPS connects over TLS from the first byte.')">
                        <x-ui.select id="mail-encryption" wire:model="encryption">
                            <option value="tls">{{ __('STARTTLS (recommended)') }}</option>
                            <option value="ssl">{{ __('SSL / SMTPS (implicit TLS)') }}</option>
                            {{-- "None" is kept because existing installs store it,
                                 but it is not a plaintext mode: the transport
                                 treats it exactly like STARTTLS. Labelled for what
                                 it does rather than for what it says. --}}
                            <option value="none">{{ __('None (behaves like STARTTLS)') }}</option>
                        </x-ui.select>
                    </x-ui.form-group>
                </div>

                @if($mailer === 'smtp')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.form-group :label="__('SMTP Host')" for="mail-host" error="host" required>
                            <x-ui.input id="mail-host" wire:model="host" placeholder="smtp.example.com" />
                        </x-ui.form-group>

                        <x-ui.form-group :label="__('SMTP Port')" for="mail-port" error="port" required>
                            <x-ui.input id="mail-port" wire:model="port" type="number" min="1" max="65535" placeholder="587" />
                        </x-ui.form-group>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.form-group :label="__('Username')" for="mail-username" error="username">
                            <x-ui.input id="mail-username" wire:model="username" autocomplete="off" />
                        </x-ui.form-group>

                        <x-ui.form-group :label="__('Password')" for="mail-password" error="password"
                                         :hint="$hasPassword ? __('Leave blank to keep current password.') : null">
                            <x-ui.input id="mail-password" wire:model="password" type="password"
                                        autocomplete="new-password"
                                        placeholder="{{ $hasPassword ? '••••••••' : '' }}" />
                        </x-ui.form-group>
                    </div>
                @else
                    <p class="text-sm text-gray-500">{{ __('The log mailer writes emails to the application log instead of sending them.') }}</p>
                @endif
            </div>
        </x-settings.section>

        <x-settings.section :title="__('Sender Identity')"
                            :subtitle="__('From address and name used on outgoing emails.')">
            <x-slot:actions>
                <x-ui.badge variant="{{ $fromAddress ? 'green' : 'gray' }}">
                    {{ $fromAddress ? __('Set') : __('Not set') }}
                </x-ui.badge>
            </x-slot:actions>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.form-group :label="__('From Address')" for="mail-from-address" error="fromAddress" required>
                    <x-ui.input id="mail-from-address" wire:model="fromAddress" type="email" placeholder="noreply@example.com" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('From Name')" for="mail-from-name" error="fromName" required>
                    <x-ui.input id="mail-from-name" wire:model="fromName" placeholder="SimpleAD Manager" />
                </x-ui.form-group>
            </div>
        </x-settings.section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.button type="button" variant="secondary"
                         wire:click="sendTestEmail"
                         wire:loading.attr="disabled"
                         wire:target="sendTestEmail">
                <x-ui.spinner size="sm" class="hidden"
                              wire:loading.class.remove="hidden" wire:target="sendTestEmail" />
                <x-icons.mail class="h-4 w-4" aria-hidden="true"
                              wire:loading.remove wire:target="sendTestEmail" />
                {{ __('Send Test Email') }}
            </x-ui.button>

            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <x-ui.spinner size="sm" class="hidden"
                              wire:loading.class.remove="hidden" wire:target="save" />
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </form>
</div>
