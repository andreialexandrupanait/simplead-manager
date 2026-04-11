<div
    {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}
    x-data="{
        selected: [],
        bulkAction: '',
        bulkUpdating: false,
        bulkTotal: 0,
        bulkCompleted: 0,
        bulkFailed: 0,
        bulkType: '',
        bulkSummary: null,
        async updateAllPlugins() {
            const ids = await $wire.getUpdatablePluginIds();
            if (!ids.length) return;
            this.bulkUpdating = true;
            this.bulkTotal = ids.length;
            this.bulkCompleted = 0;
            this.bulkFailed = 0;
            this.bulkType = 'plugins';
            const result = await $wire.bulkUpdatePlugins(ids);
            this.bulkCompleted = (result.success || 0) + (result.failed || 0);
            this.bulkFailed = result.failed || 0;
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
            const result = await $wire.bulkUpdateThemes(ids);
            this.bulkCompleted = (result.success || 0) + (result.failed || 0);
            this.bulkFailed = result.failed || 0;
            this.bulkUpdating = false;
        },
        async applyBulkAction(tab) {
            if (!this.selected.length || !this.bulkAction) return;
            const action = this.bulkAction;
            if (action === 'delete' && !confirm('Delete ' + this.selected.length + ' selected item(s)? This cannot be undone.')) return;
            this.bulkUpdating = true;
            this.bulkSummary = null;
            this.bulkTotal = this.selected.length;
            this.bulkCompleted = 0;
            this.bulkFailed = 0;
            this.bulkType = action;
            const ids = [...this.selected];
            const errors = [];

            if (action === 'update') {
                // Batch update — single API call, no rate limit issue
                try {
                    const result = tab === 'plugins'
                        ? await $wire.bulkUpdatePlugins(ids)
                        : await $wire.bulkUpdateThemes(ids);
                    this.bulkCompleted = ids.length;
                    this.bulkFailed = result.failed || 0;
                    if (result.error) errors.push(result.error);
                } catch (e) {
                    this.bulkCompleted = ids.length;
                    this.bulkFailed = ids.length;
                    errors.push(e.message || 'Update request failed');
                }
            } else {
                // One-by-one for activate/deactivate/delete
                for (const id of ids) {
                    try {
                        let result;
                        if (tab === 'plugins') {
                            if (action === 'activate') result = await $wire.activatePlugin(id);
                            else if (action === 'deactivate') result = await $wire.deactivatePlugin(id);
                            else if (action === 'delete') result = await $wire.deletePluginDirect(id);
                        } else {
                            if (action === 'activate') result = await $wire.activateTheme(id);
                            else if (action === 'delete') result = await $wire.deleteThemeDirect(id);
                        }
                        if (result && result.success === false) {
                            this.bulkFailed++;
                            errors.push((result.name || 'Item') + ': ' + (result.message || 'Failed'));
                        }
                    } catch (e) {
                        this.bulkFailed++;
                        errors.push(e.message || 'Request failed');
                    }
                    this.bulkCompleted++;
                    if (action === 'delete' && this.bulkCompleted < ids.length) {
                        await new Promise(r => setTimeout(r, 3000));
                    }
                }
            }

            const summary = {
                action: action,
                success: this.bulkTotal - this.bulkFailed,
                failed: this.bulkFailed,
                errors: errors
            };

            this.selected = [];
            this.bulkAction = '';
            this.bulkUpdating = false;
            this.bulkSummary = summary;
            await $wire.syncNow();
        },
        autoDismiss(key) {
            const result = $wire.updateResults[key];
            if (result && result.success) {
                setTimeout(() => { $wire.clearResult(key); }, 5000);
            }
        }
    }"
    x-on:bulk-selection-reset.window="selected = []; bulkAction = ''"
