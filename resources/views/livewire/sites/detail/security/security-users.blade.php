<div {!! $hasRunningJobs ? 'wire:poll.1s="checkJobProgress"' : '' !!}>
    <x-ui.page-header title="{{ __('WordPress Users') }}" subtitle="{{ __('View and manage site user accounts') }}" />

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="text-sm text-gray-500">
            @if($this->lastSynced)
                {{ __('Last synced') }}: {{ \Carbon\Carbon::parse($this->lastSynced)->diffForHumans() }}
            @else
                {{ __('Not yet synced') }}
            @endif
        </div>
        @if($site->is_connected)
            <div class="flex items-center gap-2">
                <x-ui.button size="sm" variant="secondary" wire:click="scanForSpam" class="whitespace-nowrap">
                    <span wire:loading.remove wire:target="scanForSpam" class="inline-flex items-center">
                        <x-icons.shield-alert class="mr-1 h-4 w-4" />
                        {{ __('Scan for Spam') }}
                    </span>
                    <span wire:loading wire:target="scanForSpam" class="inline-flex items-center">
                        <svg class="mr-1 h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        {{ __('Scanning...') }}
                    </span>
                </x-ui.button>
                <x-ui.button size="sm" wire:click="openCreateModal">
                    <x-icons.plus class="mr-1 h-4 w-4" />
                    {{ __('Create User') }}
                </x-ui.button>
            </div>
        @endif
    </div>

    {{-- Spam Deletion Progress --}}
    @php $spamJob = $trackedJobs['spam-delete'] ?? null; @endphp

    @if($spamJob && $spamJob['status'] === 'running')
        @php
            $log = $this->progressLog;
            $successCount = collect($log)->filter(fn($e) => str_starts_with($e['message'], 'Deleted '))->count();
            $failCount = collect($log)->filter(fn($e) => str_starts_with($e['message'], 'Failed '))->count();
            $totalCount = $successCount + $failCount;
        @endphp
        <div
            wire:key="job-progress-spam-delete"
            x-data="{ progress: {{ $spamJob['progress'] }}, message: '{{ addslashes($spamJob['message']) }}' }"
            x-effect="progress = {{ $spamJob['progress'] }}; message = '{{ addslashes($spamJob['message']) }}';"
            class="mb-4 rounded-lg bg-purple-50 border border-purple-200 p-4"
        >
            {{-- Header: spinner + title + percentage --}}
            <div class="flex items-center justify-between gap-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                    <x-ui.spinner size="sm" class="text-purple-600 shrink-0" />
                    <span class="text-sm font-medium text-purple-700">{{ __('Deleting spam users...') }}</span>
                </div>
                <span class="text-lg font-bold text-purple-700 shrink-0 tabular-nums" x-show="progress > 0" x-text="progress + '%'" x-cloak></span>
            </div>

            {{-- Stats counters --}}
            @if($totalCount > 0)
                <div class="mt-2 flex items-center gap-3 text-xs">
                    <span class="font-medium text-green-700">{{ $successCount }} {{ __('deleted') }}</span>
                    @if($failCount > 0)
                        <span class="font-medium text-red-600">{{ $failCount }} {{ __('failed') }}</span>
                    @endif
                    <span class="text-purple-500">{{ __('of') }} {{ $successCount + $failCount }} {{ __('processed') }}</span>
                </div>
            @endif

            {{-- Progress bar --}}
            <div class="mt-2.5">
                <div class="w-full overflow-hidden rounded-full bg-purple-100 h-2">
                    <div
                        x-show="progress > 0"
                        class="bg-purple-500 h-2 rounded-full transition-all duration-700 ease-out"
                        :style="'width: ' + progress + '%'"
                    ></div>
                    <div
                        x-show="progress === 0"
                        class="bg-purple-500 h-2 w-1/3 animate-[progress-indeterminate_1.5s_infinite_ease-in-out]"
                    ></div>
                </div>
            </div>

            {{-- Dynamic status message --}}
            <p class="mt-2 text-xs text-purple-600" x-show="progress > 0 && message" x-text="message" x-cloak></p>

            {{-- Activity log --}}
            @if(!empty($log))
                <div class="mt-3 rounded-lg border border-gray-700 bg-gray-900 p-3 max-h-48 overflow-y-auto font-mono text-xs"
                     x-data x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                     x-effect="$el.scrollTop = $el.scrollHeight">
                    @foreach($log as $entry)
                        <div class="text-gray-300">
                            <span class="text-gray-500">[{{ $entry['time'] }}]</span>
                            <span class="{{ str_starts_with($entry['message'], 'Failed') ? 'text-red-400' : 'text-green-400' }}">{{ $entry['message'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif($spamJob && $spamJob['status'] === 'complete')
        @php
            $log = $this->progressLog;
            $successCount = collect($log)->filter(fn($e) => str_starts_with($e['message'], 'Deleted '))->count();
            $failCount = collect($log)->filter(fn($e) => str_starts_with($e['message'], 'Failed '))->count();
        @endphp
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 5000)"
            x-show="show"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="mb-4 rounded-lg bg-green-50 border border-green-200 p-3"
        >
            <div class="flex items-center gap-2.5">
                <x-icons.check-circle class="h-4 w-4 text-green-600 shrink-0" />
                <span class="text-sm font-medium text-green-700">
                    {{ $spamJob['message'] }}
                    @if($successCount > 0 || $failCount > 0)
                        <span class="text-green-600 font-normal">&mdash; {{ $successCount }} {{ __('deleted') }}{{ $failCount > 0 ? ", {$failCount} " . __('failed') : '' }}</span>
                    @endif
                </span>
            </div>
        </div>
    @elseif($spamJob && $spamJob['status'] === 'failed')
        <div
            x-data="{ show: true }"
            x-show="show"
            class="mb-4 rounded-lg bg-red-50 border border-red-200 p-3"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <x-icons.x-circle class="h-4 w-4 text-red-600 shrink-0" />
                    <span class="text-sm font-medium text-red-700">{{ $spamJob['message'] }}</span>
                </div>
                <button @click="show = false" class="text-red-400 hover:text-red-600">
                    <x-icons.x class="h-4 w-4" />
                </button>
            </div>
        </div>
    @endif

    {{-- Spam Detection Results --}}
    @if($showSpamResults)
        @php
            $summary = $this->spamResults['summary'] ?? [];
            $flagged = $this->spamResults['flagged'] ?? [];
            $allIds = collect($flagged)->pluck('wp_user_id')->map(fn ($id) => (string) $id)->values()->toArray();
        @endphp

        <div class="mb-4" x-data="{ selected: [] }">
            <x-ui.card>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                        <x-icons.shield-alert class="h-4 w-4 text-amber-500" />
                        {{ __('Spam Scan Results') }}
                    </h3>
                    <button wire:click="dismissSpamResults" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="{{ __('Dismiss') }}">
                        <x-icons.x class="h-4 w-4" />
                    </button>
                </div>

                <p class="text-sm text-gray-600 mb-3">
                    {{ __('Scanned') }} {{ $summary['total_scanned'] ?? 0 }} {{ __('users, found') }} <strong>{{ $summary['flagged_count'] ?? 0 }}</strong> {{ __('suspicious') }}
                    @if(($summary['highest_score'] ?? 0) > 0)
                        ({{ __('highest score') }}: {{ $summary['highest_score'] }})
                    @endif
                </p>

                @if(empty($flagged))
                    <div class="rounded-lg bg-green-50 border border-green-200 p-3 flex items-center gap-2">
                        <x-icons.check-circle class="h-5 w-5 text-green-600 flex-shrink-0" />
                        <span class="text-sm text-green-800">{{ __('No suspicious users detected. Your site looks clean.') }}</span>
                    </div>
                @else
                    {{-- Bulk actions --}}
                    <div class="mb-3 flex items-center justify-between">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox"
                                class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                x-on:change="selected = $event.target.checked ? {{ json_encode($allIds) }} : []"
                                :checked="selected.length === {{ count($flagged) }} && selected.length > 0"
                            />
                            {{ __('Select All') }} ({{ count($flagged) }})
                        </label>
                        <button
                            x-show="selected.length > 0"
                            x-cloak
                            x-on:click="if (confirm('{{ __('Are you sure you want to delete') }} ' + selected.length + ' {{ __('user(s)? Their content will be reassigned to an admin.') }}')) { $wire.deleteSpamUsers(selected) }"
                            class="inline-flex items-center rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
                            {{ $hasRunningJobs ? 'disabled' : '' }}
                        >
                            <x-icons.trash class="mr-1 h-3.5 w-3.5" />
                            {{ __('Delete Selected') }} (<span x-text="selected.length"></span>)
                        </button>
                    </div>

                    {{-- Flagged users table --}}
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                                    <th class="pb-2 pr-2 w-8"></th>
                                    <th class="pb-2 pr-4">{{ __('User') }}</th>
                                    <th class="pb-2 pr-4">{{ __('Role') }}</th>
                                    <th class="pb-2 pr-4">{{ __('Orders') }}</th>
                                    <th class="pb-2">{{ __('Score') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($flagged as $spamUser)
                                    <tr class="border-t border-gray-100">
                                        <td class="py-2 pr-2 align-top" rowspan="2">
                                            <input type="checkbox"
                                                value="{{ $spamUser['wp_user_id'] }}"
                                                x-model="selected"
                                                class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                            />
                                        </td>
                                        <td class="pt-2 pr-4">
                                            <div class="font-medium text-gray-900">{{ $spamUser['username'] }}</div>
                                            <div class="text-xs text-gray-400">{{ $spamUser['email'] }}</div>
                                        </td>
                                        <td class="pt-2 pr-4 align-top">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700">
                                                {{ ucfirst($spamUser['role']) }}
                                            </span>
                                        </td>
                                        <td class="pt-2 pr-4 align-top text-xs {{ ($spamUser['orders_count'] ?? 0) === 0 ? 'text-red-600 font-medium' : 'text-gray-500' }}">
                                            {{ $spamUser['orders_count'] ?? 0 }}
                                        </td>
                                        <td class="pt-2 align-top">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-bold
                                                {{ $spamUser['score'] >= 8 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">
                                                {{ $spamUser['score'] }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr class="border-0">
                                        <td colspan="4" class="pb-2 text-xs italic text-gray-400">
                                            {{ implode(', ', $spamUser['reasons']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        </div>
    @endif

    {{-- Role Filter Tabs --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <button wire:click="$set('roleFilter', '')"
            class="rounded-full px-3 py-1 text-xs font-medium {{ $roleFilter === '' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ __('All') }} ({{ array_sum($this->roleCounts) }})
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
                title="{{ __('No users found') }}"
                description="{{ __('WordPress users will appear here after the site is synced.') }}"
                icon="users"
            />
        @else
            {{-- Mobile cards --}}
            <div class="md:hidden space-y-2">
                @foreach($users as $user)
                    <div class="rounded-lg border border-gray-200 p-3">
                        {{-- Top row: avatar + username + role badge --}}
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                @if($user->avatar_url)
                                    <img src="{{ $user->avatar_url }}" alt="" class="h-8 w-8 rounded-full flex-shrink-0" />
                                @else
                                    <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-200 text-xs font-medium text-gray-500">
                                        {{ strtoupper(substr($user->username, 0, 1)) }}
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-gray-900 text-sm">{{ $user->username }}</div>
                                    <div class="truncate text-xs text-gray-500">{{ $user->email }}</div>
                                </div>
                            </div>
                            <span class="flex-shrink-0 inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $user->role === 'administrator' ? 'bg-red-50 text-red-700' : ($user->role === 'editor' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-700') }}">
                                {{ ucfirst($user->role) }}
                            </span>
                        </div>

                        {{-- Bottom row: status + last login + actions --}}
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <div class="flex items-center gap-3">
                                @if($user->is_active)
                                    <span class="inline-flex items-center gap-1 text-xs text-green-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> {{ __('Active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                        <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span> {{ __('Inactive') }}
                                    </span>
                                @endif
                                <span class="text-xs {{ ($user->orders_count ?? 0) === 0 ? 'text-red-600 font-medium' : 'text-gray-400' }}">{{ __('Orders') }}: {{ $user->orders_count ?? 0 }}</span>
                                <span class="text-xs text-gray-400">{{ __('Last login') }}: {{ $user->last_login_at?->diffForHumans() ?? __('Never') }}</span>
                            </div>
                            @if($site->is_connected)
                                <div class="flex items-center gap-1">
                                    <button wire:click="openEditModal({{ $user->id }})" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="{{ __('Edit user') }}">
                                        <x-icons.pencil class="h-4 w-4" />
                                    </button>
                                    <button wire:click="confirmDeleteUser({{ $user->id }})" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" title="{{ __('Delete user') }}">
                                        <x-icons.trash class="h-4 w-4" />
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                            <th class="pb-2 pr-4">{{ __('User') }}</th>
                            <th class="pb-2 pr-4 cursor-pointer" wire:click="sort('role')">{{ __('Role') }}</th>
                            <th class="pb-2 pr-4">{{ __('Email') }}</th>
                            <th class="pb-2 pr-4 cursor-pointer" wire:click="sort('last_login_at')">{{ __('Last Login') }}</th>
                            <th class="pb-2 pr-4 cursor-pointer" wire:click="sort('orders_count')">{{ __('Orders') }}</th>
                            <th class="pb-2 pr-4">{{ __('Status') }}</th>
                            @if($site->is_connected)
                                <th class="pb-2 pr-4 text-right">{{ __('Actions') }}</th>
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
                                    {{ $user->last_login_at?->diffForHumans() ?? __('Never') }}
                                </td>
                                <td class="py-2 pr-4 text-xs {{ ($user->orders_count ?? 0) === 0 ? 'text-red-600 font-medium' : 'text-gray-500' }}">
                                    {{ $user->orders_count ?? 0 }}
                                </td>
                                <td class="py-2 pr-4">
                                    @if($user->is_active)
                                        <span class="inline-flex items-center gap-1 text-xs text-green-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> {{ __('Active') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                                            <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span> {{ __('Inactive') }}
                                        </span>
                                    @endif
                                </td>
                                @if($site->is_connected)
                                    <td class="py-2 pr-4 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button wire:click="openEditModal({{ $user->id }})" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="{{ __('Edit user') }}">
                                                <x-icons.pencil class="h-4 w-4" />
                                            </button>
                                            <button wire:click="confirmDeleteUser({{ $user->id }})" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" title="{{ __('Delete user') }}">
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
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Create User') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Create a new WordPress user on') }} {{ $site->name }}.</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Username') }}</label>
                    <x-ui.input wire:model="newUsername" type="text" class="mt-1" placeholder="username" required />
                    @error('newUsername') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
                    <x-ui.input wire:model="newEmail" type="email" class="mt-1" placeholder="user@example.com" required />
                    @error('newEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                    <x-ui.input wire:model="newPassword" type="password" class="mt-1" placeholder="{{ __('Min 8 characters') }}" required />
                    @error('newPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Role') }}</label>
                    <x-ui.select wire:model="newRole" class="mt-1">
                        @foreach($this->availableRoles as $role)
                            <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Display Name') }} <span class="text-gray-400">({{ __('optional') }})</span></label>
                    <x-ui.input wire:model="newDisplayName" type="text" class="mt-1" placeholder="John Doe" />
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-create-user')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Create User') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Edit User Modal --}}
    <x-ui.modal name="edit-user" maxWidth="md">
        <form wire:submit="updateUser">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Edit User') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Update user details for') }} <strong>{{ $editUsername }}</strong>.</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Username') }}</label>
                    <x-ui.input type="text" :value="$editUsername" class="mt-1" disabled />
                    <p class="mt-1 text-xs text-gray-400">{{ __('Usernames cannot be changed in WordPress.') }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
                    <x-ui.input wire:model="editEmail" type="email" class="mt-1" required />
                    @error('editEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Role') }}</label>
                    <x-ui.select wire:model="editRole" class="mt-1">
                        @foreach($this->availableRoles as $role)
                            <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('editRole') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Display Name') }}</label>
                    <x-ui.input wire:model="editDisplayName" type="text" class="mt-1" />
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-edit-user')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save Changes') }}</x-ui.button>
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
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Delete User') }}</h2>
                    <p class="text-sm text-gray-500">{{ __('This action cannot be undone.') }}</p>
                </div>
            </div>

            <p class="text-sm text-gray-700">
                {{ __('Are you sure you want to delete') }} <strong>{{ $deletingUsername }}</strong>? {{ __('All content authored by this user will need to be reassigned or will be deleted.') }}
            </p>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700">{{ __('Reassign content to') }} <span class="text-gray-400">({{ __('optional') }})</span></label>
                <x-ui.select wire:model="reassignTo" class="mt-1">
                    <option value="">{{ __("Don't reassign (delete content)") }}</option>
                    @foreach(\App\Models\SiteUser::where('site_id', $site->id)->where('wp_user_id', '!=', $deletingUserId)->orderBy('username')->get() as $otherUser)
                        <option value="{{ $otherUser->wp_user_id }}">{{ $otherUser->username }} ({{ ucfirst($otherUser->role) }})</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-delete-user')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="deleteUser">{{ __('Delete User') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
