<x-ui.modal name="configure-monitor" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $monitorId ? __('Edit Monitor') : __('Add Uptime Monitor') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('Configure uptime monitoring for a site.') }}</p>

        <div class="mt-6 space-y-4">
            {{-- URL --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('URL') }}</label>
                <x-ui.input wire:model="form.url" type="url" placeholder="https://example.com" class="mt-1" />
                @error('form.url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Monitor Type') }}</label>
                <x-ui.select wire:model.live="form.type" class="mt-1">
                    <option value="http">HTTP(S)</option>
                    <option value="keyword">{{ __('Keyword') }}</option>
                    <option value="ping">Ping</option>
                </x-ui.select>
            </div>

            {{-- Keyword fields --}}
            @if($form->type === 'keyword')
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Keyword') }}</label>
                    <x-ui.input wire:model="form.keyword" placeholder="{{ __('Expected text on the page') }}" class="mt-1" />
                    @error('form.keyword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex-1">
                        <x-ui.select wire:model="form.keyword_type" class="mt-1">
                            <option value="exists">{{ __('Must exist') }}</option>
                            <option value="not_exists">{{ __('Must not exist') }}</option>
                        </x-ui.select>
                    </div>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="form.keyword_case_sensitive" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                        <span class="text-sm text-gray-700">{{ __('Case sensitive') }}</span>
                    </label>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                {{-- Interval --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Check Interval') }}</label>
                    <x-ui.select wire:model="form.interval_minutes" class="mt-1">
                        <option value="1">{{ __('Every 1 min') }}</option>
                        <option value="3">{{ __('Every 3 min') }}</option>
                        <option value="5">{{ __('Every 5 min') }}</option>
                        <option value="10">{{ __('Every 10 min') }}</option>
                        <option value="15">{{ __('Every 15 min') }}</option>
                        <option value="30">{{ __('Every 30 min') }}</option>
                        <option value="60">{{ __('Every 60 min') }}</option>
                    </x-ui.select>
                </div>

                {{-- Timeout --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Timeout (seconds)') }}</label>
                    <x-ui.input wire:model="form.timeout" type="number" min="5" max="120" class="mt-1" />
                    @error('form.timeout') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            @if($form->type !== 'ping')
                <div class="grid grid-cols-2 gap-4">
                    {{-- HTTP Method --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('HTTP Method') }}</label>
                        <x-ui.select wire:model="form.http_method" class="mt-1">
                            <option value="GET">GET</option>
                            <option value="HEAD">HEAD</option>
                            <option value="POST">POST</option>
                        </x-ui.select>
                    </div>

                    {{-- Follow Redirects --}}
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="form.follow_redirects" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            <span class="text-sm text-gray-700">{{ __('Follow redirects') }}</span>
                        </label>
                    </div>
                </div>
            @endif

            {{-- Alert threshold --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Alert after consecutive failures') }}</label>
                <x-ui.input wire:model="form.alert_after_failures" type="number" min="1" max="10" class="mt-1" />
                @error('form.alert_after_failures') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-configure-monitor')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="submit">
                {{ $monitorId ? __('Update Monitor') : __('Create Monitor') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
