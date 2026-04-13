<div class="min-w-0">
    <x-ui.page-header title="SEO Comparison" subtitle="Compare SEO scores across sites — portfolio and prospects">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('seo.index') }}"><x-icons.arrow-left class="h-4 w-4" /> SEO Overview</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Site Selector --}}
    <x-ui.card class="mb-6">
        <h3 class="text-sm font-medium text-gray-900 mb-3">Select 2-4 sites to compare</h3>
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search sites..." class="mb-3 w-full rounded-lg border-gray-300 text-sm shadow-sm sm:w-64">
        <div class="flex flex-wrap gap-2">
            @foreach($this->availableSites as $s)
                @php $selected = in_array($s->id, $selectedIds); @endphp
                <button wire:click="toggleSite({{ $s->id }})" class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors {{ $selected ? 'border-accent-500 bg-accent-50 text-accent-700' : 'border-gray-200 text-gray-600 hover:border-gray-300' }}">
                    @if($s->is_prospect) <span class="text-xs text-purple-500">P</span> @endif
                    {{ $s->name }}
                    @if($selected) <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg> @endif
                </button>
            @endforeach
        </div>
        @if(count($selectedIds) > 0)
            <p class="mt-2 text-xs text-gray-400">{{ count($selectedIds) }} selected ({{ 4 - count($selectedIds) }} remaining)</p>
        @endif
    </x-ui.card>

    {{-- Comparison Results --}}
    @if(!empty($this->comparisonData))
        @php $data = $this->comparisonData; $best = collect($data)->sortByDesc('score')->first(); @endphp

        {{-- Score Bars --}}
        <x-ui.card class="mb-6">
            <h3 class="text-sm font-medium text-gray-900 mb-4">Overall Scores</h3>
            <div class="space-y-3">
                @foreach($data as $site)
                    @php $sc = $site['score']; $color = $sc >= 80 ? 'bg-green-500' : ($sc >= 50 ? 'bg-yellow-500' : 'bg-red-500'); $textColor = $sc >= 80 ? 'text-green-600' : ($sc >= 50 ? 'text-yellow-600' : 'text-red-600'); @endphp
                    <div class="flex items-center gap-3">
                        <div class="w-32 truncate text-sm font-medium text-gray-700">
                            {{ $site['name'] }}
                            @if($site['is_prospect']) <span class="text-xs text-purple-400">(prospect)</span> @endif
                        </div>
                        <div class="flex-1 h-6 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full rounded-full {{ $color }} transition-all" style="width: {{ $sc }}%"></div>
                        </div>
                        <span class="w-10 text-right text-lg font-bold {{ $textColor }}">{{ $sc }}</span>
                        @if($site['score'] === $best['score']) <span class="text-xs text-green-600 font-medium">Best</span> @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- Category Comparison --}}
        <x-ui.card class="mb-6">
            <h3 class="text-sm font-medium text-gray-900 mb-4">Category Breakdown</h3>
            <x-charts.bar-chart
                :labels="collect($data)->pluck('name')->toArray()"
                :data="collect($data)->pluck('score')->toArray()"
                :horizontal="true"
                height="{{ count($data) * 50 + 40 }}px"
                color="#8D5CF5"
            />
        </x-ui.card>

        {{-- Detailed Comparison Table --}}
        <x-ui.card>
            <h3 class="text-sm font-medium text-gray-900 mb-4">Detailed Comparison</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="py-2 px-3 text-left text-xs font-medium text-gray-500">Metric</th>
                            @foreach($data as $site)
                                <th class="py-2 px-3 text-center text-xs font-medium text-gray-900">{{ $site['name'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach([
                            'Overall Score' => 'score',
                            'Technical SEO' => 'technical',
                            'On-Page' => 'on_page',
                            'Performance' => 'performance',
                            'Other' => 'other',
                            'Pages Crawled' => 'pages_crawled',
                            'Critical Issues' => 'critical',
                            'High Issues' => 'high',
                            'Medium Issues' => 'medium',
                            'Total Issues' => 'total_issues',
                        ] as $label => $key)
                            @php
                                $isScore = in_array($key, ['score', 'technical', 'on_page', 'performance', 'other']);
                                $isIssue = in_array($key, ['critical', 'high', 'medium', 'total_issues']);
                                $values = collect($data)->pluck($key);
                                $bestVal = $isIssue ? $values->min() : $values->max();
                            @endphp
                            <tr>
                                <td class="py-2 px-3 text-gray-600">{{ $label }}</td>
                                @foreach($data as $site)
                                    @php
                                        $val = $site[$key];
                                        $isBest = $val === $bestVal && count($data) > 1;
                                        $color = '';
                                        if ($isScore) $color = $val >= 80 ? 'text-green-600' : ($val >= 50 ? 'text-yellow-600' : 'text-red-600');
                                        if ($isIssue && $val > 0) $color = $key === 'critical' ? 'text-red-600' : ($key === 'high' ? 'text-orange-600' : '');
                                    @endphp
                                    <td class="py-2 px-3 text-center font-medium {{ $color }} {{ $isBest ? 'bg-green-50 rounded' : '' }}">
                                        {{ $val }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-400">Last scanned: @foreach($data as $site) {{ $site['name'] }}: {{ $site['scanned_at'] }}{{ !$loop->last ? ' | ' : '' }} @endforeach</p>
        </x-ui.card>
    @elseif(count($selectedIds) > 0 && count($selectedIds) < 2)
        <x-ui.card><x-ui.empty-state title="Select one more site" description="Select at least 2 sites to compare." icon="bar-chart-2" /></x-ui.card>
    @else
        <x-ui.card><x-ui.empty-state title="No sites selected" description="Choose sites above to start comparing SEO scores." icon="bar-chart-2" /></x-ui.card>
    @endif
</div>
