<div>
    @include('livewire.settings.partials.settings-tabs')

    <x-ui.flash-alert type="success" key="success" />

    <x-ui.page-header title="Users & Invitations" subtitle="Manage team members and send invitations" />

    {{-- Invite Form --}}
    <x-ui.card class="mt-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Send Invitation</h3>
        <div class="flex items-end gap-3">
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                <x-ui.input type="email" wire:model="inviteEmail" placeholder="colleague@example.com" />
                @error('inviteEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="w-36">
                <label class="block text-xs font-medium text-gray-600 mb-1">Role</label>
                <x-ui.select wire:model="inviteRole">
                    @foreach($roles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <x-ui.button wire:click="sendInvitation" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendInvitation">Send</span>
                <span wire:loading wire:target="sendInvitation">Sending...</span>
            </x-ui.button>
        </div>
    </x-ui.card>

    {{-- Pending Invitations --}}
    @if($pendingInvitations->isNotEmpty())
        <x-ui.card class="mt-6">
            <h3 class="text-sm font-semibold text-gray-900 mb-3">Pending Invitations</h3>
            <div class="divide-y divide-gray-100">
                @foreach($pendingInvitations as $inv)
                    <div class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $inv->email }}</p>
                            <p class="text-xs text-gray-500">
                                {{ ucfirst($inv->role) }} &middot;
                                Invited by {{ $inv->inviter->name }} &middot;
                                @if($inv->isExpired())
                                    <span class="text-red-600">Expired</span>
                                @else
                                    Expires {{ $inv->expires_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="resendInvitation({{ $inv->id }})"
                                    class="text-xs text-purple-600 hover:text-purple-800">Resend</button>
                            <button wire:click="revokeInvitation({{ $inv->id }})"
                                    class="text-xs text-red-600 hover:text-red-800">Revoke</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- Users List --}}
    <x-ui.card class="mt-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Team Members</h3>
        <div class="divide-y divide-gray-100">
            @foreach($users as $user)
                <div class="flex items-center justify-between py-3">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-purple-100 text-sm font-semibold text-purple-700">
                            {{ $user->initials }}
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                    <span class="text-xs text-gray-400">(you)</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $user->email }}</p>
                            @if($user->assignedClients->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mt-0.5">
                                    @foreach($user->assignedClients as $ac)
                                        <span class="inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-xs text-purple-700">{{ $ac->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        @if($user->id !== auth()->id())
                            <x-ui.select wire:change="updateRole({{ $user->id }}, $event.target.value)" class="w-auto text-xs">
                                @foreach($roles as $role)
                                    <option value="{{ $role->value }}" @selected($user->role === $role)>{{ $role->label() }}</option>
                                @endforeach
                            </x-ui.select>
                            @if($clients->isNotEmpty() && !$user->isAdmin())
                                <x-ui.dropdown>
                                    <x-slot:trigger>
                                        <button class="text-xs text-gray-500 hover:text-purple-600">Clients</button>
                                    </x-slot:trigger>
                                    @foreach($clients as $client)
                                        <button wire:click="toggleClientAssignment({{ $user->id }}, {{ $client->id }})"
                                                class="w-full px-4 py-2 text-left text-xs hover:bg-gray-50 flex items-center justify-between">
                                            {{ $client->name }}
                                            @if($user->assignedClients->contains('id', $client->id))
                                                <svg class="h-3.5 w-3.5 text-purple-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                            @endif
                                        </button>
                                    @endforeach
                                </x-ui.dropdown>
                            @endif
                            <button wire:click="confirmDeleteUser({{ $user->id }})"
                                    class="text-xs text-red-600 hover:text-red-800">Remove</button>
                        @else
                            <span class="text-xs text-gray-500">{{ $user->role->label() }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Delete Confirmation Modal --}}
    <x-ui.modal name="delete-user">
        <h2 class="text-lg font-semibold text-gray-900">Remove User</h2>
        <p class="mt-2 text-sm text-gray-600">Are you sure? This will remove the user and reassign their sites to you. This action cannot be undone.</p>
        <div class="mt-4 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-delete-user')">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="deleteUser">Remove</x-ui.button>
        </div>
    </x-ui.modal>
</div>
