<div>
    {{-- Header --}}
    <x-ui.page-header title="Plugins & Themes" subtitle="Manage installed plugins and themes" />
</div>

<div
    x-data="{
        bulkUpdating: false,
        bulkTotal: 0,
        bulkCompleted: 0,
        bulkFailed: 0,
        bulkType: '',
        async updateAllPlugins() {
            const ids = await $wire.getUpdatablePluginIds();
            if (!ids.length) return;
            this.bulkUpdating = true;
            this.bulkTotal = ids.length;
            this.bulkCompleted = 0;
            this.bulkFailed = 0;
            this.bulkType = 'plugins';
            for (const id of ids) {
                try {
                    const result = await $wire.updateSinglePlugin(id);
                    if (result && !result.success) this.bulkFailed++;
                } catch (e) {
                    this.bulkFailed++;
                }
                this.bulkCompleted++;
            }
            this.bulkUpdating = false;
        },
        async updateAllThemes() {
            const ids = await $wire.getUpdatableThemeIds();
            if (!ids.length) return;
            this.bulkUpdating = true;
            this.bulkTotal = ids.length;
            this.bulkCompleted = 0;
            this.bulkFailed = 0;
            this.bulkType = 'themes';
            for (const id of ids) {
                try {
                    const result = await $wire.updateSingleTheme(id);
                    if (result && !result.success) this.bulkFailed++;
                } catch (e) {
                    this.bulkFailed++;
                }
                this.bulkCompleted++;
            }
            this.bulkUpdating = false;
        },
        autoDismiss(key) {
            setTimeout(() => { $wire.clearResult(key); }, 5000);
        }
    }"
