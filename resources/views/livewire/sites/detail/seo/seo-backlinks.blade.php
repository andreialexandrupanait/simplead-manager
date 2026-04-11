<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    <x-ui.page-header title="{{ __('Backlinks') }}" subtitle="{{ __('External links pointing to your site — discover, verify, and monitor') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="sync" :jobs="$trackedJobs" title="{{ __('Syncing backlinks...') }}" />

    {{-- Action Bar --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="primary" size="sm" wire:click="syncAll" wire:loading.attr="disabled" wire:target="syncAll">
                <span wire:loading.remove wire:target="syncAll">{{ __('Sync All') }}</span>
                <span wire:loading wire:target="syncAll">{{ __('Syncing...') }}</span>
            </x-ui.button>
            <button wire:click="toggleImportForm"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                {{ __('Import CSV') }}
            </button>
        </div>
        @if($this->lastSyncAt)
            <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('Last synced') }}: {{ $this->lastSyncAt }}</span>
        @endif
    </div>

    {{-- CSV Import Form --}}
    @if($showImportForm)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Import Backlinks from CSV') }}</h3>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">{{ __('Compatible with Ubersuggest, Ahrefs, SEMrush exports. Required: source_url, target_url. Optional: anchor_text, nofollow.') }}</p>
            <form wire:submit="importCsv" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <input type="file" wire:model="csvFile" accept=".csv,.txt" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-purple-50 file:px-3 file:py-1 file:text-xs file:font-medium file:text-purple-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300" />
                    @error('csvFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="importCsv">{{ __('Import') }}</x-ui.button>
                    <button type="button" wire:click="toggleImportForm" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('Cancel') }}</button>
                </div>
            </form>
        </div>
    @endif

    @php $stats = $this->stats; @endphp

    {{-- Stats Cards --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Total') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Ref. Domains') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['referring_domains']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('New (30d)') }}</p>
            <p class="mt-1 text-2xl font-bold text-green-600 dark:text-green-400">+{{ number_format($stats['new_last_30_days']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Lost (30d)') }}</p>
            <p class="mt-1 text-2xl font-bold text-red-600 dark:text-red-400">-{{ number_format($stats['lost_last_30_days']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Toxic') }}</p>
            @php $spam = $this->spamDistribution; @endphp
            <p class="mt-1 text-2xl font-bold {{ $spam['toxic'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">{{ $spam['toxic'] }}</p>
        </div>
    </div>

    {{-- Spam Distribution + Anchor Types --}}
    @if($stats['total'] > 0)
        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Spam Distribution --}}
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Link Quality') }}</h3>
                @php
                    $spamTotal = $spam['clean'] + $spam['suspicious'] + $spam['toxic'];
                    $cleanPct = $spamTotal > 0 ? round($spam['clean'] / $spamTotal * 100) : 0;
                    $suspPct = $spamTotal > 0 ? round($spam['suspicious'] / $spamTotal * 100) : 0;
                    $toxicPct = $spamTotal > 0 ? 100 - $cleanPct - $suspPct : 0;
                @endphp
                <div class="mb-4 flex h-4 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                    @if($cleanPct > 0)<div class="bg-green-500" style="width: {{ $cleanPct }}%"></div>@endif
                    @if($suspPct > 0)<div class="bg-yellow-500" style="width: {{ $suspPct }}%"></div>@endif
                    @if($toxicPct > 0)<div class="bg-red-500" style="width: {{ $toxicPct }}%"></div>@endif
                </div>
                <div class="flex justify-between text-xs">
                    <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full bg-green-500"></span> {{ __('Clean') }} ({{ $spam['clean'] }})</span>
                    <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full bg-yellow-500"></span> {{ __('Suspicious') }} ({{ $spam['suspicious'] }})</span>
                    <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full bg-red-500"></span> {{ __('Toxic') }} ({{ $spam['toxic'] }})</span>
                </div>
            </x-ui.card>

            {{-- Anchor Text Types --}}
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Anchor Text Types') }}</h3>
                @php $anchorTypes = $this->anchorTextTypes; @endphp
                @if(empty($anchorTypes))
                    <p class="py-4 text-center text-sm text-gray-400">{{ __('Run Sync to analyze anchor texts') }}</p>
                @else
                    <div class="space-y-2">
                        @php
                            $colors = ['brand' => 'bg-purple-500', 'exact_match' => 'bg-blue-500', 'partial_match' => 'bg-cyan-500', 'generic' => 'bg-gray-400', 'url' => 'bg-orange-500', 'image' => 'bg-pink-500', 'other' => 'bg-gray-300'];
                        @endphp
                        @foreach($anchorTypes as $at)
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $colors[$at['type']] ?? 'bg-gray-300' }}"></span>
                                    <span class="text-gray-700 dark:text-gray-300">{{ $at['label'] }}</span>
                                </div>
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $at['count'] }} ({{ $at['percent'] }}%)</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </div>
    @endif

    {{-- Section Tabs --}}
    <div class="mb-4 flex gap-2">
        @foreach(['backlinks' => 'Backlinks', 'domains' => 'Referring Domains', 'pages' => 'Top Pages'] as $section => $label)
            <button wire:click="setSection('{{ $section }}')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeSection === $section ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700' }}">
                {{ __($label) }}
            </button>
        @endforeach
    </div>

    {{-- Backlinks List --}}
    @if($activeSection === 'backlinks')
        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-3">
            <select wire:model.live="spamFilter" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="all">{{ __('All Quality') }}</option>
                <option value="clean">{{ __('Clean') }} ({{ $spam['clean'] }})</option>
                <option value="suspicious">{{ __('Suspicious') }} ({{ $spam['suspicious'] }})</option>
                <option value="toxic">{{ __('Toxic') }} ({{ $spam['toxic'] }})</option>
            </select>
            <select wire:model.live="typeFilter" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="all">{{ __('All Types') }}</option>
                <option value="dofollow">{{ __('Dofollow') }} ({{ $stats['dofollow'] }})</option>
                <option value="nofollow">{{ __('Nofollow') }} ({{ $stats['nofollow'] }})</option>
            </select>
        </div>

        @php $backlinks = $this->backlinksList; @endphp
        @if($backlinks->isEmpty())
            <x-ui.card>
                <div class="py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                    <h3 class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('No backlinks found') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Click "Sync All" to discover backlinks from Google Search Console, or import a CSV.') }}</p>
                </div>
            </x-ui.card>
        @else
            <x-ui.card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Source') }}</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Anchor') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Type') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Spam') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Position') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('First Seen') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Live') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($backlinks as $bl)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                    <td class="max-w-xs px-4 py-2.5">
                                        <div class="min-w-0">
                                            <a href="{{ $bl->source_url }}" target="_blank" class="block truncate text-xs font-medium text-purple-600 hover:text-purple-800 dark:text-purple-400" title="{{ $bl->source_url }}">{{ $bl->source_domain !== 'gsc-aggregate' ? $bl->source_url : $bl->target_url }}</a>
                                            @if($bl->page_title)
                                                <p class="mt-0.5 truncate text-xs text-gray-400">{{ $bl->page_title }}</p>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="max-w-[150px] px-4 py-2.5">
                                        <span class="truncate text-xs text-gray-700 dark:text-gray-300">{{ $bl->anchor_text ?: '—' }}</span>
                                        @if($bl->anchor_type)
                                            <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-400">{{ $bl->anchor_type }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        @if($bl->is_nofollow)
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">nofollow</span>
                                        @else
                                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">dofollow</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        @php $spamScore = $bl->spam_score ?? 0; @endphp
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold {{ match($bl->spam_color) { 'red' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'yellow' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', default => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' } }}">{{ $spamScore }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center text-xs text-gray-500">{{ $bl->link_position ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-center text-xs text-gray-500">{{ $bl->first_seen_at?->format('M d') }}</td>
                                    <td class="px-4 py-2.5 text-center">
                                        @if($bl->is_alive)
                                            <span class="text-green-500">&#10003;</span>
                                        @else
                                            <span class="text-red-500">&#10007;</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    {{ $backlinks->links() }}
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- Referring Domains --}}
    @if($activeSection === 'domains')
        @php $domains = $this->referringDomains; @endphp
        @if(empty($domains))
            <x-ui.card>
                <div class="py-8 text-center text-sm text-gray-400">{{ __('No referring domains found. Run Sync first.') }}</div>
            </x-ui.card>
        @else
            <x-ui.card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                                <th class="px-5 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Domain') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Links') }}</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400" style="min-width:200px">{{ __('Spam Score') }}</th>
                                <th class="px-4 py-2.5 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Last Seen') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($domains as $d)
                                @php
                                    $barColor = $d['avg_spam'] >= 60 ? 'bg-red-500' : ($d['avg_spam'] >= 30 ? 'bg-yellow-500' : 'bg-green-500');
                                    $label = $d['avg_spam'] >= 60 ? 'Toxic' : ($d['avg_spam'] >= 30 ? 'Suspicious' : 'Clean');
                                @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                    <td class="px-5 py-2.5 text-xs font-medium text-gray-900 dark:text-white">{{ $d['domain'] }}</td>
                                    <td class="px-4 py-2.5 text-center text-xs font-medium text-gray-700 dark:text-gray-300">{{ $d['link_count'] }}</td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div class="{{ $barColor }}" style="width: {{ $d['avg_spam'] }}%; height: 100%"></div>
                                            </div>
                                            <span class="text-xs text-gray-500">{{ $d['avg_spam'] }} ({{ $label }})</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-xs text-gray-500">{{ $d['last_seen'] ? \Carbon\Carbon::parse($d['last_seen'])->format('M d') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- Top Pages --}}
    @if($activeSection === 'pages')
        @php $pages = $this->topLinkedPages; @endphp
        @if(empty($pages))
            <x-ui.card>
                <div class="py-8 text-center text-sm text-gray-400">{{ __('No linked pages found. Run Sync first.') }}</div>
            </x-ui.card>
        @else
            <x-ui.card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                                <th class="px-5 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Page') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Backlinks') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Ref. Domains') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($pages as $page)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                    <td class="max-w-md px-5 py-2.5"><a href="{{ $page['target_url'] }}" target="_blank" class="block truncate text-xs text-purple-600 hover:text-purple-800 dark:text-purple-400">{{ $page['target_url'] }}</a></td>
                                    <td class="px-4 py-2.5 text-center text-xs font-medium text-gray-700 dark:text-gray-300">{{ number_format($page['backlink_count']) }}</td>
                                    <td class="px-4 py-2.5 text-center text-xs text-gray-500 dark:text-gray-400">{{ number_format($page['domain_count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    @endif
</div>
