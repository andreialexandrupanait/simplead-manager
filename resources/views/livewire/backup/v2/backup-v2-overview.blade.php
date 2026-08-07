<div>
    @unless($enabled)
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('The Backup V2 console is not available.') }}</p>
        </div>
    @else
        <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <x-ui.page-header title="Backup V2" subtitle="{{ __('Next-gen backup engine — sessions, storage & alerts') }}" />
            <div class="flex items-center gap-2">
                {{-- The console had no way to reach the fleet view, and a migration nobody can find
                     is a migration nobody runs. --}}
                <x-ui.button href="{{ route('backup-v2.fleet') }}" variant="secondary" size="sm">
                    {{ __('Fleet & migration') }}
                </x-ui.button>
                {{-- Was violet: the last of the pre-retheme accent, on a screen themed WordPress blue. --}}
                <span class="inline-flex items-center gap-1 rounded-full bg-accent-50 px-2.5 py-1 text-xs font-semibold text-accent-700 dark:bg-accent-500/10 dark:text-accent-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-accent-500"></span> {{ __('Preview — engine V2') }}
                </span>
            </div>
        </div>

        {{-- Alerts --}}
        @if(! empty($this->alerts))
            <div class="mb-6 space-y-2">
                @foreach($this->alerts as $alert)
                    @php $isCrit = $alert['level'] === 'critical'; @endphp
                    <div class="flex items-center gap-2 rounded-lg border px-4 py-2.5 text-sm {{ $isCrit ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-300' : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $isCrit ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                        {{ $alert['message'] }}
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            @php
                $cards = [
                    ['label' => __('Active'), 'value' => $this->stats['active'], 'color' => 'text-accent-600'],
                    ['label' => __('Failed'), 'value' => $this->stats['failed'], 'color' => 'text-red-600'],
                    ['label' => __('Paused'), 'value' => $this->stats['paused'], 'color' => 'text-amber-600'],
                    ['label' => __('Stale'), 'value' => $this->stats['stale'], 'color' => 'text-orange-600'],
                    ['label' => __('Completed'), 'value' => $this->stats['completed'], 'color' => 'text-green-600'],
                    ['label' => __('Proven restores'), 'value' => $this->stats['proven_restore'], 'color' => 'text-blue-600'],
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

        {{-- Storage usage + quotas --}}
        <x-ui.card class="mb-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Destination health & storage usage') }}</h3>
            @if($this->destinations->isEmpty())
                <p class="text-sm text-gray-500">{{ __('No storage destinations configured.') }}</p>
            @else
                <div class="space-y-3">
                    @foreach($this->destinations as $d)
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full {{ $d['healthy'] ? 'bg-green-500' : 'bg-red-500' }}"></span>
                                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ $d['model']->name }}</span>
                                    <span class="text-xs text-gray-400">{{ $d['model']->type }}</span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ \App\Helpers\FormatHelper::bytes($d['used_bytes']) }}
                                    @if($d['quota_bytes']) / {{ \App\Helpers\FormatHelper::bytes($d['quota_bytes']) }} ({{ $d['percent'] }}%) @else <span class="text-gray-400">/ {{ __('unbounded') }}</span> @endif
                                </div>
                            </div>
                            @if($d['quota_bytes'])
                                <div class="mt-1.5 h-1.5 w-full rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-1.5 rounded-full {{ $d['over_warn'] ? 'bg-red-500' : 'bg-accent-500' }}" style="width: {{ min(100, (float) $d['percent']) }}%"></div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Active sessions --}}
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Active sessions') }}</h3>
                @forelse($this->activeSessions as $s)
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 py-2 last:border-0 text-sm">
                        <a href="{{ route('backup-v2.detail', $s->id) }}" class="font-medium text-gray-800 dark:text-gray-100 hover:text-accent-600">#{{ $s->id }} · {{ $s->site?->name ?? '—' }}</a>
                        <span class="text-xs text-gray-500">{{ $s->state->value }} · {{ $s->stage }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No active sessions.') }}</p>
                @endforelse
            </x-ui.card>

            {{-- Failed sessions --}}
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Failed / corrupt sessions') }}</h3>
                @forelse($this->failedSessions as $s)
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 py-2 last:border-0 text-sm">
                        <a href="{{ route('backup-v2.detail', $s->id) }}" class="font-medium text-red-700 dark:text-red-400 hover:underline">#{{ $s->id }} · {{ $s->site?->name ?? '—' }}</a>
                        <span class="text-xs text-gray-500">{{ $s->error_code ?? $s->state->value }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No failed sessions.') }}</p>
                @endforelse
            </x-ui.card>

            {{-- Stale sessions --}}
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Stale sessions') }}</h3>
                @forelse($this->staleSessions as $s)
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 py-2 last:border-0 text-sm">
                        <a href="{{ route('backup-v2.detail', $s->id) }}" class="font-medium text-gray-800 dark:text-gray-100 hover:text-accent-600">#{{ $s->id }} · {{ $s->site?->name ?? '—' }}</a>
                        <span class="text-xs text-gray-500">{{ $s->heartbeat_at ? $s->heartbeat_at->diffForHumans() : __('no heartbeat') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No stale sessions.') }}</p>
                @endforelse
            </x-ui.card>

            {{-- Latest restores + proven restore --}}
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Latest restores') }}</h3>
                @forelse($this->latestRestores as $r)
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 py-2 last:border-0 text-sm">
                        <span class="font-medium text-gray-800 dark:text-gray-100">#{{ $r->id }} · {{ $r->site?->name ?? '—' }}</span>
                        <span class="text-xs text-gray-500">{{ $r->state->value }} · {{ $r->mode }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No restores yet.') }}</p>
                @endforelse
            </x-ui.card>
        </div>

        {{-- Incomplete backups (orphan objects risk) --}}
        <x-ui.card class="mt-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Incomplete backups (orphan objects)') }}</h3>
            @forelse($this->incompleteSessions as $s)
                <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 py-2 last:border-0 text-sm">
                    <a href="{{ route('backup-v2.detail', $s->id) }}" class="font-medium text-gray-800 dark:text-gray-100 hover:text-accent-600">#{{ $s->id }} · {{ $s->site?->name ?? '—' }}</a>
                    <span class="text-xs text-gray-500">{{ $s->error_message ? Str::limit($s->error_message, 60) : $s->state->value }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('No incomplete backups.') }}</p>
            @endforelse
        </x-ui.card>
    @endunless
</div>
