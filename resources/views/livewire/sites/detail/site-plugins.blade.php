<div
    @if($hasRunningJobs) wire:poll.3s="checkJobProgress" @endif
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
            await $wire.syncNow();
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
            await $wire.syncNow();
            this.bulkUpdating = false;
        },
        autoDismiss(key) {
            setTimeout(() => { $wire.clearResult(key); }, 5000);
        }
    }"
>
    {{-- Header --}}
    <x-ui.page-header title="Plugins & Themes" subtitle="Manage installed plugins and themes" />

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

    {{-- Quick Actions Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-2">
        {{-- WP Admin --}}
        <x-ui.button variant="secondary" wire:click="openWpAdmin" wire:loading.attr="disabled" wire:target="openWpAdmin">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
            </svg>
            <span wire:loading.remove wire:target="openWpAdmin">WP Admin</span>
            <span wire:loading wire:target="openWpAdmin">Opening...</span>
        </x-ui.button>

        {{-- Quick Backup --}}
        <x-ui.button variant="secondary" wire:click="quickBackup" wire:loading.attr="disabled" wire:target="quickBackup">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
            </svg>
            <span wire:loading.remove wire:target="quickBackup">Backup Now</span>
            <span wire:loading wire:target="quickBackup">Starting...</span>
        </x-ui.button>

        {{-- Sync Now --}}
        <x-ui.button variant="secondary" wire:click="syncNow" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span wire:loading.remove wire:target="syncNow">Sync Now</span>
            <span wire:loading wire:target="syncNow">Syncing...</span>
        </x-ui.button>

        <div class="flex-1"></div>

        {{-- Safe Update Mode Toggle --}}
        <label class="flex items-center gap-2 cursor-pointer">
            <div class="relative">
                <input type="checkbox" wire:model.live="safeUpdateMode" class="sr-only peer">
                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600"></div>
            </div>
            <span class="text-sm font-medium text-gray-700">Safe Update Mode</span>
            @if($safeUpdateMode)
                <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            @endif
        </label>
    </div>

    {{-- Flash messages --}}
    <x-ui.flash-alert type="success" key="update-success" />
    <x-ui.flash-alert type="error" key="update-error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="sync" :jobs="$trackedJobs" title="Syncing site data..." />
    <x-ui.job-progress job-key="abandoned" :jobs="$trackedJobs" title="Checking for abandoned plugins..." />
    <x-ui.job-progress job-key="safe-update" :jobs="$trackedJobs" title="Running safe update..." />

    {{-- Safe Updates in Progress --}}
    @if($this->activeSafeUpdates->count() > 0)
        <div class="mb-4 rounded-lg border border-purple-200 bg-purple-50 p-4">
            <h4 class="text-sm font-semibold text-purple-900 mb-3">Safe Updates in Progress</h4>
            <div class="space-y-3">
                @foreach($this->activeSafeUpdates as $safeUpdate)
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-sm font-medium text-purple-900">{{ $safeUpdate->name }}</span>
                            <x-ui.badge :variant="match($safeUpdate->status) {
                                'pending' => 'gray',
                                'backing_up' => 'yellow',
                                'updating' => 'purple',
                                'health_checking' => 'purple',
                                'rolling_back' => 'yellow',
                                'completed' => 'green',
                                'failed' => 'red',
                                default => 'gray',
                            }">
                                {{ ucfirst(str_replace('_', ' ', $safeUpdate->status)) }}
                            </x-ui.badge>
                        </div>
                        <div class="flex items-center gap-1 text-xs text-gray-500">
                            @foreach(['pending', 'backing_up', 'updating', 'health_checking', 'completed'] as $step)
                                @php
                                    $steps = ['pending', 'backing_up', 'updating', 'health_checking', 'completed'];
                                    $currentIndex = array_search($safeUpdate->status, $steps);
                                    $stepIndex = array_search($step, $steps);
                                    $isActive = $currentIndex !== false && $stepIndex <= $currentIndex;
                                @endphp
                                <div class="flex items-center gap-1">
                                    <div class="h-2 w-2 rounded-full {{ $isActive ? 'bg-purple-500' : 'bg-gray-200' }}"></div>
                                    <span class="{{ $isActive ? 'text-purple-600' : '' }}">{{ ucfirst(str_replace('_', ' ', $step)) }}</span>
                                </div>
                                @if(!$loop->last)
                                    <div class="h-px w-4 {{ $isActive ? 'bg-purple-300' : 'bg-gray-200' }}"></div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

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
                        <div class="flex shrink-0 items-center gap-1">
                            <button
                                wire:click="deactivateConflictPlugin('{{ $siteConflict->plugin_b_slug }}')"
                                wire:loading.attr="disabled"
                                class="rounded px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-100 transition"
                                title="Deactivate {{ $siteConflict->plugin_b_slug }}"
                            >
                                Deactivate
                            </button>
                            <button
                                wire:click="dismissConflict({{ $siteConflict->id }})"
                                class="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-100 transition"
                            >
                                Dismiss
                            </button>
                        </div>
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

    {{-- WordPress Core Update Banner --}}
    @if($site->core_update_version)
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                        <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-blue-900">WordPress Core Update Available</h4>
                        <p class="text-xs text-blue-700">{{ $site->wp_version }} &rarr; {{ $site->core_update_version }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @if($safeUpdateMode)
                        <x-ui.button size="sm" variant="secondary" class="!bg-purple-50 !text-purple-700 !border-purple-200 hover:!bg-purple-100" wire:click="safeUpdateCore" wire:loading.attr="disabled" wire:target="safeUpdateCore">
                            <svg class="h-3.5 w-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <span wire:loading.remove wire:target="safeUpdateCore">Safe Update Core</span>
                            <span wire:loading wire:target="safeUpdateCore">Starting...</span>
                        </x-ui.button>
                    @else
                        <x-ui.button size="sm" wire:click="updateCore" wire:loading.attr="disabled" wire:target="updateCore">
                            <span wire:loading.remove wire:target="updateCore">Update Core</span>
                            <span wire:loading wire:target="updateCore">Updating...</span>
                        </x-ui.button>
                    @endif
                </div>
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
        <button
            wire:click="setTab('history')"
            class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === 'history' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
        >
            History
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

                {{-- Filter pills --}}
                @if($tab !== 'users')
                    @php
                        if ($tab === 'plugins') {
                            $filters = ['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'updates' => 'Updates'];
                            if ($this->pluginCounts['issues'] > 0) {
                                $filters['abandoned'] = 'Issues (' . $this->pluginCounts['issues'] . ')';
                            }
                        } elseif ($tab === 'history') {
                            $filters = ['all' => 'All', 'plugins' => 'Plugins', 'themes' => 'Themes'];
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
                />
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
                                <button wire:click="showDetail('plugin', {{ $plugin->id }})" class="text-sm font-medium text-gray-900 hover:text-purple-700 hover:underline text-left">
                                    {{ $plugin->name }}
                                </button>
                                <x-ui.badge :variant="$plugin->is_active ? 'green' : 'gray'">
                                    {{ $plugin->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                                <button
                                    wire:click="toggleAutoUpdate('plugin', {{ $plugin->id }})"
                                    class="rounded px-1.5 py-0.5 text-xs font-medium transition {{ $plugin->auto_update ? 'bg-purple-100 text-purple-700 hover:bg-purple-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}"
                                    title="{{ $plugin->auto_update ? 'Disable' : 'Enable' }} auto-updates"
                                >
                                    {{ $plugin->auto_update ? 'Auto ✓' : 'Auto' }}
                                </button>
                                @if($plugin->is_closed)
                                    <span class="group relative">
                                        <x-ui.badge variant="red">Closed{{ $plugin->closed_reason ? ': ' . str_replace('_', ' ', $plugin->closed_reason) : '' }}</x-ui.badge>
                                    </span>
                                @elseif($plugin->is_abandoned)
                                    <span class="group relative">
                                        <x-ui.badge variant="yellow">Abandoned{{ $plugin->wp_org_last_updated ? ' — last updated ' . $plugin->wp_org_last_updated->format('M Y') : '' }}</x-ui.badge>
                                    </span>
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

                            {{-- Rollback button --}}
                            <button
                                wire:click="showRollback('plugin', {{ $plugin->id }})"
                                class="rounded px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 transition"
                                title="Rollback to previous version"
                            >
                                Rollback
                            </button>

                            {{-- Update button --}}
                            @if($plugin->has_update)
                                <span class="text-xs text-yellow-600">{{ $plugin->version }} &rarr; {{ $plugin->update_version }}</span>
                                @if($safeUpdateMode)
                                    <x-ui.button size="sm" variant="secondary" class="!bg-purple-50 !text-purple-700 !border-purple-200 hover:!bg-purple-100" wire:click="safeUpdatePlugin({{ $plugin->id }})" wire:loading.attr="disabled" wire:target="safeUpdatePlugin({{ $plugin->id }})">
                                        <svg class="h-3.5 w-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        <span wire:loading.remove wire:target="safeUpdatePlugin({{ $plugin->id }})">Safe Update</span>
                                        <span wire:loading wire:target="safeUpdatePlugin({{ $plugin->id }})">Starting...</span>
                                    </x-ui.button>
                                @else
                                    <x-ui.button size="sm" wire:click="updatePlugin({{ $plugin->id }})" wire:loading.attr="disabled" wire:target="updatePlugin({{ $plugin->id }})">
                                        <span wire:loading.remove wire:target="updatePlugin({{ $plugin->id }})">Update</span>
                                        <span wire:loading wire:target="updatePlugin({{ $plugin->id }})">Updating...</span>
                                    </x-ui.button>
                                @endif
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
                                    <button wire:click="showDetail('theme', {{ $theme->id }})" class="text-sm font-medium text-gray-900 hover:text-purple-700 hover:underline text-left">
                                        {{ $theme->name }}
                                    </button>
                                    <x-ui.badge :variant="$theme->is_active ? 'green' : 'gray'">
                                        {{ $theme->is_active ? 'Active' : 'Inactive' }}
                                    </x-ui.badge>
                                    @if($theme->is_child_theme)
                                        <x-ui.badge variant="purple">Child</x-ui.badge>
                                    @endif
                                    <button
                                        wire:click="toggleAutoUpdate('theme', {{ $theme->id }})"
                                        class="rounded px-1.5 py-0.5 text-xs font-medium transition {{ $theme->auto_update ? 'bg-purple-100 text-purple-700 hover:bg-purple-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}"
                                        title="{{ $theme->auto_update ? 'Disable' : 'Enable' }} auto-updates"
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

                            {{-- Rollback button --}}
                            <button
                                wire:click="showRollback('theme', {{ $theme->id }})"
                                class="rounded px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 transition"
                                title="Rollback to previous version"
                            >
                                Rollback
                            </button>

                            {{-- Update button --}}
                            @if($theme->has_update)
                                <span class="text-xs text-yellow-600">{{ $theme->version }} &rarr; {{ $theme->update_version }}</span>
                                @if($safeUpdateMode)
                                    <x-ui.button size="sm" variant="secondary" class="!bg-purple-50 !text-purple-700 !border-purple-200 hover:!bg-purple-100" wire:click="safeUpdateTheme({{ $theme->id }})" wire:loading.attr="disabled" wire:target="safeUpdateTheme({{ $theme->id }})">
                                        <svg class="h-3.5 w-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        <span wire:loading.remove wire:target="safeUpdateTheme({{ $theme->id }})">Safe Update</span>
                                        <span wire:loading wire:target="safeUpdateTheme({{ $theme->id }})">Starting...</span>
                                    </x-ui.button>
                                @else
                                    <x-ui.button size="sm" wire:click="updateTheme({{ $theme->id }})" wire:loading.attr="disabled" wire:target="updateTheme({{ $theme->id }})">
                                        <span wire:loading.remove wire:target="updateTheme({{ $theme->id }})">Update</span>
                                        <span wire:loading wire:target="updateTheme({{ $theme->id }})">Updating...</span>
                                    </x-ui.button>
                                @endif
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
                        No update history found{{ $search ? ' matching "' . $search . '"' : '' }}.
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
                Are you sure you want to delete <strong>{{ $confirmingDeleteName ?? 'this plugin' }}</strong>? This action cannot be undone.
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
                Are you sure you want to delete <strong>{{ $confirmingDeleteThemeName ?? 'this theme' }}</strong>? This action cannot be undone.
            </p>
            @if(!empty($confirmingDeleteThemeChildren))
                <div class="mt-3 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                    <p class="text-sm font-medium text-yellow-800">Warning: Child themes depend on this theme</p>
                    <ul class="mt-1 text-xs text-yellow-700 list-disc pl-4">
                        @foreach($confirmingDeleteThemeChildren as $childName)
                            <li>{{ $childName }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-yellow-600">Deleting this parent theme will break these child themes.</p>
                </div>
            @endif
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

    {{-- Rollback Modal --}}
    <x-ui.modal name="rollback" maxWidth="md">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">Rollback to Previous Version</h3>
            <p class="mt-1 text-sm text-gray-600">Select a previous version to restore.</p>

            @if($rollbackItemId && $this->rollbackHistory->count() > 0)
                <div class="mt-4 divide-y rounded-lg border">
                    @foreach($this->rollbackHistory as $log)
                        <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                            <div>
                                <div class="text-sm font-medium text-gray-900">v{{ $log->from_version }}</div>
                                <div class="text-xs text-gray-500">
                                    Updated to v{{ $log->to_version }} on {{ $log->performed_at->format('M j, Y H:i') }}
                                    @if($log->user)
                                        by {{ $log->user->name }}
                                    @endif
                                </div>
                            </div>
                            <x-ui.button size="sm" variant="secondary" wire:click="rollbackTo({{ $log->id }})" wire:loading.attr="disabled" wire:target="rollbackTo({{ $log->id }})">
                                <span wire:loading.remove wire:target="rollbackTo({{ $log->id }})">Restore v{{ $log->from_version }}</span>
                                <span wire:loading wire:target="rollbackTo({{ $log->id }})">Rolling back...</span>
                            </x-ui.button>
                        </div>
                    @endforeach
                </div>
            @elseif($rollbackItemId)
                <div class="mt-4 rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-500">
                    No update history found for this item. Rollback requires a previous update record.
                </div>
            @endif

            <div class="mt-4 flex justify-end">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-rollback')">Close</x-ui.button>
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
                    <div><span class="text-gray-500">Author:</span> <span class="text-gray-900">{{ $detailItem['author'] ?? '—' }}</span></div>
                    <div><span class="text-gray-500">Slug:</span> <span class="text-gray-900 font-mono text-xs">{{ $detailItem['slug'] }}</span></div>
                    <div><span class="text-gray-500">Auto-Update:</span> <span class="{{ $detailItem['auto_update'] ? 'text-purple-700' : 'text-gray-900' }}">{{ $detailItem['auto_update'] ? 'Enabled' : 'Disabled' }}</span></div>
                    @if($detailItem['wp_org_last_updated'])
                        <div><span class="text-gray-500">Last WP.org Update:</span> <span class="text-gray-900">{{ $detailItem['wp_org_last_updated'] }}</span></div>
                    @endif
                </div>

                {{-- Description --}}
                @if($detailItem['description'])
                    <div class="mt-4 text-sm text-gray-600 border-t pt-3">{{ $detailItem['description'] }}</div>
                @endif

                {{-- Warnings --}}
                @if($detailItem['is_abandoned'] || $detailItem['is_closed'])
                    <div class="mt-3 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                        <p class="text-sm font-medium text-yellow-800">
                            @if($detailItem['is_closed'])
                                This {{ $detailItem['type'] }} has been closed on WordPress.org{{ $detailItem['closed_reason'] ? ': ' . str_replace('_', ' ', $detailItem['closed_reason']) : '' }}
                            @else
                                This {{ $detailItem['type'] }} appears to be abandoned
                            @endif
                        </p>
                    </div>
                @endif

                {{-- Quick links --}}
                <div class="mt-4 flex items-center gap-2 border-t pt-3">
                    <a href="{{ route('sites.updates', $site) }}" class="text-xs text-purple-600 hover:underline" wire:navigate>Updates</a>
                    <span class="text-gray-300">|</span>
                    <a href="{{ route('sites.security', $site) }}" class="text-xs text-purple-600 hover:underline" wire:navigate>Security</a>
                    @if($detailItem['url'])
                        <span class="text-gray-300">|</span>
                        <a href="{{ $detailItem['url'] }}" target="_blank" class="text-xs text-purple-600 hover:underline">Website</a>
                    @endif
                </div>

                <div class="mt-4 flex justify-end">
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal-plugin-detail')">Close</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
