<div>
    <x-ui.page-header title="{{ __('Keyword Tracking') }}" subtitle="{{ __('Monitor your keyword rankings over time') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Add Keyword + Import --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end">
        <form wire:submit="addKeyword" class="flex flex-1 items-center gap-2">
            <div class="flex-1">
                <label for="new-keyword" class="mb-1 block text-xs font-medium text-gray-700">{{ __('Add Keyword') }}</label>
                <input
                    id="new-keyword"
                    type="text"
                    wire:model="newKeyword"
                    placeholder="{{ __('e.g. wordpress hosting romania') }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
                />
            </div>
            <div class="mt-5">
                <x-ui.button type="submit" variant="primary" size="sm" wire:loading.attr="disabled" wire:target="addKeyword">
                    <span wire:loading.remove wire:target="addKeyword">{{ __('Add') }}</span>
                    <span wire:loading wire:target="addKeyword">{{ __('Adding...') }}</span>
                </x-ui.button>
            </div>
        </form>

        @if($this->hasSearchConsole)
            <div>
                <x-ui.button variant="secondary" size="sm" wire:click="importFromSearchConsole" wire:loading.attr="disabled" wire:target="importFromSearchConsole">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    <span wire:loading.remove wire:target="importFromSearchConsole">{{ __('Import from Search Console') }}</span>
                    <span wire:loading wire:target="importFromSearchConsole">{{ __('Importing...') }}</span>
                </x-ui.button>
            </div>
        @endif
    </div>

    @if($this->keywords->isNotEmpty())
        {{-- Keywords Table --}}
        <x-ui.card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50">
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Keyword') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Position') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Clicks') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Impressions') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('CTR') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->keywords as $keyword)
                            @php
                                $position = $keyword->latest_position;
                                $positionColor = match(true) {
                                    $position === null      => 'text-gray-400',
                                    $position <= 3          => 'text-green-600',
                                    $position <= 10         => 'text-yellow-600',
                                    default                 => 'text-red-600',
                                };
                                $isSelected = $selectedKeywordId === $keyword->id;
                            @endphp
                            <tr class="transition hover:bg-gray-50 {{ $isSelected ? 'bg-purple-50' : '' }}">
                                <td class="px-4 py-3">
                                    <span class="font-medium text-gray-900">{{ $keyword->keyword }}</span>
                                </td>

                                {{-- Position with trend indicator --}}
                                <td class="px-4 py-3 text-center">
                                    <span class="font-bold {{ $positionColor }}">
                                        {{ $position !== null ? number_format($position, 1) : '—' }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-center text-gray-600">
                                    {{ $keyword->latest_clicks !== null ? number_format($keyword->latest_clicks) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-center text-gray-600">
                                    {{ $keyword->latest_impressions !== null ? number_format($keyword->latest_impressions) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-center text-gray-600">
                                    {{ $keyword->latest_ctr !== null ? number_format($keyword->latest_ctr, 1) . '%' : '—' }}
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        {{-- Chart toggle --}}
                                        <button
                                            wire:click="selectKeyword({{ $keyword->id }})"
                                            title="{{ __('View chart') }}"
                                            class="rounded-lg p-1.5 transition {{ $isSelected ? 'bg-purple-100 text-purple-600' : 'text-gray-400 hover:bg-gray-100 hover:text-gray-600' }}">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                                            </svg>
                                        </button>

                                        {{-- Remove --}}
                                        <button
                                            wire:click="removeKeyword({{ $keyword->id }})"
                                            wire:confirm="{{ __('Remove this keyword from tracking?') }}"
                                            title="{{ __('Remove keyword') }}"
                                            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-red-50 hover:text-red-600">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Keyword Chart --}}
        @if($selectedKeywordId && !empty($this->chartData))
            @php $selectedKeyword = $this->keywords->firstWhere('id', $selectedKeywordId); @endphp
            <div class="mt-6">
                <x-ui.card>
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">
                                {{ __('Position history') }}: <span class="text-purple-600">{{ $selectedKeyword?->keyword ?? '' }}</span>
                            </h3>
                            <p class="mt-0.5 text-xs text-gray-500">{{ __('Lower position = better ranking') }}</p>
                        </div>

                        {{-- Period selector --}}
                        <div class="flex gap-1">
                            @foreach(['30d' => __('30 Days'), '90d' => __('90 Days'), '1y' => __('1 Year')] as $period => $label)
                                <button
                                    wire:click="setChartPeriod('{{ $period }}')"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition
                                           {{ $chartPeriod === $period
                                               ? 'bg-purple-100 text-purple-700'
                                               : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Inverted Y-axis note: lower position = better, so the chart should visually show improvements going up --}}
                    <x-charts.line-chart
                        :labels="$this->chartData['labels'] ?? []"
                        :datasets="$this->chartData['datasets'] ?? []"
                        height="280px"
                    />
                </x-ui.card>
            </div>
        @endif

    @else
        {{-- Empty state --}}
        <x-ui.card>
            <x-ui.empty-state
                title="{{ __('No keywords tracked yet') }}"
                description="{{ __('Add keywords to monitor their ranking positions in search engines over time.') }}"
                icon="search"
            >
                <x-slot:action>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="newKeyword"
                            placeholder="{{ __('Enter a keyword...') }}"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
                        />
                        <x-ui.button variant="primary" wire:click="addKeyword" wire:loading.attr="disabled" wire:target="addKeyword">
                            <span wire:loading.remove wire:target="addKeyword">{{ __('Add Keyword') }}</span>
                            <span wire:loading wire:target="addKeyword">{{ __('Adding...') }}</span>
                        </x-ui.button>
                    </div>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @endif

    {{-- Keyword Cannibalization --}}
    @if(!empty($this->cannibalization))
        <x-ui.card class="mt-6">
            <h3 class="mb-3 flex items-center gap-2 text-base font-semibold text-gray-900">
                <span class="h-3 w-3 rounded-full bg-orange-500"></span>
                {{ __('Keyword Cannibalization') }}
                <span class="font-normal text-sm text-gray-400">({{ count($this->cannibalization) }} {{ __('keywords') }})</span>
            </h3>
            <p class="mb-4 text-sm text-gray-500">{{ __('These keywords appear in the title or H1 of multiple pages, which may confuse search engines about which page to rank.') }}</p>

            <div class="space-y-3" x-data="{ open: null }">
                @foreach($this->cannibalization as $kw => $data)
                    <div class="rounded-lg border border-orange-200">
                        <button @click="open = open === '{{ $kw }}' ? null : '{{ $kw }}'" class="flex w-full items-center justify-between px-4 py-3 text-left text-sm hover:bg-orange-50 transition">
                            <span class="font-medium text-gray-900">"{{ $kw }}"</span>
                            <span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-semibold text-orange-700">{{ count($data['pages']) }} {{ __('pages') }}</span>
                        </button>
                        <div x-show="open === '{{ $kw }}'" x-cloak class="border-t border-orange-100">
                            <div class="bg-blue-50 border-b border-blue-100 px-4 py-2">
                                <p class="text-xs text-blue-800"><strong>{{ __('Fix') }}:</strong> {{ __('Choose ONE primary page for this keyword. On other pages, either target a different keyword or add a canonical pointing to the primary page.') }}</p>
                            </div>
                            <div class="px-4 py-3 space-y-1.5">
                                @foreach($data['pages'] as $pg)
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">{{ $pg['where'] }}</span>
                                        <a href="{{ $pg['url'] }}" target="_blank" class="truncate text-purple-600 hover:text-purple-800">{{ $pg['url'] }}</a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
