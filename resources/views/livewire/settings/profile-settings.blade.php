<div class="space-y-6">
    {{-- Profile ------------------------------------------------------------ --}}
    <x-settings.section id="profile"
                        :title="__('Profile Information')"
                        :subtitle="__('Your personal details and preferences.')">
        <form wire:submit="saveProfile" class="space-y-4">
            <x-ui.form-group :label="__('Avatar')" for="avatar" error="avatar"
                             :hint="__('JPG, PNG, GIF or WebP. Up to 2 MB and 1000×1000 px.')">
                <div class="flex items-center gap-4">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-accent-100 text-lg font-bold text-accent-600">
                        @if(Auth::user()->avatar_path)
                            <img src="{{ Storage::url(Auth::user()->avatar_path) }}"
                                 alt="{{ __('Avatar') }} {{ Auth::user()->name }}"
                                 class="h-full w-full object-cover">
                        @else
                            {{ Auth::user()->initials }}
                        @endif
                    </div>

                    <input id="avatar" type="file" wire:model="avatar" accept="image/*"
                           class="min-w-0 text-[13px] text-gray-500
                                  file:mr-3 file:rounded-lg file:border file:border-accent-500 file:bg-accent-50
                                  file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-accent-700
                                  hover:file:bg-accent-100">

                    <x-ui.spinner size="sm" class="hidden shrink-0 text-gray-400"
                                  wire:loading.class.remove="hidden" wire:target="avatar" />
                </div>
            </x-ui.form-group>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group :label="__('Name')" for="name" error="name" required>
                    <x-ui.input wire:model="name" id="name" autocomplete="name" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('Email')" for="email" error="email" required>
                    <x-ui.input wire:model="email" id="email" type="email" autocomplete="email" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('Timezone')" for="timezone" error="timezone">
                    <x-ui.select wire:model="timezone" id="timezone">
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Language')" for="language" error="language">
                    <x-ui.select wire:model="language" id="language">
                        <option value="en">{{ __('English') }}</option>
                        <option value="ro">{{ __('Romanian') }}</option>
                    </x-ui.select>
                </x-ui.form-group>
            </div>

            <div class="flex justify-end border-t border-gray-100 pt-4">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveProfile">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="saveProfile" />
                    {{ __('Save Profile') }}
                </x-ui.button>
            </div>
        </form>
    </x-settings.section>

    {{-- Password ----------------------------------------------------------- --}}
    <x-settings.section id="password"
                        :title="__('Change Password')"
                        :subtitle="__('Update your account password. You stay signed in on this device.')">
        <form wire:submit="changePassword" class="space-y-4">
            <x-ui.form-group :label="__('Current Password')" for="currentPassword" error="currentPassword"
                             class="sm:max-w-sm" required>
                <x-ui.input wire:model="currentPassword" id="currentPassword" type="password"
                            autocomplete="current-password" />
            </x-ui.form-group>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group :label="__('New Password')" for="newPassword" error="newPassword"
                                 :hint="__('At least 8 characters, different from the current one.')" required>
                    <x-ui.input wire:model="newPassword" id="newPassword" type="password"
                                autocomplete="new-password" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('Confirm New Password')" for="newPasswordConfirmation"
                                 error="newPasswordConfirmation" required>
                    <x-ui.input wire:model="newPasswordConfirmation" id="newPasswordConfirmation" type="password"
                                autocomplete="new-password" />
                </x-ui.form-group>
            </div>

            <div class="flex justify-end border-t border-gray-100 pt-4">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="changePassword">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="changePassword" />
                    {{ __('Change Password') }}
                </x-ui.button>
            </div>
        </form>
    </x-settings.section>

    {{-- API tokens --------------------------------------------------------- --}}
    <x-settings.section id="tokens"
                        :title="__('API Tokens')"
                        :subtitle="__('Create personal access tokens to authenticate with the API.')">
        <x-slot:actions>
            <x-ui.badge :variant="$this->apiTokens->isNotEmpty() ? 'blue' : 'gray'">
                {{ trans_choice('{0}No tokens|{1}:count token|[2,*]:count tokens', $this->apiTokens->count(), ['count' => $this->apiTokens->count()]) }}
            </x-ui.badge>
        </x-slot:actions>

        {{-- The plaintext token exists for exactly one render. It gets the loudest
             treatment on the page: full-width green panel, icon, monospace value,
             one-click copy. Losing it means creating another token. --}}
        @if($newTokenPlainText)
            <div class="mb-5 rounded-xl border border-green-200 bg-green-50 p-4"
                 x-data="{ token: @js($newTokenPlainText), copied: false }"
                 role="alert">
                <div class="flex items-start gap-3">
                    <x-icons.check-circle class="mt-0.5 h-5 w-5 shrink-0 text-green-600" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-green-800">{{ __('Token created') }}</p>
                        <p class="mt-0.5 text-xs text-green-700">{{ __('Copy it now — this is the only time it is shown.') }}</p>

                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                            <code class="min-w-0 flex-1 select-all break-all rounded-lg border border-green-200 bg-white px-3 py-2 font-mono text-xs text-gray-900">{{ $newTokenPlainText }}</code>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.button size="sm" variant="secondary"
                                             x-on:click="navigator.clipboard.writeText(token); copied = true; setTimeout(() => copied = false, 2000)">
                                    <x-icons.copy class="h-3.5 w-3.5" aria-hidden="true" />
                                    <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                </x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="dismissNewToken">
                                    {{ __('Done') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <form wire:submit="createApiToken" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <x-ui.form-group :label="__('Token name')" for="newTokenName" error="newTokenName" class="flex-1">
                <x-ui.input type="text" id="newTokenName" wire:model="newTokenName"
                            placeholder="{{ __('e.g. CI/CD Pipeline') }}" />
            </x-ui.form-group>

            <x-ui.button type="submit" class="sm:mt-6" wire:loading.attr="disabled" wire:target="createApiToken">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="createApiToken" />
                <x-icons.plus class="h-4 w-4" wire:loading.remove wire:target="createApiToken" aria-hidden="true" />
                {{ __('Create Token') }}
            </x-ui.button>
        </form>

        @if($this->apiTokens->isEmpty())
            <x-ui.empty-state icon="code"
                              :title="__('No API tokens yet')"
                              :description="__('Tokens let scripts and external tools talk to the API on your behalf.')" />
        @else
            <ul class="mt-5 divide-y divide-gray-100 border-t border-gray-100">
                @foreach($this->apiTokens as $token)
                    <li class="flex items-center justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $token->name }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ __('Created') }} {{ $token->created_at->diffForHumans() }}
                                @if($token->last_used_at)
                                    &middot; {{ __('Last used') }} {{ $token->last_used_at->diffForHumans() }}
                                @else
                                    &middot; {{ __('Never used') }}
                                @endif
                            </p>
                        </div>

                        <x-ui.button variant="danger" size="xs"
                                     wire:click="revokeApiToken({{ $token->id }})"
                                     wire:confirm="{{ __('Revoke this token? Any applications using it will lose access.') }}"
                                     wire:loading.attr="disabled" wire:target="revokeApiToken({{ $token->id }})">
                            <x-ui.spinner size="xs" class="hidden" wire:loading.class.remove="hidden"
                                          wire:target="revokeApiToken({{ $token->id }})" />
                            {{ __('Revoke') }}
                        </x-ui.button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-settings.section>

    {{-- Data export (GDPR) -------------------------------------------------- --}}
    <x-settings.section id="data"
                        :title="__('Download My Data')"
                        :subtitle="__('Export a copy of all your personal data including profile, sites, report metadata, and activity logs.')">
        <x-ui.button variant="secondary" wire:click="exportData"
                     wire:loading.attr="disabled" wire:target="exportData">
            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="exportData" />
            <x-icons.hard-drive class="h-4 w-4" wire:loading.remove wire:target="exportData" aria-hidden="true" />
            {{ __('Download My Data') }}
        </x-ui.button>
    </x-settings.section>

    {{-- Danger zone -------------------------------------------------------- --}}
    <x-settings.section :title="__('Delete Account')"
                        :subtitle="__('Permanently delete your account and all associated data. This action cannot be undone.')">
        <x-slot:actions>
            <x-ui.badge variant="red">{{ __('Irreversible') }}</x-ui.badge>
        </x-slot:actions>

        <div class="rounded-xl border border-red-200 bg-red-50 p-4">
            <x-ui.form-group :label="__('Confirm your password')" for="deleteAccountPassword"
                             error="deleteAccountPassword" class="mb-4 max-w-sm">
                <x-ui.input type="password" wire:model="deleteAccountPassword" id="deleteAccountPassword"
                            autocomplete="current-password"
                            placeholder="{{ __('Enter your password to confirm') }}" />
            </x-ui.form-group>

            <x-ui.button variant="danger" wire:click="deleteAccount"
                         wire:confirm="{{ __('Are you sure you want to delete your account? This cannot be undone.') }}"
                         wire:loading.attr="disabled" wire:target="deleteAccount">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="deleteAccount" />
                <x-icons.trash class="h-4 w-4" wire:loading.remove wire:target="deleteAccount" aria-hidden="true" />
                {{ __('Delete Account') }}
            </x-ui.button>
        </div>
    </x-settings.section>
</div>
