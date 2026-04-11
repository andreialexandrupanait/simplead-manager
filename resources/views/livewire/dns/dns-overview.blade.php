<div>
    <div class="mb-6">
        <x-ui.page-header title="{{ __('DNS Monitoring') }}" subtitle="{{ __('Track DNS record changes across all sites') }}" />
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Active Monitors') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold {{ $this->stats['with_changes'] > 0 ? 'text-yellow-600' : 'text-green-600' }}">{{ $this->stats['with_changes'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('With Changes') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-purple-600">{{ $this->stats['recent_changes'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Changes (7d)') }}</p>
            </div>
        </x-ui.card>
    </div>

    <div class="mb-4">
        <x-ui.filter-tabs
            :options="['monitors' => __('Monitors'), 'changes' => __('Recent Changes')]"
            :selected="$tab"
            wire="tab"
        />
    </div>

    @if($tab === 'monitors')
        <x-ui.card class="!p-0 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Domain') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Site') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Last Check') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($monitors as $monitor)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ $monitor->domain }}</td>
                            <td class="px-4 py-3">
                                @if($monitor->site)
                                    <a href="{{ route('sites.overview', $monitor->site) }}" class="text-sm text-purple-600 hover:underline" wire:navigate>{{ $monitor->site->name }}</a>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($monitor->has_changes)
                                    <x-ui.badge variant="yellow">{{ __('Changes detected') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="green">{{ __('Stable') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $monitor->last_checked_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-12 text-center text-sm text-gray-500">{{ __('No DNS monitors configured. Monitors are created automatically when sites are added.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
        @if($monitors instanceof \Illuminate\Pagination\LengthAwarePaginator && $monitors->hasPages())
            <div class="mt-4">{{ $monitors->links() }}</div>
        @endif
    @else
        <x-ui.card class="!p-0 overflow-hidden">
            @forelse($changes as $change)
                <div class="flex gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <div class="h-7 w-7 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center shrink-0 mt-0.5">
                        <svg class="h-3.5 w-3.5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <x-ui.badge variant="purple">{{ $change->record_type }}</x-ui.badge>
                            @if($change->monitor?->site)
                                <a href="{{ route('sites.overview', $change->monitor->site) }}" class="text-sm text-purple-600 hover:underline" wire:navigate>{{ $change->monitor->domain }}</a>
                            @else
                                <span class="text-sm text-gray-900 dark:text-white">{{ $change->monitor?->domain ?? '—' }}</span>
                            @endif
                            @if($change->acknowledged_at)
                                <span class="text-[10px] text-green-500">{{ __('Acknowledged') }}</span>
                            @endif
                        </div>
                        <div class="mt-1 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-gray-400">{{ __('Before') }}:</span>
                                <span class="text-red-600 font-mono">{{ is_array($change->old_value) ? implode(', ', array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $change->old_value)) : ($change->old_value ?? '—') }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">{{ __('After') }}:</span>
                                <span class="text-green-600 font-mono">{{ is_array($change->new_value) ? implode(', ', array_map(fn($v) => is_array($v) ? json_encode($v) : $v, $change->new_value)) : ($change->new_value ?? '—') }}</span>
                            </div>
                        </div>
                        <div class="mt-1 text-[11px] text-gray-400">{{ $change->detected_at->diffForHumans() }}</div>
                    </div>
                    @unless($change->acknowledged_at)
                        <button wire:click="acknowledge({{ $change->id }})" class="shrink-0 self-center text-xs text-gray-400 hover:text-green-600" title="{{ __('Acknowledge') }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    @endunless
                </div>
            @empty
                <div class="py-12 text-center text-sm text-gray-500">{{ __('No DNS changes detected yet.') }}</div>
            @endforelse
        </x-ui.card>
        @if($changes instanceof \Illuminate\Pagination\LengthAwarePaginator && $changes->hasPages())
            <div class="mt-4">{{ $changes->links() }}</div>
        @endif
    @endif
</div>
