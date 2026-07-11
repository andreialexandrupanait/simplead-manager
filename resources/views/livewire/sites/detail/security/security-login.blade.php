<div>
    <x-ui.page-header title="{{ __('Login Protection') }}" subtitle="{{ __('Brute force protection and custom login URL') }}">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" wire:click="verifySettings" wire:loading.attr="disabled" wire:target="verifySettings">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="verifySettings" />
                {{ __('Verify') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="login-saved" />
    <x-ui.flash-alert type="error" key="verify-error" />

    {{-- Brute Force Protection --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Brute Force Protection') }}</h3>
        <div class="space-y-4">
            <x-ui.form-group label="{{ __('Max Login Attempts') }}" for="maxAttempts" error="{{ $errors->first('maxAttempts') }}">
                <x-ui.input type="number" id="maxAttempts" wire:model.live="maxAttempts" min="1" max="100" />
            </x-ui.form-group>

            <x-ui.form-group label="{{ __('Time Window (minutes)') }}" for="windowMinutes" error="{{ $errors->first('windowMinutes') }}">
                <x-ui.input type="number" id="windowMinutes" wire:model.live="windowMinutes" min="1" max="1440" />
            </x-ui.form-group>

            <x-ui.form-group label="{{ __('Block Duration (minutes)') }}" for="blockDurationMinutes" error="{{ $errors->first('blockDurationMinutes') }}">
                <x-ui.input type="number" id="blockDurationMinutes" wire:model.live="blockDurationMinutes" min="1" max="43200" />
            </x-ui.form-group>
        </div>
    </x-ui.card>

    {{-- Custom Login URL --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Custom Login URL') }}</h3>
        <p class="text-sm text-gray-500 mb-4">{{ __('Change the default /wp-login.php URL to a custom slug to reduce automated attacks.') }}</p>
        <div class="space-y-4">
            <x-ui.form-group label="{{ __('Login URL Slug') }}" for="loginSlug" error="{{ $errors->first('loginSlug') }}">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-500">{{ $this->site->url }}/</span>
                    <x-ui.input type="text" id="loginSlug" wire:model.live.debounce.300ms="loginSlug" placeholder="my-login" class="max-w-xs" />
                </div>
            </x-ui.form-group>
        </div>
    </x-ui.card>

    {{-- Two-Factor Authentication --}}
    <x-ui.card>
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-900">{{ __('Two-Factor Authentication') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Email a 6-digit verification code on login for the selected roles. Trusted devices skip the code for 30 days.') }}</p>
            </div>
            <x-ui.toggle
                :enabled="$twoFactorEnabled"
                wire:click="toggleTwoFactor"
            />
        </div>

        @if($twoFactorEnabled)
            <div class="mt-4 grid gap-4 border-t border-gray-100 pt-4 sm:grid-cols-2">
                <x-ui.form-group label="{{ __('Required for roles') }}" for="twoFactorRoles">
                    <div class="space-y-1.5">
                        @foreach(['administrator' => __('Administrators'), 'editor' => __('Editors'), 'author' => __('Authors'), 'shop_manager' => __('Shop managers')] as $role => $label)
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" value="{{ $role }}" wire:model="twoFactorRoles" wire:change="saveTwoFactorOptions"
                                       class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </x-ui.form-group>
                <x-ui.form-group label="{{ __('If the code email fails') }}" for="twoFactorFailMode">
                    <select id="twoFactorFailMode" wire:model="twoFactorFailMode" wire:change="saveTwoFactorOptions"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <option value="open">{{ __('Allow the login (fail-open, recommended)') }}</option>
                        <option value="closed">{{ __('Block the login (fail-closed)') }}</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Fail-closed can lock everyone out if the site cannot send email.') }}</p>
                </x-ui.form-group>
            </div>
        @endif
    </x-ui.card>

    {{-- Sticky Save Bar --}}
    @if($isDirty)
        <div class="sticky bottom-0 mt-6 -mx-6 -mb-6 rounded-b-lg border-t border-gray-200 bg-white px-6 py-4 flex items-center justify-between shadow-lg">
            <p class="text-sm text-gray-500">{{ __('You have unsaved changes') }}</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    @endif
</div>
