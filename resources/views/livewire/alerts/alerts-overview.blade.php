@php($alerts = $this->alerts)
@php($counts = $this->counts)

<div>
    <x-ui.page-header
        title="{{ __('Alerts') }}"
        subtitle="{{ __('The sites that need you, grouped by client.') }}"
    />

    {{-- Filters double as the summary — the numbers you would have read anyway. --}}
    <div class="mb-5 flex flex-wrap items-center gap-1 border-b border-gray-200 dark:border-gray-700">
        @php($tabs = [
            'all' => __('All'),
            'down' => __('Down'),
            'disconnected' => __('Disconnected'),
            'health' => __('Critical health'),
        ])
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition
                           {{ $filter === $key
                               ? 'border-accent-500 text-accent-600 dark:text-accent-400'
                               : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}">
                {{ $label }}
                @if(($counts[$key] ?? 0) > 0)
                    <span class="rounded-full px-1.5 text-xs
                                 {{ $key === 'all' ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }}">
                        {{ $counts[$key] }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    @forelse($alerts['groups'] as $group)
        <div class="mb-3 rounded-lg border bg-white p-4 shadow-sm dark:bg-gray-800/40
                    {{ $group['severity'] === 0 ? 'border-red-300 dark:border-red-500/40' : 'border-gray-200 dark:border-gray-700' }}">
            <div class="mb-2 flex items-center justify-between gap-3">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                    {{ $group['client'] }}
                    <span class="text-[11px] font-normal text-gray-400">
                        · {{ trans_choice(':n site|:n site-uri', count($group['sites']), ['n' => count($group['sites'])]) }}
                    </span>
                </p>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px]
                             {{ $group['severity'] === 0 ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' }}">
                    {{ $group['severity'] === 0 ? __('critical') : __('attention') }}
                </span>
            </div>

            @foreach($group['sites'] as $site)
                <div class="flex items-center gap-2 py-1 text-[13px]">
                    <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $site['severity'] === 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                    <a href="{{ $site['url'] }}" class="font-medium text-gray-800 hover:text-accent-600 dark:text-gray-100" wire:navigate>
                        {{ $site['name'] }}
                    </a>
                    <span class="truncate text-gray-400">— {{ $site['reasons'] }}</span>
                    @if($site['last_seen'])
                        <span class="ml-auto shrink-0 text-[11px] text-gray-400">{{ __('synced') }} {{ $site['last_seen'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <div class="rounded-lg border border-gray-200 p-10 text-center dark:border-gray-700">
            <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('No alerts.') }}</p>
            <p class="mt-1 text-[13px] text-gray-500">
                {{ trans_choice(':count site is healthy|:count sites are healthy', $alerts['healthy'], ['count' => $alerts['healthy']]) }}
            </p>
        </div>
    @endforelse

    @if($alerts['total'] > 0)
        <p class="mt-4 text-[13px] text-gray-500">
            {{ trans_choice(':count site is healthy|:count sites are healthy', $alerts['healthy'], ['count' => $alerts['healthy']]) }}
            ·
            <a href="{{ route('notifications.index') }}" class="text-accent-600 hover:underline" wire:navigate>
                {{ __('Notification history') }}
            </a>
        </p>
    @endif
</div>
