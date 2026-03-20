<div>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])
    @include('livewire.sites.detail.security.partials.monitoring-sub-tabs', ['site' => $site])

    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="text-sm text-gray-500">
            @if($this->lastSynced)
                Last synced: {{ \Carbon\Carbon::parse($this->lastSynced)->diffForHumans() }}
            @else
                Not yet synced
            @endif
        </div>
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
</div>
