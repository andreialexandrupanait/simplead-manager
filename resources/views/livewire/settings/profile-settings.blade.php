<div>
    @include('livewire.settings.partials.settings-tabs')

    <div class="space-y-6">
        {{-- Profile Section --}}
        <form wire:submit="saveProfile">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Profile Information') }}</h3>
                <div class="space-y-4">
                    {{-- Avatar --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Avatar') }}</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-purple-100 text-lg font-bold text-purple-600 overflow-hidden">
                                @if(Auth::user()->avatar_path)
                                    <img src="{{ Storage::url(Auth::user()->avatar_path) }}" alt="" class="h-full w-full object-cover">
                                @else
                                    {{ Auth::user()->initials }}
                                @endif
                            </div>
                            <div>
                                <input type="file" wire:model="avatar" accept="image/*" class="text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-purple-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-purple-700 hover:file:bg-purple-100">
                                @error('avatar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <x-ui.form-group :label="__('Name')" for="name" error="name">
                        <x-ui.input wire:model="name" id="name" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Email')" for="email" error="email">
                        <x-ui.input wire:model="email" id="email" type="email" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Timezone')" for="timezone">
                        <x-ui.select wire:model="timezone" id="timezone">
                            @foreach(timezone_identifiers_list() as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Language')" for="language">
                        <x-ui.select wire:model="language" id="language">
                            <option value="en">{{ __('English') }}</option>
                            <option value="ro">{{ __('Romanian') }}</option>
                        </x-ui.select>
                    </x-ui.form-group>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveProfile">{{ __('Save Profile') }}</span>
                        <span wire:loading wire:target="saveProfile">{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </x-ui.card>
        </form>

        {{-- Password Section --}}
        <form wire:submit="changePassword">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Change Password') }}</h3>
                <div class="space-y-4">
                    <x-ui.form-group :label="__('Current Password')" for="currentPassword" error="currentPassword">
                        <x-ui.input wire:model="currentPassword" id="currentPassword" type="password" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('New Password')" for="newPassword" error="newPassword">
                        <x-ui.input wire:model="newPassword" id="newPassword" type="password" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Confirm New Password')" for="newPasswordConfirmation" error="newPasswordConfirmation">
                        <x-ui.input wire:model="newPasswordConfirmation" id="newPasswordConfirmation" type="password" />
                    </x-ui.form-group>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="changePassword">{{ __('Change Password') }}</span>
                        <span wire:loading wire:target="changePassword">{{ __('Updating...') }}</span>
                    </x-ui.button>
                </div>
            </x-ui.card>
        </form>

        {{-- Two-Factor Authentication --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-2">{{ __('Two-Factor Authentication') }}</h3>
            <p class="text-sm text-gray-500 mb-4">{{ __('Add an additional layer of security to your account using a TOTP authenticator app.') }}</p>

            @if(!Auth::user()->two_factor_enabled && !$showingQrCode)
                {{-- Not enabled — show enable button --}}
                <div class="flex items-center gap-3">
                    <x-ui.badge variant="gray">{{ __('Not enabled') }}</x-ui.badge>
                    <x-ui.button wire:click="enableTwoFactor" variant="secondary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="enableTwoFactor">{{ __('Enable 2FA') }}</span>
                        <span wire:loading wire:target="enableTwoFactor">{{ __('Setting up...') }}</span>
                    </x-ui.button>
                </div>
            @elseif($showingQrCode)
                {{-- QR code setup phase --}}
                <div class="space-y-4">
                    <p class="text-sm text-gray-700">{{ __('Scan the QR code below with your authenticator app (Google Authenticator, Authy, 1Password, etc.), then enter the 6-digit code to confirm.') }}</p>
                    <div class="flex justify-center p-4 bg-white rounded-lg border border-gray-200">
                        {!! $twoFactorQrSvg !!}
                    </div>
                    <x-ui.form-group :label="__('Verification Code')" for="twoFactorCode" error="twoFactorCode" class="max-w-xs">
                        <x-ui.input wire:model="twoFactorCode" id="twoFactorCode" placeholder="000000" maxlength="6" />
                    </x-ui.form-group>
                    <div class="flex items-center gap-3">
                        <x-ui.button wire:click="confirmTwoFactor">{{ __('Confirm & Enable') }}</x-ui.button>
                        <x-ui.button wire:click="$set('showingQrCode', false)" variant="secondary">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </div>
            @else
                {{-- Enabled — show status + actions --}}
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <x-ui.badge variant="green">{{ __('Enabled') }}</x-ui.badge>
                    </div>

                    @if($showingRecoveryCodes && !empty($recoveryCodes))
                        <div>
                            <p class="text-sm font-medium text-gray-700 mb-2">{{ __('Recovery Codes') }}</p>
                            <p class="text-xs text-gray-500 mb-3">{{ __('Store these codes in a secure location. Each code can only be used once.') }}</p>
                            <div class="grid grid-cols-2 gap-2 max-w-sm bg-gray-50 rounded-lg p-4 border border-gray-200">
                                @foreach($recoveryCodes as $code)
                                    <code class="text-sm font-mono text-gray-800">{{ $code }}</code>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center gap-3">
                        <x-ui.button wire:click="showRecoveryCodes" variant="secondary">{{ __('Show Recovery Codes') }}</x-ui.button>
                        <x-ui.button wire:click="regenerateRecoveryCodes" variant="secondary">{{ __('Regenerate Codes') }}</x-ui.button>
                        <x-ui.button wire:click="disableTwoFactor" variant="danger" wire:confirm="{{ __('Are you sure you want to disable two-factor authentication?') }}">{{ __('Disable 2FA') }}</x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>

        {{-- Data Export (GDPR) --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-2">{{ __('Download My Data') }}</h3>
            <p class="text-sm text-gray-500 mb-4">{{ __('Export a copy of all your personal data including profile, sites, report metadata, and activity logs.') }}</p>
            <x-ui.button variant="secondary" wire:click="exportData" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="exportData">{{ __('Download My Data') }}</span>
                <span wire:loading wire:target="exportData">{{ __('Preparing export...') }}</span>
            </x-ui.button>
        </x-ui.card>

        {{-- Danger Zone --}}
        <x-ui.card class="border-red-200 ring-red-100">
            <h3 class="text-base font-semibold text-red-600 mb-2">{{ __('Delete Account') }}</h3>
            <p class="text-sm text-gray-500 mb-4">{{ __('Permanently delete your account and all associated data. This action cannot be undone.') }}</p>
            <x-ui.form-group :label="__('Confirm your password')" for="deleteAccountPassword" error="deleteAccountPassword" class="max-w-sm mb-4">
                <x-ui.input type="password" wire:model="deleteAccountPassword" id="deleteAccountPassword" placeholder="{{ __('Enter your password to confirm') }}" />
            </x-ui.form-group>
            <x-ui.button variant="danger" wire:click="deleteAccount" wire:confirm="{{ __('Are you sure you want to delete your account? This cannot be undone.') }}" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="deleteAccount">{{ __('Delete Account') }}</span>
                <span wire:loading wire:target="deleteAccount">{{ __('Deleting...') }}</span>
            </x-ui.button>
        </x-ui.card>
    </div>
</div>
