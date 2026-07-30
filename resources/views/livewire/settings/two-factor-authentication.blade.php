<div>
    <x-settings.section id="two-factor"
                        :title="__('Two-Factor Authentication')"
                        :subtitle="__('Add an authenticator-app code to your login for extra security.')">
        <x-slot:actions>
            @if($this->enabled)
                <x-ui.badge variant="green">{{ __('Enabled') }}</x-ui.badge>
            @else
                <x-ui.badge variant="gray">{{ __('Disabled') }}</x-ui.badge>
            @endif
        </x-slot:actions>

        {{-- Freshly generated recovery codes (shown once) --}}
        @if($showingRecoveryCodes && count($recoveryCodes))
            <div class="mb-6 rounded-xl border border-yellow-300 bg-yellow-50 p-4"
                 x-data="{ codes: @js(implode(PHP_EOL, $recoveryCodes)), copied: false }"
                 role="alert">
                <div class="flex items-start gap-3">
                    <x-icons.alert-triangle class="mt-0.5 h-5 w-5 shrink-0 text-yellow-600" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-yellow-800">{{ __('Recovery codes') }}</p>
                        <p class="mt-0.5 text-xs text-yellow-700">
                            {{ __('Store these recovery codes somewhere safe. Each can be used once if you lose your authenticator. They will not be shown again.') }}
                        </p>

                        <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-gray-800">
                            @foreach($recoveryCodes as $rc)
                                <div class="select-all rounded-lg border border-yellow-200 bg-white px-3 py-1.5">{{ $rc }}</div>
                            @endforeach
                        </div>

                        <div class="mt-3">
                            <x-ui.button size="sm" variant="secondary"
                                         x-on:click="navigator.clipboard.writeText(codes); copied = true; setTimeout(() => copied = false, 2000)">
                                <x-icons.copy class="h-3.5 w-3.5" aria-hidden="true" />
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy codes'))">{{ __('Copy codes') }}</span>
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if(! $this->enabled && ! $this->enrolling)
            {{-- Not enrolled --}}
            <x-ui.button wire:click="startEnrollment" wire:loading.attr="disabled" wire:target="startEnrollment">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="startEnrollment" />
                <x-icons.shield-check class="h-4 w-4" wire:loading.remove wire:target="startEnrollment" aria-hidden="true" />
                {{ __('Enable two-factor authentication') }}
            </x-ui.button>
        @endif

        @if($this->enrolling)
            {{-- Enrollment: QR + confirm --}}
            <div class="space-y-4">
                <p class="text-sm text-gray-600">
                    {{ __('Scan this QR code with Google Authenticator, Microsoft Authenticator, or Authy — then enter the 6-digit code to confirm.') }}
                </p>

                <div class="flex flex-col items-start gap-6 sm:flex-row">
                    <div class="rounded-xl border border-gray-200 bg-white p-3">
                        {!! $this->qrCodeSvg !!}
                    </div>
                    <div class="min-w-0 text-sm">
                        <p class="mb-1 text-gray-500">{{ __('Or enter this key manually:') }}</p>
                        <code class="select-all break-all font-mono text-xs text-gray-800">{{ $this->pendingSecret }}</code>
                    </div>
                </div>

                <form wire:submit="confirm" class="max-w-xs space-y-3">
                    <x-ui.form-group :label="__('Confirmation code')" for="confirmCode" error="confirmCode">
                        <x-ui.input type="text" id="confirmCode" wire:model="confirmCode" inputmode="numeric"
                                    autocomplete="one-time-code" placeholder="123456" maxlength="6" />
                    </x-ui.form-group>

                    <div class="flex gap-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="confirm">
                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="confirm" />
                            {{ __('Confirm & enable') }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="secondary" wire:click="cancelEnrollment">
                            {{ __('Cancel') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        @endif

        @if($this->enabled)
            {{-- Enabled: regenerate / disable.

                 Each form owns its own password property. They used to share one,
                 so typing into either filled both and a validation error from one
                 surfaced under the other. --}}
            <div class="space-y-6">
                <div class="border-t border-gray-100 pt-4">
                    <h3 class="mb-1 text-sm font-semibold text-gray-900">{{ __('Recovery codes') }}</h3>
                    <p class="mb-3 text-sm text-gray-500">{{ __('Regenerate a fresh set (invalidates the old ones). Requires your password.') }}</p>

                    <form wire:submit="regenerateRecoveryCodes" class="max-w-md">
                        <x-ui.form-group for="regeneratePassword" error="regeneratePassword">
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <x-ui.input type="password" id="regeneratePassword" wire:model="regeneratePassword"
                                            placeholder="{{ __('Current password') }}" autocomplete="current-password" />
                                <x-ui.button type="submit" variant="secondary" class="shrink-0"
                                             wire:loading.attr="disabled" wire:target="regenerateRecoveryCodes">
                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden"
                                                  wire:target="regenerateRecoveryCodes" />
                                    {{ __('Regenerate codes') }}
                                </x-ui.button>
                            </div>
                        </x-ui.form-group>
                    </form>
                </div>

                <div class="border-t border-gray-100 pt-4">
                    <h3 class="mb-1 text-sm font-semibold text-red-700">{{ __('Disable two-factor authentication') }}</h3>
                    <p class="mb-3 text-sm text-gray-500">{{ __('Admins are required to keep 2FA enabled; you may be prompted to set it up again.') }}</p>

                    <form wire:submit="disable" class="max-w-md">
                        <x-ui.form-group for="disablePassword" error="disablePassword">
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <x-ui.input type="password" id="disablePassword" wire:model="disablePassword"
                                            placeholder="{{ __('Current password') }}" autocomplete="current-password" />
                                <x-ui.button type="submit" variant="danger" class="shrink-0"
                                             wire:loading.attr="disabled" wire:target="disable">
                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden"
                                                  wire:target="disable" />
                                    {{ __('Disable') }}
                                </x-ui.button>
                            </div>
                        </x-ui.form-group>
                    </form>
                </div>
            </div>
        @endif
    </x-settings.section>
</div>