>
    {{-- Indeterminate progress bar styles --}}
    <style>
        @keyframes indeterminate {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(400%); }
        }
        .progress-indeterminate {
            animation: indeterminate 1.5s ease-in-out infinite;
            width: 25%;
        }
    </style>

    <div class="mb-6 flex justify-end">
        <x-ui.button variant="secondary" wire:click="syncNow" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span wire:loading.remove wire:target="syncNow">Sync Now</span>
            <span wire:loading wire:target="syncNow">Syncing...</span>
        </x-ui.button>
    </div>

    {{-- Flash messages --}}
    <x-ui.flash-alert type="success" key="update-success" />
    <x-ui.flash-alert type="error" key="update-error" />
    <x-ui.flash-alert type="info" key="sync-dispatched" />

    {{-- Bulk update progress bar --}}
    <div x-show="bulkUpdating" x-cloak class="mb-4 rounded-lg bg-blue-50 p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-blue-700">
                Updating <span x-text="bulkCompleted"></span> of <span x-text="bulkTotal"></span>
                <span x-text="bulkType"></span>...
            </span>
            <span x-show="bulkFailed > 0" class="text-xs text-red-600" x-text="bulkFailed + ' failed'"></span>
        </div>
        <div class="h-2 w-full rounded-full bg-blue-200 overflow-hidden">
            <div class="h-full rounded-full bg-blue-600 transition-all duration-300"
                 :style="'width: ' + (bulkTotal > 0 ? Math.round((bulkCompleted / bulkTotal) * 100) : 0) + '%'"></div>
        </div>
    </div>

    {{-- Plugin Conflict Warnings --}}
    @if($tab === 'plugins' && $this->activeConflicts->count() > 0)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-semibold text-red-800">
                    {{ $this->activeConflicts->count() }} Plugin Conflict{{ $this->activeConflicts->count() > 1 ? 's' : '' }} Detected
                </h4>
                <x-ui.button variant="secondary" size="sm" wire:click="checkConflictsNow" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="checkConflictsNow">Re-check</span>
                    <span wire:loading wire:target="checkConflictsNow">Checking...</span>
                </x-ui.button>
            </div>
            <div class="space-y-2">
                @foreach($this->activeConflicts as $siteConflict)
                    <div class="flex items-start justify-between gap-3 rounded-lg bg-white/60 p-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-red-900">{{ $siteConflict->plugin_a_slug }}</span>
                                <span class="text-xs text-red-400">&times;</span>
                                <span class="text-sm font-medium text-red-900">{{ $siteConflict->plugin_b_slug }}</span>
                                @if($siteConflict->conflict)
                                    @php
                                        $sevColor = match($siteConflict->conflict->severity ?? '') {
                                            'critical' => 'red',
                                            'high' => 'red',
                                            'medium' => 'yellow',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <x-ui.badge :variant="$sevColor">{{ ucfirst($siteConflict->conflict->severity ?? 'unknown') }}</x-ui.badge>
                                @endif
                            </div>
                            @if($siteConflict->conflict?->description)
                                <p class="mt-1 text-xs text-red-700">{{ $siteConflict->conflict->description }}</p>
                            @endif
                        </div>
                        <button
                            wire:click="dismissConflict({{ $siteConflict->id }})"
                            class="shrink-0 rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-100 transition"
                        >
                            Dismiss
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Abandoned Plugin Warning Banner --}}
    @if($tab === 'plugins' && ($this->abandonedCounts['abandoned'] > 0 || $this->abandonedCounts['closed'] > 0))
        <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="text-sm font-semibold text-yellow-800">Plugin Health Issues</h4>
                    <p class="mt-1 text-xs text-yellow-700">
                        {{ $this->abandonedCounts['abandoned'] }} abandoned and {{ $this->abandonedCounts['closed'] }} closed plugin(s) detected.
                        @if($this->lastAbandonedCheck)
                            <span class="text-yellow-500">Last checked {{ \Carbon\Carbon::parse($this->lastAbandonedCheck)->diffForHumans() }}</span>
                        @endif
                    </p>
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="checkAbandonedNow" wire:loading.attr="disabled">
                    <x-ui.spinner size="xs" class="hidden" wire:loading.class.remove="hidden" wire:target="checkAbandonedNow" />
                    <span wire:loading.remove wire:target="checkAbandonedNow">Check Now</span>
                    <span wire:loading wire:target="checkAbandonedNow">Checking...</span>
                </x-ui.button>
            </div>
        </div>
    @elseif($tab === 'plugins' && !$this->lastAbandonedCheck)
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="text-sm font-medium text-gray-700">Abandoned Plugin Detection</h4>
                    <p class="mt-0.5 text-xs text-gray-500">Check if any installed plugins are abandoned or closed on WordPress.org.</p>
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="checkAbandonedNow" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="checkAbandonedNow">Check Now</span>
                    <span wire:loading wire:target="checkAbandonedNow">Checking...</span>
                </x-ui.button>
            </div>
        </div>
    @endif

    {{-- Tab switcher --}}
    <div class="mb-4 flex items-center gap-1 rounded-lg bg-gray-100 p-1 w-fit">
        <button
            wire:click="setTab('plugins')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === 'plugins' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
        >
            Plugins
            <span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs">{{ $this->pluginCounts['total'] }}</span>
        </button>
        <button
            wire:click="setTab('themes')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === 'themes' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
        >
            Themes
            <span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs">{{ $this->themeCounts['total'] }}</span>
        </button>
        <button
            wire:click="setTab('users')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === 'users' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
        >
            Users
            <span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs">{{ $this->userCount }}</span>
        </button>
    </div>

    <x-ui.card :padding="false">
        {{-- Summary bar + filter + search --}}
        <div class="border-b p-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-4">
                    @if($tab === 'plugins')
                        <span class="text-sm text-gray-600">
                            {{ $this->pluginCounts['active'] }} active,
                            {{ $this->pluginCounts['inactive'] }} inactive,
                            {{ $this->pluginCounts['updates'] }} updates
                        </span>
                        @if($this->pluginCounts['updates'] > 0)
                            <x-ui.button size="sm" x-on:click="updateAllPlugins()" x-bind:disabled="bulkUpdating">
                                <span x-show="!bulkUpdating || bulkType !== 'plugins'">Update All ({{ $this->pluginCounts['updates'] }})</span>
                                <span x-show="bulkUpdating && bulkType === 'plugins'" x-cloak>Updating...</span>
                            </x-ui.button>
                        @endif
                    @elseif($tab === 'themes')
                        <span class="text-sm text-gray-600">
                            {{ $this->themeCounts['active'] }} active,
                            {{ $this->themeCounts['updates'] }} updates
                        </span>
                        @if($this->themeCounts['updates'] > 0)
                            <x-ui.button size="sm" x-on:click="updateAllThemes()" x-bind:disabled="bulkUpdating">
                                <span x-show="!bulkUpdating || bulkType !== 'themes'">Update All ({{ $this->themeCounts['updates'] }})</span>
                                <span x-show="bulkUpdating && bulkType === 'themes'" x-cloak>Updating...</span>
                            </x-ui.button>
                        @endif
                    @else
                        <span class="text-sm text-gray-600">
                            {{ $this->userCount }} user(s)
                        </span>
                    @endif
                </div>

                {{-- Filter pills --}}
                @if($tab !== 'users')
                    @php
                        if ($tab === 'plugins') {
                            $filters = ['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'updates' => 'Updates'];
                            if ($this->pluginCounts['issues'] > 0) {
                                $filters['abandoned'] = 'Issues (' . $this->pluginCounts['issues'] . ')';
                            }
                        } else {
                            $filters = ['all' => 'All', 'active' => 'Active', 'updates' => 'Updates'];
                        }
                    @endphp
                    <div class="flex rounded-lg bg-gray-100 p-1">
                        @foreach($filters as $key => $label)
                            <button
                                wire:click="setFilter('{{ $key }}')"
                                class="rounded-md px-3 py-1 text-xs font-medium transition
                                    {{ $filter === $key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                @endif

                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search..."
                    class="ml-auto w-48"
                >
            </div>
        </div>

        {{-- Plugin rows --}}
        @if($tab === 'plugins')
            <div class="divide-y">
                @forelse($this->plugins as $plugin)
                    @php $resultKey = 'plugin_' . $plugin->id; @endphp
                    <div
                        class="flex items-center justify-between px-4 py-3 transition-colors hover:bg-gray-50"
                        wire:loading.class="!bg-blue-50" wire:target="updatePlugin({{ $plugin->id }}), activatePlugin({{ $plugin->id }}), deactivatePlugin({{ $plugin->id }})"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900">{{ $plugin->name }}</span>
                                <x-ui.badge :variant="$plugin->is_active ? 'green' : 'gray'">
                                    {{ $plugin->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                                @if($plugin->auto_update)
                                    <x-ui.badge variant="purple">Auto</x-ui.badge>
                                @endif
                                @if($plugin->is_closed)
                                    <x-ui.badge variant="red">Closed</x-ui.badge>
                                @elseif($plugin->is_abandoned)
                                    <x-ui.badge variant="yellow">Abandoned</x-ui.badge>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                {{ $plugin->author ? 'by ' . $plugin->author . ' — ' : '' }}v{{ $plugin->version }}
                            </div>

                            {{-- Inline progress bar during update --}}
                            <div wire:loading wire:target="updatePlugin({{ $plugin->id }})">
                                <div class="mt-1.5 h-1 w-32 rounded-full bg-blue-200 overflow-hidden">
                                    <div class="h-full rounded-full bg-blue-500 progress-indeterminate"></div>
                                </div>
                            </div>

                            {{-- Inline result message --}}
                            @if(isset($updateResults[$resultKey]))
                                <div
                                    x-data="{ show: true }"
                                    x-init="autoDismiss('{{ $resultKey }}')"
                                    x-show="show"
                                    class="mt-1 text-xs font-medium {{ $updateResults[$resultKey]['success'] ? 'text-green-600' : 'text-red-600' }}"
                                >
                                    {{ $updateResults[$resultKey]['message'] }}
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 ml-4">
                            {{-- Action buttons --}}
                            @if($plugin->is_active)
                                <button
                                    wire:click="deactivatePlugin({{ $plugin->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="deactivatePlugin({{ $plugin->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-yellow-700 hover:bg-yellow-50 transition"
                                >
                                    <span wire:loading.remove wire:target="deactivatePlugin({{ $plugin->id }})">Deactivate</span>
                                    <span wire:loading wire:target="deactivatePlugin({{ $plugin->id }})">...</span>
                                </button>
                            @else
                                <button
                                    wire:click="activatePlugin({{ $plugin->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="activatePlugin({{ $plugin->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-50 transition"
                                >
                                    <span wire:loading.remove wire:target="activatePlugin({{ $plugin->id }})">Activate</span>
                                    <span wire:loading wire:target="activatePlugin({{ $plugin->id }})">...</span>
                                </button>
                                <button
                                    wire:click="confirmDeletePlugin({{ $plugin->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                >
                                    Delete
                                </button>
                            @endif

                            {{-- Update button --}}
                            @if($plugin->has_update)
                                <span class="text-xs text-yellow-600">{{ $plugin->version }} &rarr; {{ $plugin->update_version }}</span>
                                <x-ui.button size="sm" wire:click="updatePlugin({{ $plugin->id }})" wire:loading.attr="disabled" wire:target="updatePlugin({{ $plugin->id }})">
                                    <span wire:loading.remove wire:target="updatePlugin({{ $plugin->id }})">Update</span>
                                    <span wire:loading wire:target="updatePlugin({{ $plugin->id }})">Updating...</span>
                                </x-ui.button>
                            @else
                                <span class="text-xs text-green-600">Up to date</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">
                        No plugins found{{ $search ? ' matching "' . $search . '"' : '' }}.
                    </div>
                @endforelse
            </div>
        @endif

        {{-- Theme rows --}}
        @if($tab === 'themes')
            <div class="divide-y">
                @forelse($this->themes as $theme)
                    @php $resultKey = 'theme_' . $theme->id; @endphp
                    <div
                        class="flex items-center justify-between px-4 py-3 transition-colors hover:bg-gray-50"
                        wire:loading.class="!bg-blue-50" wire:target="updateTheme({{ $theme->id }}), activateTheme({{ $theme->id }})"
                    >
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            @if($theme->screenshot_url)
                                <img src="{{ $theme->screenshot_url }}" alt="" class="h-10 w-16 rounded object-cover ring-1 ring-gray-200">
                            @else
                                <div class="h-10 w-16 rounded bg-gray-100 flex items-center justify-center">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900">{{ $theme->name }}</span>
                                    <x-ui.badge :variant="$theme->is_active ? 'green' : 'gray'">
                                        {{ $theme->is_active ? 'Active' : 'Inactive' }}
                                    </x-ui.badge>
                                    @if($theme->is_child_theme)
                                        <x-ui.badge variant="purple">Child</x-ui.badge>
                                    @endif
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500">
                                    {{ $theme->author ? 'by ' . $theme->author . ' — ' : '' }}v{{ $theme->version }}
                                    @if($theme->parent_theme)
                                        <span class="ml-1">(Parent: {{ $theme->parent_theme }})</span>
                                    @endif
                                </div>

                                {{-- Inline progress bar during update --}}
                                <div wire:loading wire:target="updateTheme({{ $theme->id }})">
                                    <div class="mt-1.5 h-1 w-32 rounded-full bg-blue-200 overflow-hidden">
                                        <div class="h-full rounded-full bg-blue-500 progress-indeterminate"></div>
                                    </div>
                                </div>

                                {{-- Inline result message --}}
                                @if(isset($updateResults[$resultKey]))
                                    <div
                                        x-data="{ show: true }"
                                        x-init="autoDismiss('{{ $resultKey }}')"
                                        x-show="show"
                                        class="mt-1 text-xs font-medium {{ $updateResults[$resultKey]['success'] ? 'text-green-600' : 'text-red-600' }}"
                                    >
                                        {{ $updateResults[$resultKey]['message'] }}
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2 ml-4">
                            {{-- Action buttons --}}
                            @if(!$theme->is_active)
                                <button
                                    wire:click="activateTheme({{ $theme->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="activateTheme({{ $theme->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-50 transition"
                                >
                                    <span wire:loading.remove wire:target="activateTheme({{ $theme->id }})">Activate</span>
                                    <span wire:loading wire:target="activateTheme({{ $theme->id }})">...</span>
                                </button>
                                <button
                                    wire:click="confirmDeleteTheme({{ $theme->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                >
                                    Delete
                                </button>
                            @endif

                            {{-- Update button --}}
                            @if($theme->has_update)
                                <span class="text-xs text-yellow-600">{{ $theme->version }} &rarr; {{ $theme->update_version }}</span>
                                <x-ui.button size="sm" wire:click="updateTheme({{ $theme->id }})" wire:loading.attr="disabled" wire:target="updateTheme({{ $theme->id }})">
                                    <span wire:loading.remove wire:target="updateTheme({{ $theme->id }})">Update</span>
                                    <span wire:loading wire:target="updateTheme({{ $theme->id }})">Updating...</span>
                                </x-ui.button>
                            @else
                                <span class="text-xs text-green-600">Up to date</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">
                        No themes found{{ $search ? ' matching "' . $search . '"' : '' }}.
                    </div>
                @endforelse
            </div>
        @endif

        {{-- Users tab --}}
        @if($tab === 'users')
            <div class="divide-y">
                @forelse($this->users as $user)
                    <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <img src="{{ $user->avatar_url }}" alt="" class="h-10 w-10 rounded-full ring-1 ring-gray-200 bg-gray-100">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900">{{ $user->display_name ?: $user->username }}</span>
                                    @php
                                        $roleColors = [
                                            'administrator' => 'purple',
                                            'editor' => 'blue',
                                            'author' => 'green',
                                            'contributor' => 'yellow',
                                            'subscriber' => 'gray',
                                        ];
                                        $variant = $roleColors[$user->role] ?? 'gray';
                                    @endphp
                                    <x-ui.badge :variant="$variant">{{ ucfirst($user->role ?: 'none') }}</x-ui.badge>
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500">
                                    {{ $user->username }}
                                    @if($user->email)
                                        <span class="mx-1">&middot;</span>
                                        {{ $user->email }}
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 ml-4 text-xs text-gray-500">
                            <div class="text-right">
                                <div>{{ $user->posts_count }} {{ Str::plural('post', $user->posts_count) }}</div>
                                @if($user->registered_at)
                                    <div class="text-gray-400">Joined {{ $user->registered_at->format('M j, Y') }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">
                        No users found{{ $search ? ' matching "' . $search . '"' : '' }}.
                        @if(!$search)
                            <span class="block mt-1 text-gray-400">Sync the site to fetch user data.</span>
                        @endif
                    </div>
                @endforelse
            </div>
        @endif
    </x-ui.card>

    {{-- Last synced --}}
    @if($site->last_synced_at)
        <p class="mt-3 text-xs text-gray-400 text-right">
            Last synced {{ $site->last_synced_at->diffForHumans() }}
        </p>
    @endif

    {{-- Delete Plugin Confirmation Modal --}}
    <x-ui.modal name="confirm-delete-plugin" maxWidth="sm">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">Delete Plugin</h3>
            <p class="mt-2 text-sm text-gray-600">
                Are you sure you want to delete this plugin? This action cannot be undone.
            </p>
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-confirm-delete-plugin')">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="deletePlugin" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deletePlugin">Delete Plugin</span>
                    <span wire:loading wire:target="deletePlugin">Deleting...</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Delete Theme Confirmation Modal --}}
    <x-ui.modal name="confirm-delete-theme" maxWidth="sm">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">Delete Theme</h3>
            <p class="mt-2 text-sm text-gray-600">
                Are you sure you want to delete this theme? This action cannot be undone.
            </p>
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-confirm-delete-theme')">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteTheme" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deleteTheme">Delete Theme</span>
                    <span wire:loading wire:target="deleteTheme">Deleting...</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
