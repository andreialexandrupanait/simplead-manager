{{-- Poll only while the session is still moving. Without this the progress bar sat at whatever
     percentage the page happened to load at, and the only way to watch a backup was to keep
     pressing refresh — the V1 screen has had live progress for months. --}}
<div @if($enabled && $session->state->isActive()) wire:poll.3s @endif>
    @unless($enabled)
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('The Backup V2 console is not available.') }}</p>
        </div>
    @else
        @php $cp = $session->checkpoint ?? []; @endphp
        <div class="mb-6 flex items-center justify-between">
            <x-ui.page-header title="Backup session #{{ $session->id }}" subtitle="{{ $session->site?->name }} · {{ $session->type }}" />
            <a href="{{ $session->site ? route('backup-v2.site', $session->site->id) : route('backup-v2.index') }}" class="text-sm text-accent-600 hover:underline">&larr; {{ __('Back') }}</a>
        </div>

        <x-ui.flash-alert type="success" key="backup-v2-success" />
        <x-ui.flash-alert type="error" key="backup-v2-error" />

        {{-- State + progress --}}
        <x-ui.card class="mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <x-ui.badge :variant="$session->state->badgeVariant()">{{ $session->state->label() }}</x-ui.badge>
                    <span class="text-sm text-gray-500">{{ $session->stage }}</span>
                    @if($session->protected)<span class="text-xs text-amber-600">🔒 {{ __('protected') }}</span>@endif
                </div>
                <div class="flex gap-2">
                    <button wire:click="toggleProtected" class="text-xs text-gray-600 hover:underline">{{ $session->protected ? __('Unprotect') : __('Protect') }}</button>
                </div>
            </div>
            <div class="mt-3">
                <div class="h-2 w-full rounded-full bg-gray-100 dark:bg-gray-700">
                    <div class="h-2 rounded-full bg-accent-500" style="width: {{ $this->progressPercent }}%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500">{{ $this->progressPercent }}%</p>
            </div>
        </x-ui.card>

        {{-- Facts grid --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Session') }}</h3>
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('Type') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $session->type }}</dd>
                    <dt class="text-gray-500">{{ __('Resource profile') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $session->resource_profile }}</dd>
                    <dt class="text-gray-500">{{ __('Heartbeat') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $session->heartbeat_at?->diffForHumans() ?? '—' }}</dd>
                    <dt class="text-gray-500">{{ __('Attempts') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $session->attempt }} ({{ __('retries') }}: {{ $session->retry_count }})</dd>
                    <dt class="text-gray-500">{{ __('Duration') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $this->duration ?? '—' }}</dd>
                    <dt class="text-gray-500">{{ __('Verified') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $session->verified_at?->diffForHumans() ?? __('never') }}</dd>
                    <dt class="text-gray-500">{{ __('Expires') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $session->expires_at?->toDayDateTimeString() ?? '—' }}</dd>
                    <dt class="text-gray-500">{{ __('Format') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ $session->format_version }}</dd>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Chain') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-2">
                    {{ $session->full_base_id === null ? __('Full backup (chain base)') : __('Incremental, position').' '.$session->chain_position }}
                    @if($session->full_base_id)· {{ __('base') }} #{{ $session->full_base_id }}@endif
                </p>
                <div class="text-sm space-x-1">
                    @foreach($this->chain as $m)
                        <a href="{{ route('backup-v2.detail', $m->id) }}" class="{{ $m->id === $session->id ? 'font-semibold text-accent-600' : 'text-blue-600 hover:underline' }}">
                            {{ $m->full_base_id === null ? 'full' : 'inc'.$m->chain_position }}#{{ $m->id }}</a>@if(! $loop->last)<span class="text-gray-400">→</span>@endif
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        {{-- Errors --}}
        @if($session->error_code)
            <x-ui.card class="mb-6 border-red-200 dark:border-red-900/40">
                <h3 class="text-base font-semibold text-red-700 dark:text-red-400 mb-1">{{ __('Error') }}</h3>
                <p class="text-sm font-mono text-red-600">{{ $session->error_code }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">{{ $session->error_message }}</p>
            </x-ui.card>
        @endif

        {{-- Objects + checksums --}}
        <x-ui.card class="mb-6 overflow-hidden !p-0">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Objects & checksums') }} ({{ count($this->objects) }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Kind') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Key') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Size') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('SHA-256') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($this->objects as $o)
                            <tr>
                                <td class="px-4 py-2">{{ $o['kind'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $o['key'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">{{ \App\Helpers\FormatHelper::bytes((int) ($o['size'] ?? 0)) }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($o['sha256'] ?? '', 16, '…') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">{{ __('No confirmed objects.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Manifest / environment + storage --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Manifest / environment') }}</h3>
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-gray-500">WP</dt><dd class="text-gray-800 dark:text-gray-100">{{ $cp['capabilities']['wp_version'] ?? '—' }}</dd>
                    <dt class="text-gray-500">PHP</dt><dd class="text-gray-800 dark:text-gray-100">{{ $cp['capabilities']['php_version'] ?? '—' }}</dd>
                    <dt class="text-gray-500">DB</dt><dd class="text-gray-800 dark:text-gray-100">{{ $cp['db']['server_version'] ?? ($cp['capabilities']['db_server_version'] ?? '—') }}</dd>
                    <dt class="text-gray-500">{{ __('Files') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ number_format((int) ($cp['files_inventory']['total_files'] ?? 0)) }}</dd>
                    <dt class="text-gray-500">{{ __('Tables') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ (int) ($cp['db']['table_count'] ?? 0) }}</dd>
                    <dt class="text-gray-500">{{ __('Consistent snapshot') }}</dt><dd class="text-gray-800 dark:text-gray-100">{{ ($cp['capabilities']['consistent_snapshot'] ?? false) ? __('yes') : __('no') }}</dd>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Verification & restore') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                    {{ $session->verified_at ? __('Last verified').' '.$session->verified_at->diffForHumans() : __('Not yet verified') }}
                </p>
                @if($session->state->value === 'completed')
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button wire:click="restore('safe_merge')" wire:confirm="{{ __('Queue a safe-merge restore?') }}">{{ __('Restore (safe merge)') }}</x-ui.button>
                        <x-ui.button wire:click="restore('mirror')" variant="secondary" wire:confirm="{{ __('Mirror restore deletes live-only files. Continue?') }}">{{ __('Restore (mirror)') }}</x-ui.button>
                    </div>
                @else
                    <p class="text-sm text-gray-400">{{ __('Restore is available once the backup is completed.') }}</p>
                @endif
            </x-ui.card>
        </div>
    @endunless
</div>
