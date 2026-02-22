<div class="max-w-4xl">
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $client ? 'Edit Client' : 'Add Client' }}</h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Contact Information --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Contact Information</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group label="Name" for="name" error="form.name" :required="true" class="sm:col-span-2">
                    <x-ui.input wire:model="form.name" id="name" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group label="Email" for="email" error="form.email">
                    <x-ui.input wire:model="form.email" id="email" type="email" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group label="Phone" for="phone" error="form.phone">
                    <x-ui.input wire:model="form.phone" id="phone" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group label="Website" for="website" error="form.website" class="sm:col-span-2">
                    <x-ui.input wire:model="form.website" id="website" type="url" placeholder="https://" class="w-full" />
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Company Details --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Company Details</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group label="Company Name" for="company" error="form.company" class="sm:col-span-2">
                    <x-ui.input wire:model="form.company" id="company" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('VAT Number') . ' (CUI)'" for="vat_number" error="form.vat_number" :hint="__('Romanian CUI, optionally prefixed with RO')">
                    <x-ui.input wire:model="form.vat_number" id="vat_number" type="text" class="w-full" placeholder="RO12345678" />
                </x-ui.form-group>
                <x-ui.form-group :label="__('Registration Number') . ' (Nr. Reg. Com.)'" for="registration_number" error="form.registration_number" :hint="__('Format: J{county}/{number}/{year}')">
                    <x-ui.input wire:model="form.registration_number" id="registration_number" type="text" class="w-full" placeholder="J40/12345/2024" />
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Address --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Address</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form-group label="Street Address" for="address" error="form.address" class="sm:col-span-2">
                    <x-ui.input wire:model="form.address" id="address" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group label="City" for="city" error="form.city">
                    <x-ui.input wire:model="form.city" id="city" type="text" class="w-full" />
                </x-ui.form-group>
                <x-ui.form-group label="Country" for="country" error="form.country">
                    <x-ui.input wire:model="form.country" id="country" type="text" class="w-full" />
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Additional --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Additional</h2>
            <div class="space-y-4">
                <x-ui.form-group label="Status" for="status" error="form.status">
                    <x-ui.select wire:model="form.status" id="status" class="w-full sm:w-48">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="archived">Archived</option>
                    </x-ui.select>
                </x-ui.form-group>
                <x-ui.form-group label="Notes" for="notes" error="form.notes">
                    <textarea wire:model="form.notes" id="notes" rows="4" class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent"></textarea>
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Actions --}}
        <div class="flex justify-end gap-3">
            <a href="{{ $client ? route('clients.show', $client) : route('clients.index') }}">
                <x-ui.button type="button" variant="secondary">Cancel</x-ui.button>
            </a>
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $client ? 'Update Client' : 'Create Client' }}</span>
                <span wire:loading wire:target="save">Saving...</span>
            </x-ui.button>
        </div>
    </form>
</div>
