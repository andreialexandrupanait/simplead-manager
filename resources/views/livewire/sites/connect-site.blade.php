<div class="mx-auto max-w-3xl">
    <x-ui.page-header
        title="{{ __('Connect a site') }}"
        subtitle="{{ __('One screen: paste the URL, we check what is there, we propose a profile, you confirm.') }}"
    />

    {{-- 1 + 2. URL și credențiale --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-1">{{ __('Site-ul') }}</h3>
        <p class="text-[13px] text-gray-500 mb-4">
            {{ __('Install the "SimpleAd Manager Connector" plugin on the site and copy the key and secret from there.') }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700">{{ __('URL') }}</label>
                <x-ui.input wire:model.blur="url" type="url" placeholder="https://exemplu.ro" class="mt-1" />
                @error('url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700">{{ __('Nume') }}</label>
                <x-ui.input wire:model="name" class="mt-1" />
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Cheie API') }}</label>
                <x-ui.input wire:model="apiKey" class="mt-1" />
                @error('apiKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Secret API') }}</label>
                <x-ui.input wire:model="apiSecret" type="password" class="mt-1" />
                @error('apiSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <x-ui.button variant="secondary" size="sm" wire:click="detect" wire:loading.attr="disabled" wire:target="detect">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="detect" />
                {{ __('Check and detect') }}
            </x-ui.button>

            @if($detectionStatus === 'error')
                <span class="text-[13px] text-red-600">{{ $detectionMessage }}</span>
            @endif
        </div>
    </x-ui.card>

    {{-- 3 + 4. Ce am găsit și ce propunem --}}
    @if($detectionStatus === 'ok')
        <x-ui.card class="mb-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('What we found') }}</h3>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.info-row label="WordPress">{{ $detected['wp_version'] ?? '—' }}</x-ui.info-row>
                <x-ui.info-row label="PHP">{{ $detected['php_version'] ?? '—' }}</x-ui.info-row>
                <x-ui.info-row label="{{ __('Conector') }}">{{ $detected['connector_version'] ?? '—' }}</x-ui.info-row>
                <x-ui.info-row label="{{ __('Pluginuri') }}">{{ (string) ($detected['plugin_count'] ?? 0) }}</x-ui.info-row>
                <x-ui.info-row label="WooCommerce">{{ ($detected['has_woocommerce'] ?? false) ? __('da') : __('nu') }}</x-ui.info-row>
                <x-ui.info-row label="{{ __('Plugin formulare') }}">{{ $detected['form_plugin'] ?? __('niciunul detectat') }}</x-ui.info-row>
                @if($detected['is_multisite'] ?? false)
                    <x-ui.info-row label="Multisite">{{ __('da') }}</x-ui.info-row>
                @endif
            </div>

            <h4 class="mt-6 text-sm font-medium text-gray-900">{{ __('Profilul propus') }}</h4>
            <ul class="mt-2 space-y-1 text-[13px] text-gray-600 list-disc pl-5">
                <li>{{ __('The "Standard SimpleAD" preset pack, applied on connection') }}
                    @if($detected['has_woocommerce'] ?? false) <span class="text-gray-500">({{ __('inclusiv grupul WooCommerce') }})</span> @endif
                </li>
                <li>{{ __('Key URLs: the home page now, plus the top Search Console pages once data arrives') }}</li>
                <li>{{ __('Baseline security scan and first backup, right after connecting') }}</li>
                @if(!empty($detected['risky_plugins']))
                    <li>
                        {{ __('Risk list (no automatic updates):') }}
                        <span class="text-gray-900">{{ implode(', ', $detected['risky_plugins']) }}</span>
                    </li>
                @else
                    <li>{{ __('Risk list: no plugin in the sensitive categories') }}</li>
                @endif
            </ul>
        </x-ui.card>

        {{-- 5. Confirmi sau ajustezi --}}
        <x-ui.card class="mb-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Adjust') }}</h3>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Client') }}</label>
                    <x-ui.select wire:model="clientId" class="mt-1">
                        <option value="">{{ __('No client') }}</option>
                        @foreach($this->clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Plan') }}</label>
                    <x-ui.select wire:model="planId" class="mt-1">
                        @foreach($this->plans as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </div>

            <div class="mt-5">
                <x-ui.button variant="primary" wire:click="connect" wire:loading.attr="disabled" wire:target="connect">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="connect" />
                    {{ __('Connect the site') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