>
    @if(!$embedded)
    {{-- Full page header with quick actions --}}
    <x-ui.page-header title="{{ __('Plugins & Themes') }}" subtitle="{{ __('Manage installed plugins and themes') }}">
        <x-slot:actions>
            <x-ui.wp-admin-button :site="$site" />
            <x-ui.button variant="secondary" wire:click="quickBackup" wire:loading.attr="disabled" wire:target="quickBackup">
                <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                </svg>
                <span wire:loading.remove wire:target="quickBackup">{{ __('Backup Now') }}</span>
                <span wire:loading wire:target="quickBackup">{{ __('Starting...') }}</span>
            </x-ui.button>
            <x-ui.button variant="secondary" wire:click="syncNow" wire:loading.attr="disabled">
                <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span wire:loading.remove wire:target="syncNow">{{ __('Sync Now') }}</span>
                <span wire:loading wire:target="syncNow">{{ __('Syncing...') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    @endif

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

    @if(!$embedded)
    {{-- Flash messages --}}
    <x-ui.flash-alert type="success" key="update-success" />
    <x-ui.flash-alert type="error" key="update-error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="sync" :jobs="$trackedJobs" title="{{ __('Syncing site data...') }}" />
    <x-ui.job-progress job-key="backup" :jobs="$trackedJobs" title="{{ __('Creating backup...') }}" />
    @endif

    @if(!$embedded)
    {{-- Bulk update progress bar --}}
    <div x-show="bulkUpdating" x-cloak class="mb-4 rounded-lg bg-blue-50 p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-blue-700"
                x-text="({plugins: 'Updating plugins', themes: 'Updating themes', activate: 'Activating', deactivate: 'Deactivating', 'delete': 'Deleting', update: 'Updating'}[bulkType] || 'Processing') + '... ' + bulkCompleted + ' of ' + bulkTotal">
            </span>
            <span x-show="bulkFailed > 0" class="text-xs text-red-600" x-text="bulkFailed + ' failed'"></span>
        </div>
        <div class="h-2 w-full rounded-full bg-blue-200 overflow-hidden">
            <div class="h-full rounded-full bg-blue-600 transition-all duration-300"
                 :style="'width: ' + (bulkTotal > 0 ? Math.round((bulkCompleted / bulkTotal) * 100) : 0) + '%'"></div>
        </div>
    </div>

    {{-- Bulk action results summary --}}
    <div x-show="bulkSummary" x-cloak class="mb-4 rounded-lg border p-4"
         :class="bulkSummary?.failed > 0 ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50'">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium"
                   :class="bulkSummary?.failed > 0 ? 'text-red-800' : 'text-green-800'"
                   x-text="(bulkSummary?.action ? bulkSummary.action.charAt(0).toUpperCase() + bulkSummary.action.slice(1) : '') + ' complete: ' + (bulkSummary?.success || 0) + ' succeeded' + (bulkSummary?.failed > 0 ? ', ' + bulkSummary.failed + ' failed' : '')">
                </p>
                <template x-if="bulkSummary?.errors?.length > 0">
                    <ul class="mt-2 text-xs text-red-700 list-disc pl-4 space-y-0.5">
                        <template x-for="(err, i) in bulkSummary.errors" :key="i">
                            <li x-text="err"></li>
                        </template>
                    </ul>
                </template>
            </div>
            <button @click="bulkSummary = null" class="text-gray-400 hover:text-gray-600 ml-4 shrink-0">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    @endif {{-- !$embedded bulk progress/results --}}

    @if(!$embedded)
    {{-- Tab switcher (full page only) --}}
    <div class="mb-4 flex items-center gap-1 rounded-lg bg-gray-100 p-1 w-fit">
        <button
            wire:click="setTab('wordpress')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === 'wordpress' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
        >
            WordPress
            @if($site->core_update_version)
                <span class="ml-1 h-2 w-2 rounded-full bg-blue-500 inline-block"></span>
            @endif
        </button>
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
        @if(!$embedded)
        <button
            wire:click="setTab('users')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === 'users' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
        >
            Users
            <span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs">{{ $this->userCount }}</span>
        </button>
        <button
            wire:click="setTab('history')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === 'history' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
        >
            History
        </button>
        @endif
    </div>
    @endif {{-- !$embedded tab switcher --}}

    <x-ui.card :padding="false">
        @if($embedded)
        {{-- Embedded card header (matches overview card pattern) --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-100">
                    <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                </div>
                <h3 class="text-sm font-semibold text-gray-900">{{ __('Plugins & Themes') }}</h3>
            </div>
            <div class="flex items-center gap-3">
                {{-- Compact tab switcher --}}
                <div class="flex items-center gap-0.5 rounded-md bg-gray-100 p-0.5">
                    <button
                        wire:click="setTab('wordpress')"
                        class="rounded px-2.5 py-1 text-xs font-medium transition {{ $tab === 'wordpress' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
                    >
                        WP
                        @if($site->core_update_version)
                            <span class="ml-0.5 h-1.5 w-1.5 rounded-full bg-blue-500 inline-block"></span>
                        @endif
                    </button>
                    <button
                        wire:click="setTab('plugins')"
                        class="rounded px-2.5 py-1 text-xs font-medium transition {{ $tab === 'plugins' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
                    >
                        Plugins
                    </button>
                    <button
                        wire:click="setTab('themes')"
                        class="rounded px-2.5 py-1 text-xs font-medium transition {{ $tab === 'themes' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
                    >
                        Themes
                    </button>
                </div>
                <a href="{{ route('sites.plugins', $site) }}" class="text-xs text-purple-600 hover:text-purple-700" wire:navigate>
                    {{ __('View All') }} →
                </a>
            </div>
        </div>
        @endif

        {{-- Action loading status bar --}}
        <div wire:loading.flex wire:target="updateCore, updatePlugin, updateTheme, activatePlugin, deactivatePlugin, activateTheme, toggleAutoUpdate, syncNow, deletePlugin, deleteTheme"
             class="items-center gap-2 border-b bg-blue-50 px-4 py-2">
            <svg class="h-3.5 w-3.5 shrink-0 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <div class="h-1 w-16 shrink-0 rounded-full bg-blue-200 overflow-hidden">
                <div class="h-full rounded-full bg-blue-500 progress-indeterminate"></div>
            </div>
            <span class="text-xs font-medium text-blue-700">
                <span wire:loading wire:target="updateCore">{{ __('Updating WordPress...') }}</span>
                <span wire:loading wire:target="updatePlugin">{{ __('Updating plugin...') }}</span>
                <span wire:loading wire:target="updateTheme">{{ __('Updating theme...') }}</span>
                <span wire:loading wire:target="activatePlugin">{{ __('Activating plugin...') }}</span>
                <span wire:loading wire:target="deactivatePlugin">{{ __('Deactivating plugin...') }}</span>
                <span wire:loading wire:target="activateTheme">{{ __('Activating theme...') }}</span>
                <span wire:loading wire:target="toggleAutoUpdate">{{ __('Toggling auto-update...') }}</span>
                <span wire:loading wire:target="syncNow">{{ __('Syncing site data...') }}</span>
                <span wire:loading wire:target="deletePlugin">{{ __('Deleting plugin...') }}</span>
                <span wire:loading wire:target="deleteTheme">{{ __('Deleting theme...') }}</span>
            </span>
        </div>

        {{-- Summary bar + filter + search (not shown on WordPress tab) --}}
        <div class="border-b p-4" @if($tab === 'wordpress') style="display: none;" @endif>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-4">
                    @if($tab === 'plugins')
                        @if(!$embedded)
                            <input type="checkbox"
                                :checked="selected.length === {{ count($this->plugins) }} && selected.length > 0"
                                @change="selected = $event.target.checked ? @js($this->plugins->pluck('id')->values()->toArray()) : []"
                                class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                title="Select all">
                        @endif
                        <span class="text-sm text-gray-600">
                            {{ $this->pluginCounts['active'] }} active,
                            {{ $this->pluginCounts['inactive'] }} inactive,
                            {{ $this->pluginCounts['updates'] }} updates
                        </span>
                        @if(!$embedded && $this->pluginCounts['updates'] > 0)
                            <x-ui.button size="sm" x-on:click="updateAllPlugins()" x-bind:disabled="bulkUpdating">
                                <span x-show="!bulkUpdating || bulkType !== 'plugins'">Update All ({{ $this->pluginCounts['updates'] }})</span>
                                <span x-show="bulkUpdating && bulkType === 'plugins'" x-cloak>Updating...</span>
                            </x-ui.button>
                        @endif
                    @elseif($tab === 'themes')
                        @if(!$embedded)
                            <input type="checkbox"
                                :checked="selected.length === {{ count($this->themes) }} && selected.length > 0"
                                @change="selected = $event.target.checked ? @js($this->themes->pluck('id')->values()->toArray()) : []"
                                class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                title="Select all">
                        @endif
                        <span class="text-sm text-gray-600">
                            {{ $this->themeCounts['active'] }} active,
                            {{ $this->themeCounts['updates'] }} updates
                        </span>
                        @if(!$embedded && $this->themeCounts['updates'] > 0)
                            <x-ui.button size="sm" x-on:click="updateAllThemes()" x-bind:disabled="bulkUpdating">
                                <span x-show="!bulkUpdating || bulkType !== 'themes'">Update All ({{ $this->themeCounts['updates'] }})</span>
                                <span x-show="bulkUpdating && bulkType === 'themes'" x-cloak>Updating...</span>
                            </x-ui.button>
                        @endif
                    @elseif($tab === 'history')
                        <span class="text-sm text-gray-600">
                            {{ $this->updateHistory->count() }} update record(s)
                        </span>
                    @else
                        <span class="text-sm text-gray-600">
                            {{ $this->userCount }} user(s)
                        </span>
                    @endif
                </div>

                {{-- Filter --}}
                @if($tab !== 'users')
                    @php
                        if ($tab === 'plugins') {
                            $filters = ['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'updates' => 'Updates'];
                        } elseif ($tab === 'history') {
                            $filters = ['all' => 'All', 'plugins' => 'Plugins', 'themes' => 'Themes'];
                        } else {
                            $filters = ['all' => 'All', 'active' => 'Active', 'updates' => 'Updates'];
                        }
                    @endphp
                    @if($embedded)
                        {{-- Compact dropdown filter for embedded mode --}}
                        <select wire:model.live="filter" class="rounded-md border-gray-300 text-xs py-1 pl-2 pr-7 focus:border-purple-500 focus:ring-purple-500">
                            @foreach($filters as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        {{-- Filter pills for full page --}}
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
                @endif

                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search..."
                    class="ml-auto w-48"
                />
            </div>
        </div>

        {{-- Bulk action bar (full page only) --}}
        @if(!$embedded && ($tab === 'plugins' || $tab === 'themes'))
            <div x-show="selected.length > 0" x-cloak class="border-b bg-purple-50 px-4 py-2">
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-purple-700" x-text="selected.length + ' selected'"></span>
                    <select x-model="bulkAction" class="rounded-md border-gray-300 text-sm py-1 pl-2 pr-8 focus:border-purple-500 focus:ring-purple-500">
                        <option value="">{{ __('Bulk Actions') }}</option>
                        <option value="activate">{{ __('Activate') }}</option>
                        @if($tab === 'plugins')
                            <option value="deactivate">{{ __('Deactivate') }}</option>
                        @endif
                        <option value="delete">{{ __('Delete') }}</option>
                        <option value="update">{{ __('Update') }}</option>
                    </select>
                    <x-ui.button size="sm" x-on:click="applyBulkAction('{{ $tab }}')" x-bind:disabled="!bulkAction || bulkUpdating">
                        {{ __('Apply') }}
                    </x-ui.button>
                    <button @click="selected = []; bulkAction = ''" class="text-sm text-gray-500 hover:text-gray-700">
                        {{ __('Clear') }}
                    </button>
                </div>
            </div>
        @endif

        {{-- WordPress tab --}}
        @if($tab === 'wordpress')
            @if($embedded)
                {{-- Compact embedded WordPress view --}}
                <div class="divide-y">
                    {{-- WP Version row --}}
                    <div class="flex items-center justify-between px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-500">WordPress</span>
                            <span class="text-sm font-semibold text-gray-900">{{ $site->wp_version ?? '—' }}</span>
                            @if(!$site->core_update_version)
                                <x-ui.badge variant="green">{{ __('Up to date') }}</x-ui.badge>
                            @endif
                        </div>
                        @if($site->core_update_version)
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-blue-600">{{ $site->wp_version }} &rarr; {{ $site->core_update_version }}</span>
                                <x-ui.button size="sm" wire:click="updateCore" wire:loading.attr="disabled" wire:target="updateCore">
                                    <span wire:loading.remove wire:target="updateCore">{{ __('Update') }}</span>
                                    <span wire:loading wire:target="updateCore">{{ __('Updating...') }}</span>
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                    {{-- PHP Version row --}}
                    <div class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-gray-500">PHP</span>
                        <span class="text-sm font-medium text-gray-900">{{ $site->php_version ?? '—' }}</span>
                    </div>
                    {{-- Server row --}}
                    <div class="flex items-center justify-between px-4 py-3">
                        <span class="text-sm text-gray-500">Server</span>
                        <span class="text-sm font-medium text-gray-900">{{ $site->server_software ?? '—' }}</span>
                    </div>
                </div>
            @else
                {{-- Full page WordPress view --}}
                <div class="p-6 space-y-6">
                    {{-- Update banner or up-to-date badge --}}
                    @if($site->core_update_version)
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                                        <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-semibold text-blue-900">{{ __('WordPress Core Update Available') }}</h4>
                                        <p class="text-xs text-blue-700">{{ $site->wp_version }} &rarr; {{ $site->core_update_version }}</p>
                                    </div>
                                </div>
                                <x-ui.button size="sm" wire:click="updateCore" wire:loading.attr="disabled" wire:target="updateCore">
                                    <span wire:loading.remove wire:target="updateCore">{{ __('Update to :version', ['version' => $site->core_update_version]) }}</span>
                                    <span wire:loading wire:target="updateCore">{{ __('Updating...') }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    @endif

                    {{-- Environment info grid --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- WordPress Version --}}
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('WordPress Version') }}</p>
                                    <p class="mt-1 text-lg font-semibold text-gray-900">{{ $site->wp_version ?? '—' }}</p>
                                </div>
                                @if(!$site->core_update_version)
                                    <x-ui.badge variant="green">{{ __('Up to date') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="blue">{{ __('Update available') }}</x-ui.badge>
                                @endif
                            </div>
                        </div>

                        {{-- PHP Version --}}
                        <div class="rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('PHP Version') }}</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $site->php_version ?? '—' }}</p>
                        </div>

                        {{-- Server Software --}}
                        <div class="rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Server Software') }}</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $site->server_software ?? '—' }}</p>
                        </div>

                        {{-- Multisite --}}
                        <div class="rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Multisite') }}</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $site->is_multisite ? __('Yes') : __('No') }}</p>
                        </div>

                        {{-- Database Size --}}
                        @if($site->db_size_mb)
                        <div class="rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Database Size') }}</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ number_format($site->db_size_mb, 1) }} MB</p>
                        </div>
                        @endif

                        {{-- Uploads Size --}}
                        @if($site->uploads_size_mb)
                        <div class="rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Uploads Size') }}</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ number_format($site->uploads_size_mb, 1) }} MB</p>
                        </div>
                        @endif
                    </div>
                </div>
            @endif
        @endif

        {{-- Plugin rows --}}
        @if($tab === 'plugins')
            <div class="divide-y">
                @forelse($this->plugins as $plugin)
                    @php $resultKey = 'plugin_' . $plugin->id; @endphp
                    <div
                        class="flex items-center justify-between px-4 py-3 transition-colors hover:bg-gray-50"
                        wire:loading.class="!bg-blue-50" wire:target="updatePlugin({{ $plugin->id }}), activatePlugin({{ $plugin->id }}), deactivatePlugin({{ $plugin->id }})"
                    >
                        @if(!$embedded)
                        <input type="checkbox" :value="{{ $plugin->id }}" x-model="selected"
                            class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500 mr-3 shrink-0">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <button wire:click="showDetail('plugin', {{ $plugin->id }})" class="text-sm font-medium text-gray-900 hover:text-purple-700 hover:underline text-left">
                                    {{ $plugin->name }}
                                </button>
                                <x-ui.badge :variant="$plugin->is_active ? 'green' : 'gray'">
                                    {{ $plugin->is_active ? __('Active') : __('Inactive') }}
                                </x-ui.badge>
                                <button
                                    wire:click="toggleAutoUpdate('plugin', {{ $plugin->id }})"
                                    class="rounded px-1.5 py-0.5 text-xs font-medium transition {{ $plugin->auto_update ? 'bg-purple-100 text-purple-700 hover:bg-purple-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}"
                                    title="{{ $plugin->auto_update ? __('Disable') : __('Enable') }} {{ __('auto-updates') }}"
                                >
                                    {{ $plugin->auto_update ? 'Auto ✓' : 'Auto' }}
                                </button>
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
                                @if($updateResults[$resultKey]['success'])
                                    {{-- Success: small green text, auto-dismiss --}}
                                    <div x-data="{ show: true }" x-init="autoDismiss('{{ $resultKey }}')" x-show="show"
                                         class="mt-1 text-xs font-medium text-green-600">
                                        {{ $updateResults[$resultKey]['message'] }}
                                    </div>
                                @else
                                    {{-- Error: styled alert box --}}
                                    <div x-data="{ show: true }" x-show="show"
                                         class="mt-2 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2">
                                        <svg class="h-4 w-4 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                        </svg>
                                        <span class="text-xs font-medium text-red-700 flex-1">{{ $updateResults[$resultKey]['message'] }}</span>
                                        <button wire:click="clearResult('{{ $resultKey }}')" class="text-red-400 hover:text-red-600 shrink-0">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                @endif
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
                                    <span wire:loading.remove wire:target="deactivatePlugin({{ $plugin->id }})">{{ __('Deactivate') }}</span>
                                    <span wire:loading wire:target="deactivatePlugin({{ $plugin->id }})">...</span>
                                </button>
                            @else
                                <button
                                    wire:click="activatePlugin({{ $plugin->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="activatePlugin({{ $plugin->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-50 transition"
                                >
                                    <span wire:loading.remove wire:target="activatePlugin({{ $plugin->id }})">{{ __('Activate') }}</span>
                                    <span wire:loading wire:target="activatePlugin({{ $plugin->id }})">...</span>
                                </button>
                                <button
                                    wire:click="confirmDeletePlugin({{ $plugin->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                >
                                    {{ __('Delete') }}
                                </button>
                            @endif

                            {{-- Update button --}}
                            @if($plugin->has_update)
                                <span class="text-xs text-yellow-600">{{ $plugin->version }} &rarr; {{ $plugin->update_version }}</span>
                                <button
                                    wire:click="assessRisk({{ $plugin->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="assessRisk({{ $plugin->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition"
                                    title="{{ __('AI Risk Assessment') }}"
                                >
                                    <span wire:loading.remove wire:target="assessRisk({{ $plugin->id }})">AI</span>
                                    <span wire:loading wire:target="assessRisk({{ $plugin->id }})">...</span>
                                </button>
                                <x-ui.button size="sm" wire:click="updatePlugin({{ $plugin->id }})" wire:loading.attr="disabled" wire:target="updatePlugin({{ $plugin->id }})">
                                    <span wire:loading.remove wire:target="updatePlugin({{ $plugin->id }})">{{ __('Update') }}</span>
                                    <span wire:loading wire:target="updatePlugin({{ $plugin->id }})">{{ __('Updating...') }}</span>
                                </x-ui.button>
                            @else
                                <span class="text-xs text-green-600">{{ __('Up to date') }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">
                        {{ __('No plugins found') }}{{ $search ? ' ' . __('matching') . ' "' . $search . '"' : '' }}.
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
                        @if(!$embedded)
                        <input type="checkbox" :value="{{ $theme->id }}" x-model="selected"
                            class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500 mr-3 shrink-0">
                        @endif
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
                                    <button wire:click="showDetail('theme', {{ $theme->id }})" class="text-sm font-medium text-gray-900 hover:text-purple-700 hover:underline text-left">
                                        {{ $theme->name }}
                                    </button>
                                    <x-ui.badge :variant="$theme->is_active ? 'green' : 'gray'">
                                        {{ $theme->is_active ? __('Active') : __('Inactive') }}
                                    </x-ui.badge>
                                    @if($theme->is_child_theme)
                                        <x-ui.badge variant="purple">Child</x-ui.badge>
                                    @endif
                                    <button
                                        wire:click="toggleAutoUpdate('theme', {{ $theme->id }})"
                                        class="rounded px-1.5 py-0.5 text-xs font-medium transition {{ $theme->auto_update ? 'bg-purple-100 text-purple-700 hover:bg-purple-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}"
                                        title="{{ $theme->auto_update ? __('Disable') : __('Enable') }} {{ __('auto-updates') }}"
                                    >
                                        {{ $theme->auto_update ? 'Auto ✓' : 'Auto' }}
                                    </button>
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
                                    @if($updateResults[$resultKey]['success'])
                                        {{-- Success: small green text, auto-dismiss --}}
                                        <div x-data="{ show: true }" x-init="autoDismiss('{{ $resultKey }}')" x-show="show"
                                             class="mt-1 text-xs font-medium text-green-600">
                                            {{ $updateResults[$resultKey]['message'] }}
                                        </div>
                                    @else
                                        {{-- Error: styled alert box --}}
                                        <div x-data="{ show: true }" x-show="show"
                                             class="mt-2 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2">
                                            <svg class="h-4 w-4 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                            </svg>
                                            <span class="text-xs font-medium text-red-700 flex-1">{{ $updateResults[$resultKey]['message'] }}</span>
                                            <button wire:click="clearResult('{{ $resultKey }}')" class="text-red-400 hover:text-red-600 shrink-0">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </div>
                                    @endif
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
                                    <span wire:loading.remove wire:target="activateTheme({{ $theme->id }})">{{ __('Activate') }}</span>
                                    <span wire:loading wire:target="activateTheme({{ $theme->id }})">...</span>
                                </button>
                                <button
                                    wire:click="deleteThemeById({{ $theme->id }})"
                                    wire:confirm="Are you sure you want to delete {{ $theme->name }}?"
                                    wire:loading.attr="disabled"
                                    wire:target="deleteThemeById({{ $theme->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition"
                                >
                                    <span wire:loading.remove wire:target="deleteThemeById({{ $theme->id }})">{{ __('Delete') }}</span>
                                    <span wire:loading wire:target="deleteThemeById({{ $theme->id }})">{{ __('Deleting...') }}</span>
                                </button>
                            @endif

                            {{-- Update button --}}
                            @if($theme->has_update)
                                <span class="text-xs text-yellow-600">{{ $theme->version }} &rarr; {{ $theme->update_version }}</span>
                                <x-ui.button size="sm" wire:click="updateTheme({{ $theme->id }})" wire:loading.attr="disabled" wire:target="updateTheme({{ $theme->id }})">
                                    <span wire:loading.remove wire:target="updateTheme({{ $theme->id }})">{{ __('Update') }}</span>
                                    <span wire:loading wire:target="updateTheme({{ $theme->id }})">{{ __('Updating...') }}</span>
                                </x-ui.button>
                            @else
                                <span class="text-xs text-green-600">{{ __('Up to date') }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">
                        {{ __('No themes found') }}{{ $search ? ' ' . __('matching') . ' "' . $search . '"' : '' }}.
                    </div>
                @endforelse
            </div>
        @endif

        {{-- History tab --}}
        @if($tab === 'history')
            <div class="divide-y">
                @forelse($this->updateHistory as $log)
                    <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900">{{ $log->name }}</span>
                                <x-ui.badge :variant="$log->type === 'plugin' ? 'blue' : 'purple'">{{ ucfirst($log->type) }}</x-ui.badge>
                                @if($log->success)
                                    <x-ui.badge variant="green">Success</x-ui.badge>
                                @else
                                    <x-ui.badge variant="red">Failed</x-ui.badge>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">
                                {{ $log->from_version ?? '?' }} &rarr; {{ $log->to_version ?? '?' }}
                                @if($log->user)
                                    <span class="mx-1">&middot;</span>
                                    by {{ $log->user->name }}
                                @endif
                                <span class="mx-1">&middot;</span>
                                {{ $log->performed_at->diffForHumans() }}
                            </div>
                            @if($log->error_message)
                                <div class="mt-1 text-xs text-red-600">{{ $log->error_message }}</div>
                            @endif
                        </div>
                        <div class="text-right text-xs text-gray-400 ml-4">
                            {{ $log->performed_at->format('M j, Y H:i') }}
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">
                        {{ __('No update history found') }}{{ $search ? ' ' . __('matching') . ' "' . $search . '"' : '' }}.
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
                        {{ __('No users found') }}{{ $search ? ' ' . __('matching') . ' "' . $search . '"' : '' }}.
                        @if(!$search)
                            <span class="block mt-1 text-gray-400">{{ __('Sync the site to fetch user data.') }}</span>
                        @endif
                    </div>
                @endforelse
            </div>
        @endif
    </x-ui.card>

    @if(!$embedded)
    {{-- Last synced --}}
    @if($site->last_synced_at)
        <p class="mt-3 text-xs text-gray-400 text-right">
            {{ __('Last synced') }} {{ $site->last_synced_at->diffForHumans() }}
        </p>
    @endif
    @endif

    {{-- Delete Plugin Confirmation Modal --}}
    <x-ui.modal name="confirm-delete-plugin" maxWidth="sm">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Delete Plugin') }}</h3>
            <p class="mt-2 text-sm text-gray-600">
                {{ __('Are you sure you want to delete') }} <strong>{{ $confirmingDeleteName ?? __('this plugin') }}</strong>? {{ __('This action cannot be undone.') }}
            </p>
            <div wire:loading.flex wire:target="deletePlugin"
                 class="mt-4 items-center gap-2 rounded-lg bg-blue-50 px-3 py-2">
                <svg class="h-3.5 w-3.5 shrink-0 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <div class="h-1 w-16 shrink-0 rounded-full bg-blue-200 overflow-hidden">
                    <div class="h-full rounded-full bg-blue-500 progress-indeterminate"></div>
                </div>
                <span class="text-xs font-medium text-blue-700">{{ __('Deleting plugin...') }}</span>
            </div>
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-confirm-delete-plugin')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="deletePlugin" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deletePlugin">{{ __('Delete Plugin') }}</span>
                    <span wire:loading wire:target="deletePlugin">{{ __('Deleting...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Delete Theme Confirmation Modal --}}
    <x-ui.modal name="confirm-delete-theme" maxWidth="sm">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Delete Theme') }}</h3>
            <p class="mt-2 text-sm text-gray-600">
                {{ __('Are you sure you want to delete') }} <strong>{{ $confirmingDeleteThemeName ?? __('this theme') }}</strong>? {{ __('This action cannot be undone.') }}
            </p>
            @if(!empty($confirmingDeleteThemeChildren))
                <div class="mt-3 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                    <p class="text-sm font-medium text-yellow-800">{{ __('Warning: Child themes depend on this theme') }}</p>
                    <ul class="mt-1 text-xs text-yellow-700 list-disc pl-4">
                        @foreach($confirmingDeleteThemeChildren as $childName)
                            <li>{{ $childName }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-yellow-600">{{ __('Deleting this parent theme will break these child themes.') }}</p>
                </div>
            @endif
            <div wire:loading.flex wire:target="deleteTheme"
                 class="mt-4 items-center gap-2 rounded-lg bg-blue-50 px-3 py-2">
                <svg class="h-3.5 w-3.5 shrink-0 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <div class="h-1 w-16 shrink-0 rounded-full bg-blue-200 overflow-hidden">
                    <div class="h-full rounded-full bg-blue-500 progress-indeterminate"></div>
                </div>
                <span class="text-xs font-medium text-blue-700">{{ __('Deleting theme...') }}</span>
            </div>
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-confirm-delete-theme')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="danger" x-on:click="$wire.deleteTheme()">
                    {{ __('Delete Theme') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Plugin/Theme Detail Modal --}}
    <x-ui.modal name="plugin-detail" maxWidth="lg">
        @if($detailItem)
            <div class="p-2">
                <h3 class="text-lg font-semibold text-gray-900">{{ $detailItem['name'] }}</h3>
                <div class="mt-1 flex items-center gap-2">
                    <x-ui.badge :variant="$detailItem['type'] === 'plugin' ? 'purple' : 'green'">{{ ucfirst($detailItem['type']) }}</x-ui.badge>
                    <span class="text-sm text-gray-500">v{{ $detailItem['version'] }}</span>
                    @if($detailItem['is_active'])
                        <x-ui.badge variant="green">Active</x-ui.badge>
                    @endif
                    @if($detailItem['has_update'])
                        <x-ui.badge variant="yellow">Update: v{{ $detailItem['update_version'] }}</x-ui.badge>
                    @endif
                </div>

                {{-- Info grid --}}
                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-500">{{ __('Author') }}:</span> <span class="text-gray-900">{{ $detailItem['author'] ?? '—' }}</span></div>
                    <div><span class="text-gray-500">{{ __('Slug') }}:</span> <span class="text-gray-900 font-mono text-xs">{{ $detailItem['slug'] }}</span></div>
                    <div><span class="text-gray-500">{{ __('Auto-Update') }}:</span> <span class="{{ $detailItem['auto_update'] ? 'text-purple-700' : 'text-gray-900' }}">{{ $detailItem['auto_update'] ? __('Enabled') : __('Disabled') }}</span></div>
                    @if($detailItem['wp_org_last_updated'])
                        <div><span class="text-gray-500">{{ __('Last WP.org Update') }}:</span> <span class="text-gray-900">{{ $detailItem['wp_org_last_updated'] }}</span></div>
                    @endif
                    @if($detailItem['license_status'])
                        <div>
                            <span class="text-gray-500">{{ __('License') }}:</span>
                            <span class="{{ $detailItem['license_status'] === 'active' ? 'text-green-600' : ($detailItem['license_status'] === 'expired' ? 'text-red-600' : 'text-yellow-600') }}">
                                {{ ucfirst($detailItem['license_status']) }}
                            </span>
                            @if($detailItem['license_expires_at'])
                                <span class="text-gray-400 text-xs">{{ $detailItem['license_expires_at'] }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Description --}}
                @if($detailItem['description'])
                    <div class="mt-4 text-sm text-gray-600 border-t pt-3">{{ $detailItem['description'] }}</div>
                @endif

                {{-- Changelog --}}
                <div class="mt-4 border-t pt-3" x-data="{ showChangelog: false }">
                    <button
                        @click="showChangelog = !showChangelog; if (showChangelog && !$wire.changelog && !$wire.changelogLoading) { $wire.fetchChangelog(); }"
                        class="flex items-center gap-1.5 text-sm font-medium text-purple-600 hover:text-purple-800 transition"
                    >
                        <svg class="h-4 w-4 transition-transform" :class="showChangelog && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        {{ __('Changelog') }}
                    </button>
                    <div x-show="showChangelog" x-collapse>
                        <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                            @if($changelogLoading)
                                <div class="flex items-center gap-2 text-gray-400">
                                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ __('Loading changelog...') }}
                                </div>
                            @elseif($changelog)
                                <div class="prose prose-sm prose-gray max-w-none [&_h4]:text-sm [&_h4]:font-semibold [&_h4]:mt-3 [&_h4]:mb-1 [&_ul]:mt-1 [&_ul]:mb-2 [&_li]:text-xs">
                                    {!! $changelog !!}
                                </div>
                            @else
                                <p class="text-gray-400 text-xs">{{ __('Changelog not available from WordPress.org') }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Warnings --}}
                @if($detailItem['is_abandoned'] || $detailItem['is_closed'])
                    <div class="mt-3 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                        <p class="text-sm font-medium text-yellow-800">
                            @if($detailItem['is_closed'])
                                {{ __('This :type has been closed on WordPress.org', ['type' => $detailItem['type']]) }}{{ $detailItem['closed_reason'] ? ': ' . str_replace('_', ' ', $detailItem['closed_reason']) : '' }}
                            @else
                                {{ __('This :type appears to be abandoned', ['type' => $detailItem['type']]) }}
                            @endif
                        </p>
                    </div>
                @endif

                {{-- Rollback --}}
                @if($detailItem['can_rollback'] && $detailItem['rollback_version'] && $detailItem['type'] === 'plugin')
                    <div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-blue-800">{{ __('Rollback available') }}</p>
                            <p class="text-xs text-blue-600">{{ __('Revert to version :version', ['version' => $detailItem['rollback_version']]) }}</p>
                        </div>
                        <button wire:click="rollbackPlugin({{ $detailItem['id'] ?? 0 }})"
                                wire:confirm="Rollback {{ $detailItem['name'] }} to version {{ $detailItem['rollback_version'] }}?"
                                wire:loading.attr="disabled"
                                class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            <span wire:loading.remove wire:target="rollbackPlugin">{{ __('Rollback') }}</span>
                            <span wire:loading wire:target="rollbackPlugin">{{ __('Rolling back...') }}</span>
                        </button>
                    </div>
                @endif

                {{-- Quick links --}}
                <div class="mt-4 flex items-center gap-2 border-t pt-3">
                    <a href="{{ route('sites.plugins', $site) }}" class="text-xs text-purple-600 hover:underline" wire:navigate>{{ __('Plugins') }}</a>
                    <span class="text-gray-300">|</span>
                    <a href="{{ route('sites.security', $site) }}" class="text-xs text-purple-600 hover:underline" wire:navigate>{{ __('Security') }}</a>
                    @if($detailItem['url'])
                        <span class="text-gray-300">|</span>
                        <a href="{{ $detailItem['url'] }}" target="_blank" class="text-xs text-purple-600 hover:underline">{{ __('Website') }}</a>
                    @endif
                </div>

                <div class="mt-4 flex justify-end">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal-plugin-detail')">{{ __('Close') }}</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- AI Risk Assessment Modal --}}
    <x-ui.modal name="risk-assessment" maxWidth="md">
        @if($riskAssessment)
            <div class="p-2">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('AI Risk Assessment') }}</h3>

                <div class="mt-4 flex items-center gap-3">
                    <div class="h-16 w-16 rounded-full flex items-center justify-center text-xl font-bold
                        {{ $riskAssessment['level'] === 'safe' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' :
                           ($riskAssessment['level'] === 'risky' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' :
                           'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400') }}">
                        {{ $riskAssessment['score'] }}
                    </div>
                    <div>
                        <p class="text-sm font-medium {{ $riskAssessment['level'] === 'safe' ? 'text-green-700' : ($riskAssessment['level'] === 'risky' ? 'text-red-700' : 'text-yellow-700') }}">
                            {{ ucfirst($riskAssessment['level']) }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Risk Score: :score/100', ['score' => $riskAssessment['score']]) }}</p>
                    </div>
                </div>

                @if(!empty($riskAssessment['reasons']))
                    <div class="mt-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">{{ __('Analysis') }}</p>
                        <ul class="space-y-1">
                            @foreach($riskAssessment['reasons'] as $reason)
                                <li class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                                    <span class="mt-1 h-1.5 w-1.5 rounded-full shrink-0 {{ $riskAssessment['level'] === 'safe' ? 'bg-green-400' : ($riskAssessment['level'] === 'risky' ? 'bg-red-400' : 'bg-yellow-400') }}"></span>
                                    {{ $reason }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(!empty($riskAssessment['recommendation']))
                    <div class="mt-4 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 p-3">
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $riskAssessment['recommendation'] }}</p>
                    </div>
                @endif

                <div class="mt-4 flex justify-end">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal-risk-assessment')">{{ __('Close') }}</x-ui.button>
                </div>
            </div>
        @elseif($riskLoading)
            <div class="p-6 text-center">
                <svg class="mx-auto h-8 w-8 animate-spin text-purple-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="mt-3 text-sm text-gray-500">{{ __('Analyzing update risk with AI...') }}</p>
            </div>
        @endif
    </x-ui.modal>
</div>
