<div>
    @include('livewire.settings.partials.settings-tabs')

    @if(session('profile-saved'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">Profile updated successfully.</div>
    @endif
    @if(session('password-changed'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">Password changed successfully.</div>
    @endif
    @if(session('sessions-cleared'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">Other sessions have been logged out.</div>
    @endif

    <div class="space-y-6 max-w-2xl">
        {{-- Profile Section --}}
        <form wire:submit="saveProfile">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">Profile Information</h3>
                <div class="space-y-4">
                    {{-- Avatar --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Avatar</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-purple-100 text-lg font-bold text-purple-600 overflow-hidden">
                                @if(Auth::user()->avatar_path)
                                    <img src="{{ Storage::url(Auth::user()->avatar_path) }}" alt="" class="h-full w-full object-cover">
                                @else
                                    {{ Auth::user()->initials }}
                                @endif
                            </div>
                            <div>
                                <input type="file" wire:model="avatar" accept="image/*" class="text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-purple-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-purple-700 hover:file:bg-purple-100">
                                @error('avatar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Name</label>
                        <x-ui.input wire:model="name" class="mt-1" />
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <x-ui.input wire:model="email" type="email" class="mt-1" />
                        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Timezone</label>
                        <x-ui.select wire:model="timezone" class="mt-1">
                            @foreach(timezone_identifiers_list() as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button type="submit">Save Profile</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        {{-- Password Section --}}
        <form wire:submit="changePassword">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">Change Password</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Current Password</label>
                        <x-ui.input wire:model="currentPassword" type="password" class="mt-1" />
                        @error('currentPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">New Password</label>
                        <x-ui.input wire:model="newPassword" type="password" class="mt-1" />
                        @error('newPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                        <x-ui.input wire:model="newPasswordConfirmation" type="password" class="mt-1" />
                        @error('newPasswordConfirmation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button type="submit">Change Password</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        {{-- Two-Factor Authentication --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-2">Two-Factor Authentication</h3>
            <p class="text-sm text-gray-500 mb-4">Add an additional layer of security to your account.</p>
            <div class="flex items-center gap-3">
                @if(Auth::user()->two_factor_enabled)
                    <x-ui.badge variant="green">Enabled</x-ui.badge>
                @else
                    <x-ui.badge variant="gray">Not enabled</x-ui.badge>
                @endif
                <p class="text-xs text-gray-400">Two-factor authentication setup coming soon.</p>
            </div>
        </x-ui.card>

        {{-- Active Sessions --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Active Sessions</h3>
                    <p class="text-sm text-gray-500">Manage your active sessions across devices.</p>
                </div>
                <x-ui.button variant="secondary" wire:click="logoutOtherSessions" wire:confirm="Log out all other sessions?">
                    Log Out Others
                </x-ui.button>
            </div>

            <div class="divide-y divide-gray-100">
                @foreach($this->sessions as $session)
                    <div class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">
                                {{ Str::limit($session->user_agent, 60) }}
                                @if($session->is_current)
                                    <x-ui.badge variant="green">Current</x-ui.badge>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $session->ip_address }} &middot; {{ $session->last_activity }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- Danger Zone --}}
        <x-ui.card class="border-red-200 ring-red-100">
            <h3 class="text-base font-semibold text-red-600 mb-2">Delete Account</h3>
            <p class="text-sm text-gray-500 mb-4">Permanently delete your account and all associated data. This action cannot be undone.</p>
            <x-ui.button variant="danger" wire:click="deleteAccount" wire:confirm="Are you sure you want to delete your account? This cannot be undone.">
                Delete Account
            </x-ui.button>
        </x-ui.card>
    </div>
</div>
