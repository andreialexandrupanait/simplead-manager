<div class="max-w-5xl">
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $client ? __('Edit Client') : __('Add Client') }}</h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Contact Information --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">{{ __('Contact Information') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group :label="__('Name')" for="name" error="form.name" :required="true" class="sm:col-span-2">
                    <x-ui.input wire:model="form.name" id="name" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('Email')" for="email" error="form.email">
                    <x-ui.input wire:model="form.email" id="email" type="email" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('Phone')" for="phone" error="form.phone">
                    <x-ui.input wire:model="form.phone" id="phone" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('Website')" for="website" error="form.website" class="sm:col-span-2">
                    <x-ui.input wire:model="form.website" id="website" type="url" placeholder="https://" class="w-full" />
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Company Details --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">{{ __('Company Details') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group :label="__('Company Name')" for="company" error="form.company" class="sm:col-span-2">
                    <x-ui.input wire:model="form.company" id="company" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('VAT Number') . ' (CUI)'" for="vat_number" error="form.vat_number" :hint="__('Romanian CUI, optionally prefixed with RO')">
                    <div class="flex gap-2">
                        <x-ui.input wire:model="form.vat_number" id="vat_number" type="text" class="w-full" placeholder="RO12345678" />
                        <button type="button" wire:click="lookupCui" wire:loading.attr="disabled" wire:target="lookupCui"
                                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                title="{{ __('Fetch company data from ANAF') }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span wire:loading.remove wire:target="lookupCui">{{ __('ANAF') }}</span>
                            <span wire:loading wire:target="lookupCui">...</span>
                        </button>
                    </div>
                </x-ui.form-group>
                <x-ui.form-group :label="__('Registration Number') . ' (Nr. Reg. Com.)'" for="registration_number" error="form.registration_number" :hint="__('Format: J{county}/{number}/{year}')">
                    <x-ui.input wire:model="form.registration_number" id="registration_number" type="text" class="w-full" placeholder="J40/12345/2024" />
                </x-ui.form-group>
                @if($form->vat_payer || $form->company_status)
                    <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
                        @if($form->vat_payer)
                            <x-ui.badge variant="green">{{ __('VAT Payer') }}</x-ui.badge>
                        @endif
                        @if($form->company_status)
                            <x-ui.badge variant="{{ str_contains(strtoupper($form->company_status), 'RADIAT') ? 'red' : 'blue' }}">{{ $form->company_status }}</x-ui.badge>
                        @endif
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- Address --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">{{ __('Address') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group :label="__('Street Address')" for="address" error="form.address" class="sm:col-span-2">
                    <x-ui.input wire:model="form.address" id="address" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('City')" for="city" error="form.city">
                    <x-ui.input wire:model="form.city" id="city" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('County')" for="county" error="form.county">
                    <x-ui.input wire:model="form.county" id="county" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('Postal Code')" for="postal_code" error="form.postal_code">
                    <x-ui.input wire:model="form.postal_code" id="postal_code" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('Country')" for="country" error="form.country">
                    <x-ui.input wire:model="form.country" id="country" type="text" class="w-full" />
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Additional --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">{{ __('Additional') }}</h2>
            <div class="space-y-4">
                <x-ui.form-group :label="__('Status')" for="status" error="form.status">
                    <x-ui.select wire:model="form.status" id="status" class="w-full sm:w-48">
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                        <option value="archived">{{ __('Archived') }}</option>
                    </x-ui.select>
                </x-ui.form-group>
                <x-ui.form-group :label="__('Notes')" for="notes" error="form.notes">
                    <textarea wire:model="form.notes" id="notes" rows="4" class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent"></textarea>
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Logo --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">{{ __('Logo') }}</h2>
            <div class="flex items-start gap-6">
                {{-- Preview --}}
                <div class="flex-shrink-0">
                    @if($logo)
                        <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Logo preview') }}" class="h-20 w-20 rounded-lg border object-contain">
                    @elseif($client?->logo && !$removeLogo)
                        <img src="{{ Storage::disk('public')->url($client->logo) }}" alt="{{ __('Client logo') }}" class="h-20 w-20 rounded-lg border object-contain">
                    @else
                        <div class="flex h-20 w-20 items-center justify-center rounded-lg border-2 border-dashed border-gray-300 bg-gray-50">
                            <svg class="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                    @endif
                </div>
                <div class="flex-1 space-y-2">
                    <x-ui.form-group :label="__('Upload logo')" for="logo" error="logo" hint="PNG, JPG or SVG up to 2MB">
                        <input wire:model="logo" id="logo" type="file" accept="image/*" class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-accent/10 file:px-4 file:py-2 file:text-sm file:font-medium file:text-accent hover:file:bg-accent/20">
                    </x-ui.form-group>
                    @if(($client?->logo && !$removeLogo) || $logo)
                        <button type="button" wire:click="removeLogo" class="text-sm text-red-600 hover:text-red-800">{{ __('Remove logo') }}</button>
                    @endif
                </div>
            </div>
        </x-ui.card>

        {{-- Actions --}}
        <div class="flex justify-end gap-3">
            <a href="{{ $client ? route('clients.show', $client) : route('clients.index') }}">
                <x-ui.button type="button" variant="secondary">{{ __('Cancel') }}</x-ui.button>
            </a>
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $client ? __('Update Client') : __('Create Client') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
            </x-ui.button>
        </div>
    </form>
</div>
