<div>
    <x-ui.page-header title="WordPress Users" subtitle="View and manage site user accounts" />

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="text-sm text-gray-500">
            @if($this->lastSynced)
                Last synced: {{ \Carbon\Carbon::parse($this->lastSynced)->diffForHumans() }}
            @else
                Not yet synced
            @endif
        </div>
        @if($site->is_connected)
            <x-ui.button size="sm" wire:click="openCreateModal">
                <x-icons.plus class="mr-1 h-4 w-4" />
                Create User
            </x-ui.button>
        @endif
    </div>

    {{-- Role Filter Tabs --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <button wire:click="$set('roleFilter', '')"
            class="rounded-full px-3 py-1 text-xs font-medium {{ $roleFilter === '' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            All ({{ array_sum($this->roleCounts) }})
        </button>
        @foreach($this->roleCounts as $role => $count)
            <button wire:click="$set('roleFilter', '{{ $role }}')"
                class="rounded-full px-3 py-1 text-xs font-medium {{ $roleFilter === $role ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ ucfirst($role) }} ({{ $count }})
            </button>
        @endforeach
    </div>

    {{-- Users Table --}}
    <x-ui.card>
        @if($users->isEmpty())
            <x-ui.empty-state
                title="No users found"
                description="WordPress users will appear here after the site is synced."
                icon="users"
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                            <th class="pb-2 pr-4">User</th>
                            <th class="pb-2 pr-4 cursor-pointer" wire:click="sort('role')">Role</th>
                            <th class="pb-2 pr-4">Email</th>
                            <th class="pb-2 pr-4 cursor-pointer" wire:click="sort('last_login_at')">Last Login</th>
                            <th class="pb-2 pr-4">Status</th>
                            @if($site->is_connected)
                                <th class="pb-2 pr-4 text-right">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($users as $user)
                            <tr>
                                <td class="py-2 pr-4">
                                    <div class="flex items-center gap-2">
                                        @if($user->avatar_url)
                                            <img src="{{ $user->avatar_url }}" alt="" class="h-6 w-6 rounded-full" />
                                        @else
                                            <div class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-200 text-xs font-medium text-gray-500">
                                                {{ strtoupper(substr($user->username, 0, 1)) }}
                                            </div>
                                        @endif
                                        <span class="font-medium text-gray-900">{{ $user->username }}</span>
                                    </div>
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $user->role === 'administrator' ? 'bg-red-50 text-red-700' : ($user->role === 'editor' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-700') }}">
                                        {{ ucfirst($user->role) }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-gray-500">{{ $user->email }}</td>
                                <td class="py-2 pr-4 text-xs text-gray-500">
                                    {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="py-2 pr-4">
                                    @if($user->is_active)
                                        <span class="inline-flex items-center gap-1 text-xs text-green-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                            <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span> Inactive
                                        </span>
                                    @endif
                                </td>
                                @if($site->is_connected)
                                    <td class="py-2 pr-4 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button wire:click="openEditModal({{ $user->id }})" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Edit user">
                                                <x-icons.pencil class="h-4 w-4" />
                                            </button>
                                            <button wire:click="confirmDeleteUser({{ $user->id }})" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Delete user">
                                                <x-icons.trash class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($users->hasPages())
                <div class="mt-4">
                    {{ $users->links() }}
                </div>
            @endif
        @endif
    </x-ui.card>

    {{-- Create User Modal --}}
    <x-ui.modal name="create-user" maxWidth="md">
        <form wire:submit="createUser">
            <h2 class="text-lg font-semibold text-gray-900">Create User</h2>
            <p class="mt-1 text-sm text-gray-500">Create a new WordPress user on {{ $site->name }}.</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Username</label>
                    <x-ui.input wire:model="newUsername" type="text" class="mt-1" placeholder="username" required />
                    @error('newUsername') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Email</label>
                    <x-ui.input wire:model="newEmail" type="email" class="mt-1" placeholder="user@example.com" required />
                    @error('newEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Password</label>
                    <x-ui.input wire:model="newPassword" type="password" class="mt-1" placeholder="Min 8 characters" required />
                    @error('newPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Role</label>
                    <x-ui.select wire:model="newRole" class="mt-1">
                        @foreach($this->availableRoles as $role)
                            <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Display Name <span class="text-gray-400">(optional)</span></label>
                    <x-ui.input wire:model="newDisplayName" type="text" class="mt-1" placeholder="John Doe" />
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-create-user')">Cancel</x-ui.button>
                <x-ui.button type="submit">Create User</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Edit User Modal --}}
    <x-ui.modal name="edit-user" maxWidth="md">
        <form wire:submit="updateUser">
            <h2 class="text-lg font-semibold text-gray-900">Edit User</h2>
            <p class="mt-1 text-sm text-gray-500">Update user details for <strong>{{ $editUsername }}</strong>.</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Username</label>
                    <x-ui.input type="text" :value="$editUsername" class="mt-1" disabled />
                    <p class="mt-1 text-xs text-gray-400">Usernames cannot be changed in WordPress.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Email</label>
                    <x-ui.input wire:model="editEmail" type="email" class="mt-1" required />
                    @error('editEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Role</label>
                    <x-ui.select wire:model="editRole" class="mt-1">
                        @foreach($this->availableRoles as $role)
                            <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('editRole') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Display Name</label>
                    <x-ui.input wire:model="editDisplayName" type="text" class="mt-1" />
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-edit-user')">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete User Modal --}}
    <x-ui.modal name="delete-user" maxWidth="md">
        <div>
            <div class="flex items-center gap-3 mb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-red-100">
                    <x-icons.trash class="h-5 w-5 text-red-600" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Delete User</h2>
                    <p class="text-sm text-gray-500">This action cannot be undone.</p>
                </div>
            </div>

            <p class="text-sm text-gray-700">
                Are you sure you want to delete <strong>{{ $deletingUsername }}</strong>? All content authored by this user will need to be reassigned or will be deleted.
            </p>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700">Reassign content to <span class="text-gray-400">(optional)</span></label>
                <x-ui.select wire:model="reassignTo" class="mt-1">
                    <option value="">Don't reassign (delete content)</option>
                    @foreach(\App\Models\SiteUser::where('site_id', $site->id)->where('wp_user_id', '!=', $deletingUserId)->orderBy('username')->get() as $otherUser)
                        <option value="{{ $otherUser->wp_user_id }}">{{ $otherUser->username }} ({{ ucfirst($otherUser->role) }})</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-delete-user')">Cancel</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="deleteUser">Delete User</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
