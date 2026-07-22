<div>
    @include('livewire.settings.partials.settings-tabs')

    <div class="space-y-6 max-w-2xl">
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Two-Factor Authentication') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Add an authenticator-app code to your login for extra security.') }}</p>
                </div>
                @if($this->enabled)
                    <x-ui.badge variant="green">{{ __('Enabled') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="gray">{{ __('Disabled') }}</x-ui.badge>
                @endif
            </div>

            {{-- Freshly generated recovery codes (shown once) --}}
            @if($showingRecoveryCodes && count($recoveryCodes))
                <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-900/20 p-4">
                    <p class="text-sm font-medium text-amber-800 dark:text-amber-200 mb-2">
                        {{ __('Store these recovery codes somewhere safe. Each can be used once if you lose your authenticator. They will not be shown again.') }}
                    </p>
                    <div class="grid grid-cols-2 gap-2 font-mono text-sm text-gray-800 dark:text-gray-200">
                        @foreach($recoveryCodes as $rc)
                            <div class="rounded bg-white dark:bg-gray-800 px-3 py-1.5 border border-amber-200 dark:border-amber-800">{{ $rc }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(! $this->enabled && ! $this->enrolling)
                {{-- Not enrolled --}}
                <x-ui.button wire:click="startEnrollment">{{ __('Enable two-factor authentication') }}</x-ui.button>
            @endif

            @if($this->enrolling)
                {{-- Enrollment: QR + confirm --}}
                <div class="space-y-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('Scan this QR code with Google Authenticator, Microsoft Authenticator, or Authy — then enter the 6-digit code to confirm.') }}
                    </p>

                    <div class="flex items-start gap-6 flex-col sm:flex-row">
                        <div class="rounded-lg bg-white p-3 border border-gray-200">
                            {!! $this->qrCodeSvg !!}
                        </div>
                        <div class="text-sm">
                            <p class="text-gray-500 mb-1">{{ __('Or enter this key manually:') }}</p>
                            <code class="font-mono text-xs break-all text-gray-800 dark:text-gray-200">{{ $this->pendingSecret }}</code>
                        </div>
                    </div>

                    <form wire:submit="confirm" class="space-y-3 max-w-xs">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Confirmation code') }}</label>
                            <x-ui.input type="text" wire:model="confirmCode" inputmode="numeric"
                                autocomplete="one-time-code" placeholder="123456" maxlength="6" />
                            @error('confirmCode')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex gap-2">
                            <x-ui.button type="submit">{{ __('Confirm & enable') }}</x-ui.button>
                            <x-ui.button type="button" variant="secondary" wire:click="cancelEnrollment">{{ __('Cancel') }}</x-ui.button>
                        </div>
                    </form>
                </div>
            @endif

            @if($this->enabled)
                {{-- Enabled: regenerate / disable --}}
                <div class="space-y-6">
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('Recovery codes') }}</h4>
                        <p class="text-sm text-gray-500 mb-3">{{ __('Regenerate a fresh set (invalidates the old ones). Requires your password.') }}</p>
                        <form wire:submit="regenerateRecoveryCodes" class="flex flex-col sm:flex-row gap-2 max-w-md">
                            <x-ui.input type="password" wire:model="password" placeholder="{{ __('Current password') }}" autocomplete="current-password" />
                            <x-ui.button type="submit" variant="secondary">{{ __('Regenerate codes') }}</x-ui.button>
                        </form>
                        @error('password')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <h4 class="text-sm font-semibold text-red-700 dark:text-red-400 mb-1">{{ __('Disable two-factor authentication') }}</h4>
                        <p class="text-sm text-gray-500 mb-3">{{ __('Admins are required to keep 2FA enabled; you may be prompted to set it up again.') }}</p>
                        <form wire:submit="disable" class="flex flex-col sm:flex-row gap-2 max-w-md">
                            <x-ui.input type="password" wire:model="password" placeholder="{{ __('Current password') }}" autocomplete="current-password" />
                            <x-ui.button type="submit" variant="danger">{{ __('Disable') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
