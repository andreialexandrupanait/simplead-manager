<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    {{-- Module not active banner --}}
    @if(!$this->isModuleActive)
        <x-ui.module-activation-banner
            title="{{ __('Database cleanup module is not active') }}"
            description="{{ __('Enable automatic database cleanup scheduling for this site.') }}"
            icon="database"
        >
            <x-ui.button size="sm" wire:click="activateModule">{{ __('Activate') }}</x-ui.button>
        </x-ui.module-activation-banner>
    @endif

    {{-- Flash messages --}}
    <x-ui.flash-alert type="success" key="db-success" />
    <x-ui.flash-alert type="error" key="db-error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="health" :jobs="$trackedJobs" title="Checking database health..." />

    {{-- Database Health Section --}}
    <div class="mb-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Database Health') }}</h2>
            <div class="flex items-center gap-3">
                @if($this->latestHealthCheck?->checked_at)
                    <span class="text-xs text-gray-500">{{ __('Last checked') }} {{ $this->latestHealthCheck->checked_at->diffForHumans() }}</span>
                @endif
                <x-ui.button variant="secondary" wire:click="refreshHealth" wire:loading.attr="disabled">
                    <x-icons.refresh-cw class="mr-1.5 h-4 w-4" wire:loading.class="animate-spin" wire:target="refreshHealth" />
                    {{ __('Refresh Health') }}
                </x-ui.button>
            </div>
        </div>

        @if($this->latestHealthCheck)
            {{-- Health overview cards --}}
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.stat-card label="Total Size" :value="$this->latestHealthCheck->formatted_total_size" icon="database" color="purple" />
                <x-ui.stat-card label="Tables" :value="$this->latestHealthCheck->total_tables" icon="layers" color="blue" />
                <x-ui.card class="!p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg
                            {{ $this->latestHealthCheck->status_color === 'green' ? 'bg-green-50' : ($this->latestHealthCheck->status_color === 'yellow' ? 'bg-yellow-50' : 'bg-red-50') }}">
                            <x-icons.check-circle class="h-5 w-5
                                {{ $this->latestHealthCheck->status_color === 'green' ? 'text-green-600' : ($this->latestHealthCheck->status_color === 'yellow' ? 'text-yellow-600' : 'text-red-600') }}" />
                        </div>
                        <div>
                            <x-ui.badge :variant="$this->latestHealthCheck->status_color">
                                {{ $this->latestHealthCheck->status_label }}
                            </x-ui.badge>
                            <p class="text-xs text-gray-500 mt-1">{{ __('Health Status') }}</p>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            {{-- Issues panel --}}
            @if(count($this->healthIssues) > 0)
                <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
                    <h4 class="text-sm font-semibold text-yellow-800 mb-2">{{ __('Issues Found') }}</h4>
                    <ul class="space-y-1">
                        @foreach($this->healthIssues as $issue)
                            <li class="flex items-start gap-2 text-sm text-yellow-700">
                                <x-icons.alert-triangle class="h-4 w-4 shrink-0 mt-0.5 text-yellow-600" />
                                {{ $issue }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Tables list --}}
            @if($this->latestHealthCheck->tables_data)
                <x-ui.card :padding="false" class="mb-4">
                    <div class="px-4 py-3 border-b flex items-center justify-between">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Tables') }}</h3>
                        <div class="flex items-center gap-2">
                            @php
                                $myisamTables = collect($this->latestHealthCheck->tables_data)->filter(fn($t) => strtolower($t['engine'] ?? '') === 'myisam');
                                $overheadTables = collect($this->latestHealthCheck->tables_data)->filter(fn($t) => ($t['overhead'] ?? 0) > 0);
                                $orphanedTables = collect($this->latestHealthCheck->tables_data)->filter(fn($t) => ($t['plugin']['status'] ?? null) === 'not-installed');
                            @endphp
                            @if($orphanedTables->isNotEmpty())
                                <span class="text-xs text-red-600 font-medium">{{ $orphanedTables->count() }} {{ __('orphaned') }}</span>
                            @endif
                            @if($overheadTables->isNotEmpty())
                                <span class="text-xs text-gray-500">{{ $overheadTables->count() }} {{ __('with overhead') }}</span>
                            @endif
                            @if($myisamTables->isNotEmpty())
                                <span class="text-xs text-yellow-600 font-medium">{{ $myisamTables->count() }} MyISAM</span>
                            @endif
                        </div>
                    </div>

                    {{-- Mobile cards --}}
                    <div class="md:hidden divide-y divide-gray-100 px-4 py-2 space-y-0">
                        @foreach($this->latestHealthCheck->tables_data as $table)
                            @php
                                $tableSize = ($table['data_size'] ?? 0) + ($table['index_size'] ?? 0);
                                $isMyisam = strtolower($table['engine'] ?? '') === 'myisam';
                                $hasOverhead = ($table['overhead'] ?? 0) > 1048576;
                                $plugin = $table['plugin'] ?? null;
                            @endphp
                            <div class="rounded-lg border border-gray-200 p-3 my-2 {{ $isMyisam ? 'bg-yellow-50' : ($plugin && $plugin['status'] === 'not-installed' ? 'bg-red-50/50' : '') }}">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <span class="font-mono text-sm text-gray-900 truncate block">{{ $table['name'] ?? '—' }}</span>
                                        @if($plugin)
                                            <span class="text-xs {{ $plugin['status'] === 'not-installed' ? 'text-red-600' : ($plugin['status'] === 'inactive' ? 'text-yellow-600' : 'text-gray-500') }}">
                                                {{ $plugin['name'] }}
                                                @if($plugin['status'] === 'not-installed')
                                                    ({{ __('not installed') }})
                                                @elseif($plugin['status'] === 'inactive')
                                                    ({{ __('inactive') }})
                                                @endif
                                            </span>
                                        @elseif($table['is_core'] ?? false)
                                            <span class="text-xs text-gray-400">{{ __('WordPress core') }}</span>
                                        @endif
                                    </div>
                                    <x-ui.badge :variant="$isMyisam ? 'yellow' : 'gray'">
                                        {{ $table['engine'] ?? '—' }}
                                    </x-ui.badge>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                    <span>{{ __('Rows') }}: <span class="text-gray-700">{{ number_format($table['rows'] ?? 0) }}</span></span>
                                    <span>{{ __('Size') }}:
                                        <span class="text-gray-700">
                                            @if($tableSize >= 1048576)
                                                {{ round($tableSize / 1048576, 2) }} MB
                                            @elseif($tableSize >= 1024)
                                                {{ round($tableSize / 1024, 2) }} KB
                                            @else
                                                {{ $tableSize }} B
                                            @endif
                                        </span>
                                    </span>
                                    <span>{{ __('Overhead') }}:
                                        @if(($table['overhead'] ?? 0) > 0)
                                            <span class="{{ $hasOverhead ? 'text-red-600 font-medium' : 'text-gray-700' }}">
                                                @if(($table['overhead'] ?? 0) >= 1048576)
                                                    {{ round(($table['overhead'] ?? 0) / 1048576, 2) }} MB
                                                @elseif(($table['overhead'] ?? 0) >= 1024)
                                                    {{ round(($table['overhead'] ?? 0) / 1024, 2) }} KB
                                                @else
                                                    {{ $table['overhead'] ?? 0 }} B
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </span>
                                </div>
                                @if(($table['overhead'] ?? 0) > 0 || $isMyisam || !($table['is_core'] ?? true))
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @if(($table['overhead'] ?? 0) > 0)
                                            <button wire:click="confirmTableAction('optimize', '{{ $table['name'] }}')"
                                                    class="rounded px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 transition">
                                                {{ __('Optimize') }}
                                            </button>
                                        @endif
                                        @if($isMyisam)
                                            <button wire:click="confirmTableAction('convert_engine', '{{ $table['name'] }}')"
                                                    class="rounded px-2 py-1 text-xs font-medium text-yellow-700 bg-yellow-50 hover:bg-yellow-100 transition">
                                                {{ __('Convert to InnoDB') }}
                                            </button>
                                        @endif
                                        @if(!($table['is_core'] ?? true))
                                            <button wire:click="confirmTableAction('delete', '{{ $table['name'] }}')"
                                                    class="rounded px-2 py-1 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 transition">
                                                {{ __('Delete') }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Desktop table --}}
                    <div class="hidden md:block overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <x-ui.th>{{ __('Name') }}</x-ui.th>
                                <x-ui.th>{{ __('Engine') }}</x-ui.th>
                                <x-ui.th>{{ __('Rows') }}</x-ui.th>
                                <x-ui.th>{{ __('Size') }}</x-ui.th>
                                <x-ui.th>{{ __('Overhead') }}</x-ui.th>
                                <x-ui.th class="text-right">{{ __('Actions') }}</x-ui.th>
                            </x-slot:head>
                            @foreach($this->latestHealthCheck->tables_data as $table)
                                @php
                                    $tableSize = ($table['data_size'] ?? 0) + ($table['index_size'] ?? 0);
                                    $isMyisam = strtolower($table['engine'] ?? '') === 'myisam';
                                    $hasOverhead = ($table['overhead'] ?? 0) > 1048576;
                                    $plugin = $table['plugin'] ?? null;
                                @endphp
                                <tr class="{{ $isMyisam ? 'bg-yellow-50' : ($plugin && $plugin['status'] === 'not-installed' ? 'bg-red-50/50' : '') }}">
                                    <x-ui.td>
                                        <div>
                                            <span class="text-sm font-mono text-gray-900">{{ $table['name'] ?? '—' }}</span>
                                            @if($plugin)
                                                <span class="ml-2 text-xs {{ $plugin['status'] === 'not-installed' ? 'text-red-600 font-medium' : ($plugin['status'] === 'inactive' ? 'text-yellow-600' : 'text-gray-400') }}">
                                                    {{ $plugin['name'] }}
                                                    @if($plugin['status'] === 'not-installed')
                                                        ({{ __('not installed') }})
                                                    @elseif($plugin['status'] === 'inactive')
                                                        ({{ __('inactive') }})
                                                    @endif
                                                </span>
                                            @elseif($table['is_core'] ?? false)
                                                <span class="ml-2 text-xs text-gray-400">{{ __('core') }}</span>
                                            @endif
                                        </div>
                                    </x-ui.td>
                                    <x-ui.td>
                                        <x-ui.badge :variant="$isMyisam ? 'yellow' : 'gray'">
                                            {{ $table['engine'] ?? '—' }}
                                        </x-ui.badge>
                                    </x-ui.td>
                                    <x-ui.td>{{ number_format($table['rows'] ?? 0) }}</x-ui.td>
                                    <x-ui.td>
                                        @if($tableSize >= 1048576)
                                            {{ round($tableSize / 1048576, 2) }} MB
                                        @elseif($tableSize >= 1024)
                                            {{ round($tableSize / 1024, 2) }} KB
                                        @else
                                            {{ $tableSize }} B
                                        @endif
                                    </x-ui.td>
                                    <x-ui.td>
                                        @if(($table['overhead'] ?? 0) > 0)
                                            <span class="{{ $hasOverhead ? 'text-red-600 font-medium' : 'text-gray-500' }}">
                                                @if(($table['overhead'] ?? 0) >= 1048576)
                                                    {{ round(($table['overhead'] ?? 0) / 1048576, 2) }} MB
                                                @elseif(($table['overhead'] ?? 0) >= 1024)
                                                    {{ round(($table['overhead'] ?? 0) / 1024, 2) }} KB
                                                @else
                                                    {{ $table['overhead'] ?? 0 }} B
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </x-ui.td>
                                    <x-ui.td class="text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            @if(($table['overhead'] ?? 0) > 0)
                                                <button wire:click="confirmTableAction('optimize', '{{ $table['name'] }}')"
                                                        class="rounded px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 transition"
                                                        title="{{ __('Optimize Table') }}">
                                                    {{ __('Optimize') }}
                                                </button>
                                            @endif
                                            @if($isMyisam)
                                                <button wire:click="confirmTableAction('convert_engine', '{{ $table['name'] }}')"
                                                        class="rounded px-2 py-1 text-xs font-medium text-yellow-700 bg-yellow-50 hover:bg-yellow-100 transition"
                                                        title="{{ __('Convert to InnoDB') }}">
                                                    {{ __('InnoDB') }}
                                                </button>
                                            @endif
                                            @if(!($table['is_core'] ?? true))
                                                <button wire:click="confirmTableAction('delete', '{{ $table['name'] }}')"
                                                        class="rounded px-2 py-1 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 transition"
                                                        title="{{ __('Delete Table') }}">
                                                    {{ __('Delete') }}
                                                </button>
                                            @endif
                                        </div>
                                    </x-ui.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                </x-ui.card>
            @endif
        @else
            <x-ui.card class="mb-4">
                <div class="py-8 text-center">
                    <x-icons.database class="mx-auto h-12 w-12 text-gray-300" />
                    <h3 class="mt-3 text-sm font-semibold text-gray-900">{{ __('No health data') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Click Refresh Health to run a database health check.') }}</p>
                </div>
            </x-ui.card>
        @endif
    </div>

    {{-- Divider --}}
    <div class="mb-8 border-t border-gray-200"></div>

    {{-- Database Cleanup Section --}}
    <div class="mb-6 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Database Cleanup') }}</h2>
        <x-ui.button variant="secondary" wire:click="loadStats" wire:loading.attr="disabled">
            <x-ui.spinner size="sm" class="mr-1 hidden" wire:loading.class.remove="hidden" wire:target="loadStats" />
            <span wire:loading.remove wire:target="loadStats">{{ __('Preview Cleanup') }}</span>
            <span wire:loading wire:target="loadStats">{{ __('Loading...') }}</span>
        </x-ui.button>
    </div>

    {{-- Safety banner --}}
    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
        <div class="flex items-center gap-2">
            <svg class="h-5 w-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm text-blue-800">{{ __('We recommend creating a backup before performing database cleanup.') }}</span>
        </div>
    </div>

    {{-- Stats cards --}}
    @if($stats)
        <x-ui.card class="mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('Cleanup Preview') }}</h3>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <label class="flex items-start gap-3 rounded-lg border p-3 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" wire:model="cleanRevisions" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ __('Revisions') }}</div>
                        <div class="text-lg font-bold text-gray-700">{{ number_format($stats['revisions'] ?? 0) }}</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 rounded-lg border p-3 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" wire:model="cleanAutoDrafts" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ __('Auto-Drafts') }}</div>
                        <div class="text-lg font-bold text-gray-700">{{ number_format($stats['auto_drafts'] ?? 0) }}</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 rounded-lg border p-3 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" wire:model="cleanTrashPosts" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ __('Trash Posts') }}</div>
                        <div class="text-lg font-bold text-gray-700">{{ number_format($stats['trash_posts'] ?? 0) }}</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 rounded-lg border p-3 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" wire:model="cleanSpamComments" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ __('Spam Comments') }}</div>
                        <div class="text-lg font-bold text-gray-700">{{ number_format($stats['spam_comments'] ?? 0) }}</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 rounded-lg border p-3 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" wire:model="cleanTrashComments" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ __('Trash Comments') }}</div>
                        <div class="text-lg font-bold text-gray-700">{{ number_format($stats['trash_comments'] ?? 0) }}</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 rounded-lg border p-3 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" wire:model="cleanTransients" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ __('Transients') }}</div>
                        <div class="text-lg font-bold text-gray-700">{{ number_format($stats['transients'] ?? 0) }}</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 rounded-lg border p-3 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" wire:model="cleanOrphanedMeta" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ __('Orphaned Meta') }}</div>
                        <div class="text-lg font-bold text-gray-700">{{ number_format($stats['orphaned_meta'] ?? 0) }}</div>
                    </div>
                </label>
            </div>

            <div class="mt-4 flex justify-end">
                <x-ui.button wire:click="confirmCleanup">
                    {{ __('Clean Now') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Cleanup History --}}
    @if($this->cleanupHistory->count() > 0)
        <x-ui.card :padding="false">
            <div class="px-4 py-3 border-b">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Cleanup History') }}</h3>
            </div>

            {{-- Mobile cards --}}
            <div class="md:hidden px-4 py-2 space-y-2">
                @foreach($this->cleanupHistory as $cleanup)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm text-gray-900">{{ $cleanup->cleaned_at?->format('M d, Y H:i') ?? '—' }}</span>
                            <x-ui.badge :variant="$cleanup->status === 'completed' ? 'green' : 'red'">
                                {{ ucfirst($cleanup->status) }}
                            </x-ui.badge>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                            <span>{{ __('Revisions') }}: <span class="text-gray-700">{{ $cleanup->revisions_deleted }}</span></span>
                            <span>{{ __('Drafts') }}: <span class="text-gray-700">{{ $cleanup->auto_drafts_deleted }}</span></span>
                            <span>{{ __('Trash') }}: <span class="text-gray-700">{{ $cleanup->trash_posts_deleted + $cleanup->trash_comments_deleted }}</span></span>
                            <span>{{ __('Spam') }}: <span class="text-gray-700">{{ $cleanup->spam_comments_deleted }}</span></span>
                            <span>{{ __('Transients') }}: <span class="text-gray-700">{{ $cleanup->transients_deleted }}</span></span>
                            <span>{{ __('Meta') }}: <span class="text-gray-700">{{ $cleanup->orphaned_meta_deleted }}</span></span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ __('Space saved') }}: <span class="text-gray-700">{{ $cleanup->formatted_space_saved }}</span></p>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block">
                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th>{{ __('Date') }}</x-ui.th>
                        <x-ui.th>{{ __('Revisions') }}</x-ui.th>
                        <x-ui.th>{{ __('Drafts') }}</x-ui.th>
                        <x-ui.th>{{ __('Trash') }}</x-ui.th>
                        <x-ui.th>{{ __('Spam') }}</x-ui.th>
                        <x-ui.th>{{ __('Transients') }}</x-ui.th>
                        <x-ui.th>{{ __('Meta') }}</x-ui.th>
                        <x-ui.th>{{ __('Space Saved') }}</x-ui.th>
                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                    </x-slot:head>
                    @foreach($this->cleanupHistory as $cleanup)
                        <tr>
                            <x-ui.td>{{ $cleanup->cleaned_at?->format('M d, Y H:i') ?? '—' }}</x-ui.td>
                            <x-ui.td>{{ $cleanup->revisions_deleted }}</x-ui.td>
                            <x-ui.td>{{ $cleanup->auto_drafts_deleted }}</x-ui.td>
                            <x-ui.td>{{ $cleanup->trash_posts_deleted + $cleanup->trash_comments_deleted }}</x-ui.td>
                            <x-ui.td>{{ $cleanup->spam_comments_deleted }}</x-ui.td>
                            <x-ui.td>{{ $cleanup->transients_deleted }}</x-ui.td>
                            <x-ui.td>{{ $cleanup->orphaned_meta_deleted }}</x-ui.td>
                            <x-ui.td>{{ $cleanup->formatted_space_saved }}</x-ui.td>
                            <x-ui.td>
                                <x-ui.badge :variant="$cleanup->status === 'completed' ? 'green' : 'red'">
                                    {{ ucfirst($cleanup->status) }}
                                </x-ui.badge>
                            </x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </div>
        </x-ui.card>
    @endif

    {{-- Confirmation Modal --}}
    <x-ui.modal name="confirm-cleanup" maxWidth="sm">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Confirm Database Cleanup') }}</h3>
            <p class="mt-2 text-sm text-gray-600">
                {{ __('This will permanently delete the selected items from the WordPress database. This action cannot be undone.') }}
            </p>
            <div class="mt-2 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-700">
                {{ __('Make sure you have a recent backup before proceeding.') }}
            </div>
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-confirm-cleanup')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="runCleanup" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="runCleanup">{{ __('Proceed with Cleanup') }}</span>
                    <span wire:loading wire:target="runCleanup">{{ __('Cleaning...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Table Action Confirmation Modal --}}
    <x-ui.modal name="confirm-table-action" maxWidth="sm">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">
                @if($pendingTableAction === 'delete')
                    {{ __('Delete Table') }}
                @elseif($pendingTableAction === 'convert_engine')
                    {{ __('Convert Table Engine') }}
                @else
                    {{ __('Optimize Table') }}
                @endif
            </h3>
            <p class="mt-2 text-sm text-gray-600">
                @if($pendingTableAction === 'delete')
                    {{ __('Are you sure you want to permanently delete the table') }}
                    <strong class="font-mono">{{ $pendingTableName }}</strong>?
                    {{ __('This action cannot be undone.') }}
                @elseif($pendingTableAction === 'convert_engine')
                    {{ __('Convert') }} <strong class="font-mono">{{ $pendingTableName }}</strong>
                    {{ __('from MyISAM to InnoDB? This may take a moment for large tables.') }}
                @else
                    {{ __('Optimize') }} <strong class="font-mono">{{ $pendingTableName }}</strong>?
                    {{ __('This will reclaim unused space and defragment the table.') }}
                @endif
            </p>

            @if($pendingTableAction === 'delete')
                <div class="mt-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    {{ __('Warning: This will permanently remove the table and all its data. Make sure you have a backup.') }}
                </div>
            @elseif($pendingTableAction === 'convert_engine')
                <div class="mt-2 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-700">
                    {{ __('The table will be briefly locked during conversion. This is safe for most sites.') }}
                </div>
            @endif

            @if($pendingTableAction && $pendingTableAction !== 'delete')
                <label class="mt-4 flex items-center gap-2 cursor-pointer">
                    <input type="checkbox"
                           wire:model="{{ $pendingTableAction === 'optimize' ? 'skipConfirmOptimize' : 'skipConfirmConvert' }}"
                           class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-600">{{ __('Don\'t ask again for this action') }}</span>
                </label>
            @endif

            <div class="mt-4 flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="cancelTableAction">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    :variant="$pendingTableAction === 'delete' ? 'danger' : 'primary'"
                    wire:click="executeTableAction"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="executeTableAction">
                        @if($pendingTableAction === 'delete')
                            {{ __('Delete Table') }}
                        @elseif($pendingTableAction === 'convert_engine')
                            {{ __('Convert to InnoDB') }}
                        @else
                            {{ __('Optimize') }}
                        @endif
                    </span>
                    <span wire:loading wire:target="executeTableAction">{{ __('Processing...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
