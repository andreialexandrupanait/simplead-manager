<div class="max-w-4xl">
    {{-- Header --}}
    <div class="mb-6 flex items-center gap-4">
        <a href="{{ $client ? route('clients.show', $client) : route('clients.index') }}" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
            <x-icons.arrow-left class="h-5 w-5" />
        </a>
        <h1 class="text-2xl font-semibold text-gray-900">{{ $client ? 'Edit Client' : 'Add Client' }}</h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Contact Information --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Contact Information</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="mb-1 block text-sm font-medium text-gray-700">Name *</label>
                    <x-ui.input wire:model="name" id="name" type="text" class="w-full" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                    <x-ui.input wire:model="email" id="email" type="email" class="w-full" />
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="mb-1 block text-sm font-medium text-gray-700">Phone</label>
                    <x-ui.input wire:model="phone" id="phone" type="text" class="w-full" />
                    @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="website" class="mb-1 block text-sm font-medium text-gray-700">Website</label>
                    <x-ui.input wire:model="website" id="website" type="url" placeholder="https://" class="w-full" />
                    @error('website') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        {{-- Company Details --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Company Details</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="company" class="mb-1 block text-sm font-medium text-gray-700">Company Name</label>
                    <x-ui.input wire:model="company" id="company" type="text" class="w-full" />
                    @error('company') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="vat_number" class="mb-1 block text-sm font-medium text-gray-700">VAT Number</label>
                    <x-ui.input wire:model="vat_number" id="vat_number" type="text" class="w-full" />
                    @error('vat_number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="registration_number" class="mb-1 block text-sm font-medium text-gray-700">Registration Number</label>
                    <x-ui.input wire:model="registration_number" id="registration_number" type="text" class="w-full" />
                    @error('registration_number') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        {{-- Address --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Address</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="address" class="mb-1 block text-sm font-medium text-gray-700">Street Address</label>
                    <x-ui.input wire:model="address" id="address" type="text" class="w-full" />
                    @error('address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="city" class="mb-1 block text-sm font-medium text-gray-700">City</label>
                    <x-ui.input wire:model="city" id="city" type="text" class="w-full" />
                    @error('city') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="country" class="mb-1 block text-sm font-medium text-gray-700">Country</label>
                    <x-ui.input wire:model="country" id="country" type="text" class="w-full" />
                    @error('country') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        {{-- Additional --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">Additional</h2>
            <div class="space-y-4">
                <div>
                    <label for="status" class="mb-1 block text-sm font-medium text-gray-700">Status</label>
                    <x-ui.select wire:model="status" id="status" class="w-full sm:w-48">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="archived">Archived</option>
                    </x-ui.select>
                    @error('status') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="notes" class="mb-1 block text-sm font-medium text-gray-700">Notes</label>
                    <textarea wire:model="notes" id="notes" rows="4" class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition placeholder:text-gray-400 focus:border-accent focus:ring-1 focus:ring-accent"></textarea>
                    @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        {{-- Actions --}}
        <div class="flex justify-end gap-3">
            <a href="{{ $client ? route('clients.show', $client) : route('clients.index') }}">
                <x-ui.button type="button" variant="secondary">Cancel</x-ui.button>
            </a>
            <x-ui.button type="submit">
                {{ $client ? 'Update Client' : 'Create Client' }}
            </x-ui.button>
        </div>
    </form>
</div>
