<div>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="login-saved" />

    {{-- Brute Force Protection --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Brute Force Protection</h3>
        <div class="space-y-4">
            <x-ui.form-group label="Max Login Attempts" for="maxAttempts" error="{{ $errors->first('maxAttempts') }}">
                <x-ui.input type="number" id="maxAttempts" wire:model.live="maxAttempts" min="1" max="100" />
            </x-ui.form-group>

            <x-ui.form-group label="Time Window (minutes)" for="windowMinutes" error="{{ $errors->first('windowMinutes') }}">
                <x-ui.input type="number" id="windowMinutes" wire:model.live="windowMinutes" min="1" max="1440" />
            </x-ui.form-group>

            <x-ui.form-group label="Block Duration (minutes)" for="blockDurationMinutes" error="{{ $errors->first('blockDurationMinutes') }}">
                <x-ui.input type="number" id="blockDurationMinutes" wire:model.live="blockDurationMinutes" min="1" max="43200" />
            </x-ui.form-group>
        </div>
    </x-ui.card>

    {{-- Custom Login URL --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Custom Login URL</h3>
        <p class="text-sm text-gray-500 mb-4">Change the default /wp-login.php URL to a custom slug to reduce automated attacks.</p>
        <div class="space-y-4">
            <x-ui.form-group label="Login URL Slug" for="loginSlug" error="{{ $errors->first('loginSlug') }}">
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
                <h3 class="text-base font-semibold text-gray-900">Two-Factor Authentication</h3>
                <p class="mt-1 text-sm text-gray-500">Require 2FA for administrator accounts on the WordPress site.</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">
                Coming Soon
            </span>
        </div>
    </x-ui.card>

    {{-- Sticky Save Bar --}}
    @if($isDirty)
        <div class="sticky bottom-0 mt-6 -mx-6 -mb-6 rounded-b-lg border-t border-gray-200 bg-white px-6 py-4 flex items-center justify-between shadow-lg">
            <p class="text-sm text-gray-500">You have unsaved changes</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                Save Changes
            </x-ui.button>
        </div>
    @endif
</div>
