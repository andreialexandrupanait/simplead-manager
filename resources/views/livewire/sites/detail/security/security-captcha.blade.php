<div>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])
    @include('livewire.sites.detail.security.partials.protection-sub-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="captcha-saved" />

    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">CAPTCHA Configuration</h3>

        <div class="space-y-4">
            {{-- Provider --}}
            <x-ui.form-group label="CAPTCHA Provider" for="provider">
                <x-ui.select id="provider" wire:model.live="provider">
                    <option value="none">None (disabled)</option>
                    <option value="recaptcha_v2">Google reCAPTCHA v2</option>
                    <option value="recaptcha_v3">Google reCAPTCHA v3</option>
                    <option value="hcaptcha">hCaptcha</option>
                    <option value="turnstile">Cloudflare Turnstile</option>
                </x-ui.select>
            </x-ui.form-group>

            @if($provider !== 'none')
                {{-- API Keys --}}
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-4">
                    <h4 class="text-sm font-medium text-gray-900">API Keys</h4>

                    @if($this->hasExistingKeys)
                        <p class="text-xs text-gray-500">Keys are saved. Leave blank to keep existing keys, or enter new values to replace them.</p>
                    @endif

                    <x-ui.form-group label="Site Key" for="siteKey" error="{{ $errors->first('siteKey') }}">
                        <x-ui.input type="text" id="siteKey" wire:model.live.debounce.500ms="siteKey"
                            placeholder="{{ $this->hasExistingKeys ? '••••••••••••' : 'Enter site key' }}" />
                    </x-ui.form-group>

                    <x-ui.form-group label="Secret Key" for="secretKey" error="{{ $errors->first('secretKey') }}">
                        <x-ui.input type="password" id="secretKey" wire:model.live.debounce.500ms="secretKey"
                            placeholder="{{ $this->hasExistingKeys ? '••••••••••••' : 'Enter secret key' }}" />
                    </x-ui.form-group>
                </div>

                {{-- Forms --}}
                <div class="space-y-3">
                    <h4 class="text-sm font-medium text-gray-900">Enable on Forms</h4>

                    <label class="flex items-center gap-2">
                        <x-ui.checkbox wire:model.live="enableLogin" />
                        <span class="text-sm text-gray-700">Login form</span>
                    </label>

                    <label class="flex items-center gap-2">
                        <x-ui.checkbox wire:model.live="enableRegister" />
                        <span class="text-sm text-gray-700">Registration form</span>
                    </label>

                    <label class="flex items-center gap-2">
                        <x-ui.checkbox wire:model.live="enableResetPassword" />
                        <span class="text-sm text-gray-700">Password reset form</span>
                    </label>

                    <label class="flex items-center gap-2">
                        <x-ui.checkbox wire:model.live="enableComments" />
                        <span class="text-sm text-gray-700">Comment form</span>
                    </label>
                </div>
            @endif

            {{-- Status indicator --}}
            @php
                $setting = $this->captchaSetting;
                $status = $setting?->status;
                $statusColor = $setting?->status_color ?? 'gray';
            @endphp
            @if($status && $status !== \App\Enums\SecuritySettingStatus::NotConfigured)
                <div class="flex items-center gap-1.5 text-xs text-gray-500">
                    <span class="h-2 w-2 rounded-full bg-{{ $statusColor }}-500"></span>
                    {{ $status->label() }}
                    @if($setting?->failed_at)
                        — {{ $setting->failure_reason }}
                    @endif
                </div>
            @endif
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
