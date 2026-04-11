<div>
    <x-ui.page-header title="{{ __('Competitor Analysis') }}" subtitle="{{ __('Track and compare keyword positions with competitors') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
            {{ session('message') }}
        </div>
    @endif

    {{-- Add Competitor --}}
    <x-ui.card class="mb-6">
        <form wire:submit="addCompetitor" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('Competitor URL') }}</label>
                <input
                    type="url"
                    wire:model="competitorUrl"
                    placeholder="https://competitor.com"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
                @error('competitorUrl') <span class="mt-1 text-xs text-red-500">{{ $message }}</span> @enderror
            </div>
            <div class="sm:w-48">
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('Name (optional)') }}</label>
                <input
                    type="text"
                    wire:model="competitorName"
                    placeholder="Competitor name"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
            </div>
            <div class="mt-1">
                <x-ui.button type="submit" variant="primary" size="sm">{{ __('Add Competitor') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @if($this->competitors->isEmpty())
        <x-ui.card>
            <div class="py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <h3 class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('No competitors added') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Add competitor URLs above to start tracking their keyword positions.') }}</p>
            </div>
        </x-ui.card>
    @else
        {{-- Competitors List --}}
        <x-ui.card class="mb-6" :padding="false">
            <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Tracked Competitors') }} ({{ $this->competitors->count() }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                            <th class="px-5 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Competitor') }}</th>
                            <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Keywords') }}</th>
                            <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Winning') }}</th>
                            <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Losing') }}</th>
                            <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Avg. Position') }}</th>
                            <th class="px-5 py-2.5 text-right text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($this->comparisonSummary as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                <td class="px-5 py-3">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $row['competitor']->display_name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['competitor']->competitor_url }}</div>
                                </td>
                                <td class="px-5 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['total_keywords'] }}</td>
                                <td class="px-5 py-3 text-center">
                                    <span class="font-medium text-green-600 dark:text-green-400">{{ $row['winning'] }}</span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="font-medium text-red-600 dark:text-red-400">{{ $row['losing'] }}</span>
                                </td>
                                <td class="px-5 py-3 text-center text-gray-600 dark:text-gray-300">{{ $row['avg_position'] ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            wire:click="trackKeywords({{ $row['competitor']->id }})"
                                            wire:loading.attr="disabled"
                                            class="rounded-md bg-purple-50 px-2.5 py-1 text-xs font-medium text-purple-700 transition hover:bg-purple-100 dark:bg-purple-900/30 dark:text-purple-400 dark:hover:bg-purple-900/50"
                                        >
                                            <span wire:loading.remove wire:target="trackKeywords({{ $row['competitor']->id }})">{{ __('Sync') }}</span>
                                            <span wire:loading wire:target="trackKeywords({{ $row['competitor']->id }})">{{ __('Syncing...') }}</span>
                                        </button>
                                        <button
                                            wire:click="removeCompetitor({{ $row['competitor']->id }})"
                                            wire:confirm="{{ __('Remove this competitor and all tracked data?') }}"
                                            class="rounded-md bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 transition hover:bg-red-100 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50"
                                        >
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        {{-- Show competitors without summary data yet --}}
                        @foreach($this->competitors as $competitor)
                            @if(!collect($this->comparisonSummary)->pluck('competitor.id')->contains($competitor->id))
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ $competitor->display_name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $competitor->competitor_url }}</div>
                                    </td>
                                    <td class="px-5 py-3 text-center text-gray-400">0</td>
                                    <td class="px-5 py-3 text-center text-gray-400">—</td>
                                    <td class="px-5 py-3 text-center text-gray-400">—</td>
                                    <td class="px-5 py-3 text-center text-gray-400">—</td>
                                    <td class="px-5 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                wire:click="trackKeywords({{ $competitor->id }})"
                                                wire:loading.attr="disabled"
                                                class="rounded-md bg-purple-50 px-2.5 py-1 text-xs font-medium text-purple-700 transition hover:bg-purple-100 dark:bg-purple-900/30 dark:text-purple-400 dark:hover:bg-purple-900/50"
                                            >
                                                <span wire:loading.remove wire:target="trackKeywords({{ $competitor->id }})">{{ __('Sync') }}</span>
                                                <span wire:loading wire:target="trackKeywords({{ $competitor->id }})">{{ __('Syncing...') }}</span>
                                            </button>
                                            <button
                                                wire:click="removeCompetitor({{ $competitor->id }})"
                                                wire:confirm="{{ __('Remove this competitor and all tracked data?') }}"
                                                class="rounded-md bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 transition hover:bg-red-100 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50"
                                            >
                                                {{ __('Remove') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Analysis Tabs --}}
        <div class="mb-4 flex gap-2">
            @foreach(['gap' => 'Gap Analysis', 'overlap' => 'Keyword Overlap'] as $tab => $label)
                <button
                    wire:click="setTab('{{ $tab }}')"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeTab === $tab ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700' }}"
                >
                    {{ __($label) }}
                </button>
            @endforeach
        </div>

        {{-- Gap Analysis --}}
        @if($activeTab === 'gap')
            <x-ui.card :padding="false">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Keyword Gap') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Keywords where competitors rank but you don\'t (or rank significantly worse)') }}</p>
                </div>
                @if(empty($this->gapAnalysis))
                    <div class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No keyword gaps found. Sync competitor data first.') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                                    <th class="px-5 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Keyword') }}</th>
                                    <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Your Position') }}</th>
                                    <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Best Competitor') }}</th>
                                    <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Gap') }}</th>
                                    <th class="px-5 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Competitor Details') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach($this->gapAnalysis as $gap)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                        <td class="px-5 py-3 font-medium text-gray-900 dark:text-white">{{ $gap['keyword'] }}</td>
                                        <td class="px-5 py-3 text-center">
                                            @if($gap['our_position'])
                                                <span class="text-red-600 dark:text-red-400">{{ $gap['our_position'] }}</span>
                                            @else
                                                <span class="text-gray-400">{{ __('Not ranking') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <span class="font-medium text-green-600 dark:text-green-400">{{ $gap['best_competitor_position'] ?? '—' }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            @if($gap['gap'] !== null)
                                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">+{{ $gap['gap'] }}</span>
                                            @else
                                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">{{ __('Missing') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-xs text-gray-500 dark:text-gray-400">
                                            @foreach($gap['competitors'] as $comp)
                                                {{ $comp['competitor'] }}: #{{ $comp['position'] }}@if(!$loop->last), @endif
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endif

        {{-- Overlap Analysis --}}
        @if($activeTab === 'overlap')
            <x-ui.card :padding="false">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Keyword Overlap') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Keywords where both you and competitors rank — sorted by your advantage') }}</p>
                </div>
                @if(empty($this->overlapAnalysis))
                    <div class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No keyword overlap found. Sync competitor data first.') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                                    <th class="px-5 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Keyword') }}</th>
                                    <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Your Position') }}</th>
                                    <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Best Competitor') }}</th>
                                    <th class="px-5 py-2.5 text-center text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Advantage') }}</th>
                                    <th class="px-5 py-2.5 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Competitor Details') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach($this->overlapAnalysis as $overlap)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                        <td class="px-5 py-3 font-medium text-gray-900 dark:text-white">{{ $overlap['keyword'] }}</td>
                                        <td class="px-5 py-3 text-center font-medium text-gray-900 dark:text-white">{{ $overlap['our_position'] }}</td>
                                        <td class="px-5 py-3 text-center text-gray-600 dark:text-gray-300">{{ $overlap['best_competitor_position'] ?? '—' }}</td>
                                        <td class="px-5 py-3 text-center">
                                            @if($overlap['advantage'] !== null)
                                                @if($overlap['advantage'] > 0)
                                                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">+{{ $overlap['advantage'] }}</span>
                                                @elseif($overlap['advantage'] < 0)
                                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">{{ $overlap['advantage'] }}</span>
                                                @else
                                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">{{ __('Tied') }}</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-xs text-gray-500 dark:text-gray-400">
                                            @foreach($overlap['competitors'] as $comp)
                                                {{ $comp['competitor'] }}: #{{ $comp['position'] }}@if(!$loop->last), @endif
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>
