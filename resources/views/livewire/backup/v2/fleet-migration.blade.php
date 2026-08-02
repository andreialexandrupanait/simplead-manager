<div @if($runId) wire:poll.4s @endif>
    @unless($enabled)
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('The Backup V2 console is not available.') }}</p>
        </div>
    @else
        <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <x-ui.page-header
                title="{{ __('Backup V2 — fleet') }}"
                subtitle="{{ __('Which sites run the new engine, which are ready, and what is holding the rest back') }}" />
            <div class="flex flex-wrap gap-2">
                <x-ui.button wire:click="refreshVersions" variant="ghost" wire:loading.attr="disabled">
                    {{ __('Refresh versions') }}
                </x-ui.button>
                <x-ui.button wire:click="migrateAllReady"
                             wire:confirm="{{ __('Migrate every ready site to the V2 engine?') }}"
                             wire:loading.attr="disabled">
                    {{ __('Migrate everything ready') }}
                </x-ui.button>
            </div>
        </div>

        <x-ui.flash-alert type="success" key="fleet-success" />
        <x-ui.flash-alert type="error" key="fleet-error" />

        {{-- Summary --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            @php
                $cards = [
                    ['label' => __('Sites'), 'value' => $this->summary['total'], 'color' => 'text-gray-900 dark:text-white'],
                    ['label' => __('On V2'), 'value' => $this->summary['on_v2'], 'color' => 'text-green-600'],
                    ['label' => __('Ready to migrate'), 'value' => $this->summary['ready'], 'color' => 'text-accent-600'],
                    ['label' => __('Not ready'), 'value' => $this->summary['blocked'], 'color' => 'text-amber-600'],
                ];
            @endphp
            @foreach($cards as $c)
                <x-ui.card>
                    <div class="text-center">
                        <p class="text-2xl font-semibold {{ $c['color'] }}">{{ $c['value'] }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ $c['label'] }}</p>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Fleet-wide switches --}}
        <x-ui.card class="mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Restores from the V2 engine') }}</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        {{ __('When off, a V2 restore can be requested but will refuse to run. The site is never touched either way.') }}
                    </p>
                </div>
                <x-ui.button wire:click="toggleRestore" :variant="$this->restoreEnabled ? 'secondary' : 'primary'">
                    {{ $this->restoreEnabled ? __('Disable restores') : __('Enable restores') }}
                </x-ui.button>
            </div>
        </x-ui.card>

        {{-- Progress of a running migration --}}
        @if(! empty($this->progress))
            <x-ui.card class="mb-6">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('This run') }}</h3>
                <div class="space-y-2">
                    @foreach($this->progress as $entry)
                        <div class="flex items-start gap-2 text-sm">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $entry['ok'] ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            <div>
                                <span class="font-medium text-gray-900 dark:text-white">{{ $entry['site'] }}</span>
                                <span class="text-gray-600 dark:text-gray-300">— {{ $entry['message'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        {{-- Filter --}}
        <div class="mb-3 flex flex-wrap gap-2 text-sm">
            @foreach(['all' => __('All'), 'pending' => __('Ready to migrate'), 'done' => __('On V2'), 'blocked' => __('Not ready')] as $key => $label)
                <button wire:click="$set('filter', '{{ $key }}')"
                        class="rounded-full px-3 py-1 {{ $filter === $key ? 'bg-accent-600 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- The fleet --}}
        <x-ui.card>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-3">{{ __('Site') }}</th>
                            <th class="py-2 pr-3">{{ __('Connector') }}</th>
                            <th class="py-2 pr-3">{{ __('Backup engine') }}</th>
                            <th class="py-2 pr-3">{{ __('Runs') }}</th>
                            <th class="py-2 pr-3">{{ __('Storage') }}</th>
                            <th class="py-2 pr-3">{{ __('Schedule') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($this->rows as $row)
                            @php $site = $row['site']; @endphp
                            <tr>
                                <td class="py-2.5 pr-3">
                                    <a href="{{ route('sites.overview', $site) }}" class="font-medium text-gray-900 hover:text-accent-600 dark:text-white">{{ $site->name }}</a>
                                    <p class="text-xs text-gray-400">{{ $site->domain }}</p>
                                    @if($row['blocked_by'])
                                        {{-- The reason travels with the row. "Not ready" on its own is a
                                             question nobody can act on. --}}
                                        <p class="text-xs text-amber-600 mt-1">{{ $row['blocked_by'] }}</p>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3">
                                    <span class="{{ $row['connector_ok'] ? 'text-gray-600 dark:text-gray-300' : 'text-amber-600' }}">
                                        {{ $row['connector'] ?: '—' }}
                                    </span>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <span class="{{ $row['plugin_ok'] ? 'text-gray-600 dark:text-gray-300' : 'text-amber-600' }}">
                                        {{ $row['plugin'] ?: '—' }}
                                    </span>
                                </td>
                                <td class="py-2.5 pr-3">
                                    @if($row['effective_engine']->value === 'v2')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/30 dark:text-green-300">V2</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">V1</span>
                                    @endif
                                    @if($row['engine'] !== $row['effective_engine'])
                                        {{-- The column says one thing and the gate another; what runs
                                             tonight is the gate's answer, so say so rather than show
                                             an intention as a fact. --}}
                                        <span class="ml-1 text-xs text-amber-600">{{ __('(set to :engine)', ['engine' => strtoupper($row['engine']->value)]) }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3 text-gray-600 dark:text-gray-300">{{ $row['destination'] ?: '—' }}</td>
                                <td class="py-2.5 pr-3">
                                    @if($row['scheduled'])
                                        <span class="text-gray-600 dark:text-gray-300">{{ __('on') }}</span>
                                    @else
                                        <span class="text-gray-400">{{ __('off') }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3">
                                    <div class="flex flex-wrap justify-end gap-1.5">
                                        @if($row['ready'] && $row['steps'] !== [])
                                            <x-ui.button size="xs" wire:click="migrateSite({{ $site->id }})">{{ __('Migrate') }}</x-ui.button>
                                        @endif
                                        @if($row['ready'] && ! $row['connector_ok'])
                                            <x-ui.button size="xs" variant="ghost" wire:click="pushConnector({{ $site->id }})">{{ __('Connector') }}</x-ui.button>
                                        @endif
                                        @if($row['ready'] && $row['connector_ok'] && ! $row['plugin_ok'])
                                            <x-ui.button size="xs" variant="ghost" wire:click="installPlugin({{ $site->id }})">{{ __('Engine') }}</x-ui.button>
                                        @endif
                                        @if($row['engine']->value === 'v2')
                                            <x-ui.button size="xs" variant="ghost"
                                                         wire:click="setEngine({{ $site->id }}, 'v1')"
                                                         wire:confirm="{{ __('Put this site back on the V1 engine?') }}">
                                                {{ __('Back to V1') }}
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-sm text-gray-500">{{ __('No sites match this filter.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endunless
</div>
