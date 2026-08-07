{{-- Same reason as the detail screen: poll only while something on this site is still running, so
     an idle console costs nothing. --}}
<div @if($enabled && $this->history->contains(fn ($s) => $s->state->isActive())) wire:poll.4s @endif>
    @unless($enabled)
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('The Backup V2 console is not available.') }}</p>
        </div>
    @else
        <div class="mb-6">
            <x-ui.page-header title="Backup V2 — {{ $site->name }}" subtitle="{{ $site->url }}" />
        </div>

        <x-ui.flash-alert type="success" key="backup-v2-success" />
        <x-ui.flash-alert type="error" key="backup-v2-error" />

        {{-- New backup --}}
        <x-ui.card class="mb-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">{{ __('New backup') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('Resource profile') }}</label>
                    <select wire:model="resourceProfile" class="w-full rounded-md border-gray-300 text-sm dark:bg-gray-700 dark:border-gray-600">
                        <option value="low_impact">low_impact</option>
                        <option value="normal">normal</option>
                        <option value="fast">fast</option>
                    </select>
                    <div class="mt-3 flex items-center gap-4 text-sm">
                        <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="scopeDatabase" class="rounded"> {{ __('Database') }}</label>
                        <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="scopeFiles" class="rounded"> {{ __('Files') }}</label>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('Exclusions (one glob per line)') }}</label>
                    <textarea wire:model="exclusionsText" rows="3" class="w-full rounded-md border-gray-300 text-sm font-mono dark:bg-gray-700 dark:border-gray-600" placeholder="*.log&#10;wp-content/cache/*"></textarea>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.button wire:click="startBackup('full')">{{ __('Backup now (Full)') }}</x-ui.button>
                <x-ui.button wire:click="startBackup('incremental')" variant="secondary">{{ __('Incremental') }}</x-ui.button>
                <x-ui.button wire:click="startBackup('database')" variant="secondary">{{ __('Database only') }}</x-ui.button>
                <x-ui.button wire:click="startBackup('files')" variant="secondary">{{ __('Files only') }}</x-ui.button>
                <x-ui.button wire:click="preview" variant="ghost">{{ __('Preview / estimate') }}</x-ui.button>
            </div>

            @if($previewResult !== null)
                <div class="mt-4 rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3 text-sm text-gray-600 dark:text-gray-300">
                    <p class="font-medium mb-1">{{ __('Estimate') }} @if($previewResult['based_on_session']) <span class="text-xs text-gray-400">({{ __('based on session') }} #{{ $previewResult['based_on_session'] }})</span> @endif</p>
                    <p>{{ __('Files') }}: {{ number_format($previewResult['total_files']) }} · {{ \App\Helpers\FormatHelper::bytes($previewResult['total_bytes']) }}</p>
                    <p>{{ __('Excluded') }}: {{ number_format($previewResult['excluded_files']) }} · {{ \App\Helpers\FormatHelper::bytes($previewResult['excluded_bytes']) }}</p>
                    @if(! empty($previewResult['exclusions']))
                        <p class="mt-1 text-xs text-gray-400">{{ __('Rules') }}: {{ implode(', ', $previewResult['exclusions']) }}</p>
                    @endif
                </div>
            @endif
        </x-ui.card>

        {{-- Storage destination --}}
        @if($this->destination)
            <x-ui.card class="mb-6">
                <div class="flex items-center justify-between text-sm">
                    <div>
                        <span class="text-xs text-gray-500">{{ __('Storage destination') }}</span>
                        <p class="font-medium text-gray-800 dark:text-gray-100">{{ $this->destination['model']->name }} <span class="text-xs text-gray-400">({{ $this->destination['model']->type }})</span></p>
                    </div>
                    <div class="text-right text-xs text-gray-500">
                        {{ \App\Helpers\FormatHelper::bytes($this->destination['used_bytes']) }}
                        @if($this->destination['quota_bytes']) / {{ \App\Helpers\FormatHelper::bytes($this->destination['quota_bytes']) }} ({{ $this->destination['percent'] }}%) @endif
                    </div>
                </div>
            </x-ui.card>
        @endif

        {{-- History --}}
        <x-ui.card class="mb-6 overflow-hidden !p-0">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('History') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">#</th>
                            <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('State') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Heartbeat') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Attempt') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($this->history as $s)
                            <tr>
                                <td class="px-4 py-2"><a href="{{ route('backup-v2.detail', $s->id) }}" class="text-accent-600 hover:underline">#{{ $s->id }}</a></td>
                                <td class="px-4 py-2">{{ $s->type }}@if($s->protected) <span class="ml-1 text-xs text-amber-600">🔒</span>@endif</td>
                                <td class="px-4 py-2">
                                    <x-ui.badge :variant="$s->state->badgeVariant()">{{ $s->state->label() }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-2 text-gray-500 text-xs">{{ $s->heartbeat_at?->diffForHumans() ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $s->attempt }}</td>
                                {{-- Actions carry the same weight here as they do everywhere else in
                                     the app. They used to be bare text links, so the V2 console
                                     read as a debug page next to the V1 screen it replaces. --}}
                                <td class="px-4 py-2 text-right whitespace-nowrap">
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        @if($s->state->isActive() && $s->state->value !== 'paused')
                                            <x-ui.button wire:click="pauseSession({{ $s->id }})" variant="secondary" size="xs">{{ __('Pause') }}</x-ui.button>
                                            <x-ui.button wire:click="cancelSession({{ $s->id }})" variant="secondary" size="xs">{{ __('Cancel') }}</x-ui.button>
                                        @endif
                                        @if(in_array($s->state->value, ['paused','retry_wait']))
                                            <x-ui.button wire:click="resumeSession({{ $s->id }})" variant="secondary" size="xs">{{ __('Resume') }}</x-ui.button>
                                        @endif
                                        @if(in_array($s->state->value, ['failed','corrupt']))
                                            <x-ui.button wire:click="retrySession({{ $s->id }})" variant="secondary" size="xs">{{ __('Retry') }}</x-ui.button>
                                        @endif
                                        @if($s->state->value === 'completed')
                                            <x-ui.button wire:click="verifySession({{ $s->id }})" variant="secondary" size="xs">{{ __('Verify') }}</x-ui.button>
                                            <x-ui.button wire:click="restoreSession({{ $s->id }})" variant="secondary" size="xs">{{ __('Restore') }}</x-ui.button>
                                        @endif
                                        <x-ui.button wire:click="protectSession({{ $s->id }}, {{ $s->protected ? 'false' : 'true' }})" variant="ghost" size="xs">{{ $s->protected ? __('Unprotect') : __('Protect') }}</x-ui.button>
                                        <x-ui.button wire:click="deleteSession({{ $s->id }})" wire:confirm="{{ __('Delete this backup?') }}" variant="danger" size="xs">{{ __('Delete') }}</x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">{{ __('No V2 backups for this site yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Chains --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">{{ __('Chains') }}</h3>
            @forelse($this->chains as $chain)
                <div class="mb-3 last:mb-0 text-sm">
                    <a href="{{ route('backup-v2.detail', $chain['full']->id) }}" class="font-medium text-gray-800 dark:text-gray-100 hover:text-accent-600">{{ __('Full') }} #{{ $chain['full']->id }}</a>
                    @foreach($chain['increments'] as $inc)
                        <span class="text-gray-400">→</span>
                        <a href="{{ route('backup-v2.detail', $inc->id) }}" class="text-blue-600 hover:underline">inc{{ $inc->chain_position }} #{{ $inc->id }}</a>
                    @endforeach
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('No chains yet.') }}</p>
            @endforelse
        </x-ui.card>
    @endunless
</div>
