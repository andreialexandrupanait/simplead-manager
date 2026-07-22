<div>
    <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ __('Two-Factor Authentication') }}</h2>
    <p class="mt-1 text-sm text-gray-500 mb-8">
        @if($useRecovery)
            {{ __('Enter one of your recovery codes to continue.') }}
        @else
            {{ __('Enter the 6-digit code from your authenticator app to continue.') }}
        @endif
    </p>

    <form wire:submit="submit" class="space-y-5">
        @if(! $useRecovery)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Authentication code') }}</label>
                <x-ui.input type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code"
                    autofocus placeholder="123456" maxlength="6" />
                @error('code')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @else
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Recovery code') }}</label>
                <x-ui.input type="text" wire:model="recoveryCode" autocomplete="one-time-code"
                    autofocus placeholder="XXXXX-XXXXX" />
                @error('recoveryCode')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="submit">{{ __('Verify') }}</span>
            <span wire:loading wire:target="submit">{{ __('Verifying…') }}</span>
        </x-ui.button>
    </form>

    <div class="mt-6 flex items-center justify-between text-sm">
        <button type="button" wire:click="toggleRecovery" class="text-accent-600 hover:underline">
            @if($useRecovery)
                {{ __('Use an authenticator code') }}
            @else
                {{ __('Use a recovery code') }}
            @endif
        </button>

        <button type="button" wire:click="logout" class="text-gray-500 hover:underline">
            {{ __('Log out') }}
        </button>
    </div>
</div>
