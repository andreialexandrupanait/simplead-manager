<div class="max-w-2xl mx-auto">
    {{-- Step Indicator --}}
    <div class="mb-8">
        <div class="flex items-center justify-between">
            @foreach([1 => __('Site URL'), 2 => __('Client'), 3 => __('Plan'), 4 => __('Confirm')] as $num => $label)
                <div class="flex items-center {{ $num < 4 ? 'flex-1' : '' }}">
                    <button
                        wire:click="goToStep({{ $num }})"
                        @if($num > $step) disabled @endif
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-medium transition
                            {{ $step === $num ? 'bg-purple-600 text-white' : ($step > $num ? 'bg-purple-100 text-purple-700 hover:bg-purple-200' : 'bg-gray-100 text-gray-400') }}"
                    >
                        @if($step > $num)
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        @else
                            {{ $num }}
                        @endif
                    </button>
                    <span class="ml-2 text-sm font-medium {{ $step >= $num ? 'text-gray-900' : 'text-gray-400' }}">{{ $label }}</span>
                    @if($num < 4)
                        <div class="mx-4 h-px flex-1 {{ $step > $num ? 'bg-purple-300' : 'bg-gray-200' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Step 1: Site URL --}}
    @if($step === 1)
        <x-ui.card>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Enter your site URL') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __("We'll check that your site is reachable.") }}</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label for="url" class="block text-sm font-medium text-gray-700">{{ __('Site URL') }}</label>
                    <div class="mt-1 flex gap-2">
                        <x-ui.input wire:model.live.debounce.500ms="form.url" id="url" type="url" placeholder="https://example.com" class="flex-1" />
                        <x-ui.button variant="secondary" wire:click="checkConnectivity" wire:loading.attr="disabled" wire:target="checkConnectivity">
                            <span wire:loading.remove wire:target="checkConnectivity">{{ __('Check') }}</span>
                            <span wire:loading wire:target="checkConnectivity">{{ __('Checking...') }}</span>
                        </x-ui.button>
                    </div>
                    @error('form.url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    @if($connectivityStatus === 'ok')
                        <p class="mt-2 flex items-center gap-1 text-sm text-green-600">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            {{ $connectivityMessage }}
                        </p>
                    @elseif($connectivityStatus === 'error')
                        <p class="mt-2 flex items-center gap-1 text-sm text-red-600">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            {{ $connectivityMessage }}
                        </p>
                    @endif
                </div>

                <x-ui.form-group label="{{ __('Site Name') }}" for="name" error="form.name" hint="{{ __('Auto-filled from URL. You can change it.') }}">
                    <x-ui.input wire:model="form.name" id="name" placeholder="{{ __('My Website') }}" />
                </x-ui.form-group>
            </div>

            <div class="mt-6 flex items-center justify-between">
                <a href="{{ route('dashboard') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700" wire:navigate>{{ __('Cancel') }}</a>
                <x-ui.button wire:click="nextStep">{{ __('Next') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Step 2: Client --}}
    @if($step === 2)
        <x-ui.card>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Assign a client') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Optionally assign this site to a client for organization.') }}</p>

            <div class="mt-6 space-y-3">
                {{-- No client option --}}
                <button
                    wire:click="$set('form.clientId', null)"
                    class="w-full rounded-lg border p-3 text-left transition
                        {{ $form->clientId === null ? 'border-purple-300 bg-purple-50 ring-1 ring-purple-300' : 'border-gray-200 hover:border-purple-200 hover:bg-purple-50/50' }}"
                >
                    <div class="text-sm font-medium text-gray-900">{{ __('No client') }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ __('Skip client assignment for now.') }}</div>
                </button>

                {{-- Existing clients --}}
                @foreach($this->clients as $client)
                    <button
                        wire:click="$set('form.clientId', {{ $client->id }})"
                        class="w-full rounded-lg border p-3 text-left transition
                            {{ $form->clientId === $client->id ? 'border-purple-300 bg-purple-50 ring-1 ring-purple-300' : 'border-gray-200 hover:border-purple-200 hover:bg-purple-50/50' }}"
                    >
                        <div class="text-sm font-medium text-gray-900">{{ $client->name }}</div>
                        @if($client->email)
                            <div class="mt-0.5 text-xs text-gray-500">{{ $client->email }}</div>
                        @endif
                    </button>
                @endforeach

                {{-- New client --}}
                @if(!$creatingClient)
                    <button
                        wire:click="$set('creatingClient', true)"
                        class="w-full rounded-lg border border-dashed border-gray-300 p-3 text-center text-sm font-medium text-gray-500 hover:border-purple-300 hover:text-purple-600 transition"
                    >
                        + {{ __('Create new client') }}
                    </button>
                @else
                    <div class="rounded-lg border border-purple-200 bg-purple-50/50 p-4">
                        <h4 class="text-sm font-medium text-gray-900 mb-3">{{ __('New Client') }}</h4>
                        <div class="space-y-3">
                            <x-ui.form-group error="form.newClientName">
                                <x-ui.input wire:model="form.newClientName" placeholder="{{ __('Client name') }}" />
                            </x-ui.form-group>
                            <x-ui.form-group error="form.newClientEmail">
                                <x-ui.input wire:model="form.newClientEmail" type="email" placeholder="{{ __('Email (optional)') }}" />
                            </x-ui.form-group>
                            <div class="flex gap-2">
                                <x-ui.button size="sm" wire:click="createClient">{{ __('Create') }}</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="$set('creatingClient', false)">{{ __('Cancel') }}</x-ui.button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex items-center justify-between">
                <x-ui.button variant="secondary" wire:click="previousStep">{{ __('Back') }}</x-ui.button>
                <x-ui.button wire:click="nextStep">{{ __('Next') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Step 3: Plan --}}
    @if($step === 3)
        <x-ui.card>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Choose a maintenance plan') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Plans determine which monitoring modules are enabled.') }}</p>

            <div class="mt-6 space-y-3">
                @foreach($this->plans as $plan)
                    <button
                        wire:click="$set('form.planId', {{ $plan->id }})"
                        class="w-full rounded-lg border p-4 text-left transition
                            {{ $form->planId === $plan->id ? 'border-purple-300 bg-purple-50 ring-1 ring-purple-300' : 'border-gray-200 hover:border-purple-200 hover:bg-purple-50/50' }}"
                    >
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-900">{{ $plan->name }}</div>
                            @if($plan->is_default)
                                <span class="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700">{{ __('Default') }}</span>
                            @endif
                        </div>
                        @if($plan->description)
                            <p class="mt-1 text-xs text-gray-500">{{ $plan->description }}</p>
                        @endif
                        @if($plan->planModules->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach($plan->planModules as $mod)
                                    @if($mod->is_enabled)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ ucfirst(str_replace('_', ' ', $mod->module_key)) }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </button>
                @endforeach

                @if($this->plans->isEmpty())
                    <div class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-500">
                        {{ __('No plans configured. The site will use default monitoring settings.') }}
                    </div>
                @endif
            </div>

            <div class="mt-6 flex items-center justify-between">
                <x-ui.button variant="secondary" wire:click="previousStep">{{ __('Back') }}</x-ui.button>
                <x-ui.button wire:click="nextStep">{{ __('Next') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Step 4: Confirm --}}
    @if($step === 4)
        <x-ui.card>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Confirm & Create') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Review the details before creating your site.') }}</p>

            <div class="mt-6 space-y-4">
                {{-- Site details --}}
                <div class="rounded-lg border border-gray-200 divide-y">
                    <div class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-gray-500">{{ __('Site URL') }}</span>
                        <span class="text-sm font-medium text-gray-900">{{ $form->url }}</span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-gray-500">{{ __('Name') }}</span>
                        <span class="text-sm font-medium text-gray-900">{{ $form->name }}</span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-gray-500">{{ __('Client') }}</span>
                        <span class="text-sm font-medium text-gray-900">
                            @if($form->clientId)
                                {{ $this->clients->firstWhere('id', $form->clientId)?->name ?? __('Unknown') }}
                            @else
                                <span class="text-gray-400">{{ __('None') }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-gray-500">{{ __('Plan') }}</span>
                        <span class="text-sm font-medium text-gray-900">
                            @if($form->planId)
                                {{ $this->plans->firstWhere('id', $form->planId)?->name ?? __('Unknown') }}
                            @else
                                <span class="text-gray-400">{{ __('Default') }}</span>
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-between">
                <x-ui.button variant="secondary" wire:click="previousStep">{{ __('Back') }}</x-ui.button>
                <x-ui.button wire:click="createSite" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="createSite">{{ __('Create Site') }}</span>
                    <span wire:loading wire:target="createSite">{{ __('Creating...') }}</span>
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
