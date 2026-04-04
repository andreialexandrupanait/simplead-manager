<div class="min-w-0" @if($isRunning) wire:poll.2s="checkTestProgress" @endif>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="{{ __('Performance') }}" subtitle="{{ __('Monitor Core Web Vitals and PageSpeed scores') }}" />
        <div class="flex items-center gap-3">
            @if($this->monitor)
                <x-ui.button variant="secondary" wire:click="openBudgetModal">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    {{ __('Budgets') }}
                </x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="openSettings">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                {{ __('Settings') }}
            </x-ui.button>
            <x-ui.button wire:click="runTest" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="runTest">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </span>
                <x-ui.spinner size="sm" wire:loading wire:target="runTest" />
                {{ __('Run Test') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Flash message --}}
    <x-ui.flash-alert type="success" key="message" class="mb-6" />

    {{-- Test Progress Banner --}}
    @if($isRunning || $this->activeTests->isNotEmpty())
        @php
            $tests = $this->activeTests;
            $runningTest = $tests->firstWhere('status', 'running') ?? $tests->first();
            $testDevice = $runningTest?->device ?? 'mobile';
            $stillRunning = $isRunning;
            $finished = $this->lastFinishedTest;
            $hasFailed = $finished && $finished->status === 'failed';
            $errorMessage = $hasFailed ? $finished->error_message : null;
        @endphp
        <div
            class="mb-6"
            x-data="{
                dismissed: false,
                timer: null,
                running: @js($stillRunning),
                failed: @js($hasFailed),
                errorMessage: @js($errorMessage ?? ''),
                device: @js($testDevice),
                count: @js($tests->count()),
            }"
            x-effect="
                let newRunning = @js($stillRunning);
                let newCount = @js($tests->count());
                running = newRunning;
                failed = @js($hasFailed);
                errorMessage = @js($errorMessage ?? '');
                device = @js($testDevice);
                count = newCount;
                if (!newRunning && !failed && !timer) {
                    timer = setTimeout(() => { dismissed = true; }, 3000);
                }
            "
            x-show="!dismissed"
            x-transition
        >
            <x-ui.card>
                <div class="space-y-3">
                    {{-- Header --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <template x-if="running">
                                <x-ui.spinner size="md" class="text-purple-600" />
                            </template>
                            <template x-if="!running && !failed">
                                <svg class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </template>
                            <template x-if="!running && failed">
                                <svg class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </template>
                            <h3 class="text-sm font-semibold text-gray-900">
                                <span x-show="running">
                                    {{ __('Analyzing') }}
                                    <span x-show="count > 0 && device === 'mobile'">{{ __('Mobile') }}</span>
                                    <span x-show="count > 0 && device === 'desktop'">{{ __('Desktop') }}</span>
                                    {{ __('Performance') }}&hellip;
                                </span>
                                <span x-show="!running && !failed">{{ __('Tests Complete') }}</span>
                                <span x-show="!running && failed">{{ __('Test Failed') }}</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span x-show="running && count > 0">
                                <span x-show="count === 1">1 test running</span>
                                <span x-show="count === 2">2 tests running</span>
                            </span>
                            <span x-show="running && count === 0">{{ __('Queued') }}</span>
                            <button x-show="!running" @click="dismissed = true" class="text-gray-400 hover:text-gray-600">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            class="h-2 rounded-full transition-all duration-700 ease-out"
                            :class="{
                                'bg-green-500': !running && !failed,
                                'bg-red-500': !running && failed,
                                'bg-purple-500': running,
                            }"
                            :style="!running ? 'width: 100%' : 'width: 60%; animation: indeterminate 1.5s infinite ease-in-out'"
                        ></div>
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span x-show="running">{{ __('PageSpeed Insights analysis in progress') }}</span>
                        <span x-show="!running && !failed">{{ __('Results updated') }}</span>
                        <span x-show="!running && failed" x-text="errorMessage" class="text-red-600"></span>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <style>
            @keyframes indeterminate {
                0% { margin-left: 0; width: 40%; }
                50% { margin-left: 30%; width: 50%; }
                100% { margin-left: 0; width: 40%; }
            }
        </style>
    @endif

    @if($this->monitor && ($this->latestMobileTest || $this->latestDesktopTest))

        {{-- Score Gauges --}}
        <div class="mb-6 grid grid-cols-2 gap-6">
            {{-- Mobile --}}
            <x-ui.card>
                <div class="flex flex-col items-center">
                    <x-performance.score-gauge
                        :score="$this->latestMobileTest?->performance_score"
                        label="Mobile"
                        size="lg"
                    />
                    @if($this->latestMobileTest)
                        <div class="mt-4 w-full max-w-xs space-y-0.5">
                            <x-performance.metric-item label="FCP" :value="$this->latestMobileTest->formatMetric('fcp')" :color="$this->latestMobileTest->metricColor('fcp')" />
                            <x-performance.metric-item label="LCP" :value="$this->latestMobileTest->formatMetric('lcp')" :color="$this->latestMobileTest->metricColor('lcp')" />
                            <x-performance.metric-item label="CLS" :value="$this->latestMobileTest->formatMetric('cls')" :color="$this->latestMobileTest->metricColor('cls')" />
                            <x-performance.metric-item label="TBT" :value="$this->latestMobileTest->formatMetric('tbt')" :color="$this->latestMobileTest->metricColor('tbt')" />
                            <x-performance.metric-item label="SI" :value="$this->latestMobileTest->formatMetric('si')" :color="$this->latestMobileTest->metricColor('si')" />
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Desktop --}}
            <x-ui.card>
                <div class="flex flex-col items-center">
                    <x-performance.score-gauge
                        :score="$this->latestDesktopTest?->performance_score"
                        label="Desktop"
                        size="lg"
                    />
                    @if($this->latestDesktopTest)
                        <div class="mt-4 w-full max-w-xs space-y-0.5">
                            <x-performance.metric-item label="FCP" :value="$this->latestDesktopTest->formatMetric('fcp')" :color="$this->latestDesktopTest->metricColor('fcp')" />
                            <x-performance.metric-item label="LCP" :value="$this->latestDesktopTest->formatMetric('lcp')" :color="$this->latestDesktopTest->metricColor('lcp')" />
                            <x-performance.metric-item label="CLS" :value="$this->latestDesktopTest->formatMetric('cls')" :color="$this->latestDesktopTest->metricColor('cls')" />
                            <x-performance.metric-item label="TBT" :value="$this->latestDesktopTest->formatMetric('tbt')" :color="$this->latestDesktopTest->metricColor('tbt')" />
                            <x-performance.metric-item label="SI" :value="$this->latestDesktopTest->formatMetric('si')" :color="$this->latestDesktopTest->metricColor('si')" />
                        </div>
                    @endif
                </div>
            </x-ui.card>
        </div>

        {{-- Field Data (CrUX) --}}
        @if($this->hasFieldData)
            <x-ui.card class="mb-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900">{{ __('Field Data (CrUX)') }}</h3>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    @php $fieldTest = $this->latestMobileTest; @endphp
                    @foreach([
                        'FCP' => 'field_fcp',
                        'LCP' => 'field_lcp',
                        'CLS' => 'field_cls',
                        'INP' => 'field_inp',
                        'TTFB' => 'field_ttfb',
                    ] as $fieldLabel => $fieldKey)
                        <div class="rounded-lg border border-gray-100 p-3 text-center">
                            <div class="text-xs font-medium text-gray-500">{{ $fieldLabel }}</div>
                            @php
                                $fColor = $fieldTest->metricColor($fieldKey);
                                $fTextColor = match($fColor) {
                                    'green' => 'text-green-600',
                                    'orange' => 'text-yellow-600',
                                    'red' => 'text-red-600',
                                    default => 'text-gray-500',
                                };
                            @endphp
                            <div class="mt-1 text-lg font-bold {{ $fTextColor }}">
                                {{ $fieldTest->formatMetric($fieldKey) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        {{-- 30-Day Trend Summary --}}
        @if(!empty($this->trendSummary))
            <div class="mb-6 grid grid-cols-2 gap-4">
                @foreach(['mobile' => 'Mobile', 'desktop' => 'Desktop'] as $device => $label)
                    @php $trend = $this->trendSummary[$device] ?? null; @endphp
                    @if($trend)
                        <div class="rounded-lg border border-gray-200 p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase">{{ $label }} 30d avg</p>
                            <div class="mt-1 flex items-baseline gap-2">
                                <span class="text-2xl font-bold text-gray-900">{{ $trend['current'] }}</span>
                                @if($trend['change'] !== null)
                                    <span class="text-sm font-medium {{ $trend['change'] > 0 ? 'text-green-600' : ($trend['change'] < 0 ? 'text-red-600' : 'text-gray-400') }}">
                                        {{ $trend['change'] > 0 ? '+' : '' }}{{ $trend['change'] }} vs prev 30d
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- Score History Chart --}}
        <x-ui.card class="mb-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('Score History') }}</h3>
                <div class="flex rounded-lg border border-gray-200 bg-gray-50">
                    @foreach(['7d' => '7d', '30d' => '30d', '90d' => '90d', '180d' => '180d'] as $key => $label)
                        <button wire:click="setHistoryRange('{{ $key }}')"
                                class="px-3 py-1 text-xs font-medium transition {{ $historyRange === $key ? 'bg-white text-purple-700 shadow-sm rounded-lg' : 'text-gray-500 hover:text-gray-700' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
            @if(count($this->scoreHistory['labels']) > 0)
                <x-charts.line-chart
                    :labels="$this->scoreHistory['labels']"
                    :datasets="$this->scoreHistory['datasets']"
                    :annotations="$this->scoreHistory['annotations'] ?? []"
                    height="300px"
                />
            @else
                <div class="py-12 text-center text-sm text-gray-500">
                    {{ __('Not enough data yet. Run more tests to see score history.') }}
                </div>
            @endif
        </x-ui.card>

        {{-- Competitor Benchmarking --}}
        @if($this->monitor)
            <x-ui.card class="mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">{{ __('Competitor Benchmarking') }}</h3>

                @if(!empty($this->competitorComparison))
                    {{-- Mobile cards --}}
                    <div class="md:hidden space-y-2 mb-4">
                        <div class="rounded-lg border border-purple-200 bg-purple-50 p-3">
                            <div class="text-sm font-medium text-gray-900">{{ $this->site->name }} <span class="text-xs text-gray-500">(you)</span></div>
                            <div class="mt-1.5 flex items-center gap-4">
                                <span class="text-xs text-gray-500">Mobile: <span class="font-semibold text-gray-900">{{ $this->monitor->latest_mobile_score ?? '—' }}</span></span>
                                <span class="text-xs text-gray-500">Desktop: <span class="font-semibold text-gray-900">{{ $this->monitor->latest_desktop_score ?? '—' }}</span></span>
                            </div>
                        </div>
                        @foreach($this->competitorComparison as $i => $comp)
                            <div class="rounded-lg border border-gray-200 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <span class="text-sm text-gray-700">{{ $comp['domain'] }}</span>
                                    <button wire:click="removeCompetitor({{ $i }})" class="shrink-0 text-xs text-red-500 hover:text-red-700">Remove</button>
                                </div>
                                <div class="mt-1.5 flex items-center gap-4">
                                    <span class="text-xs text-gray-500">Mobile: <span class="font-semibold {{ ($comp['mobile_score'] ?? 0) < ($this->monitor->latest_mobile_score ?? 0) ? 'text-green-600' : 'text-red-600' }}">{{ $comp['mobile_score'] ?? '—' }}</span></span>
                                    <span class="text-xs text-gray-500">Desktop: <span class="font-semibold {{ ($comp['desktop_score'] ?? 0) < ($this->monitor->latest_desktop_score ?? 0) ? 'text-green-600' : 'text-red-600' }}">{{ $comp['desktop_score'] ?? '—' }}</span></span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Desktop table --}}
                    <div class="hidden md:block overflow-x-auto mb-4">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="py-2 text-left font-medium text-gray-500">Site</th>
                                    <th class="py-2 text-center font-medium text-gray-500">Mobile</th>
                                    <th class="py-2 text-center font-medium text-gray-500">Desktop</th>
                                    <th class="py-2 text-right font-medium text-gray-500"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-gray-100 bg-purple-50">
                                    <td class="py-2 font-medium text-gray-900">{{ $this->site->name }} (you)</td>
                                    <td class="py-2 text-center font-semibold">{{ $this->monitor->latest_mobile_score ?? '—' }}</td>
                                    <td class="py-2 text-center font-semibold">{{ $this->monitor->latest_desktop_score ?? '—' }}</td>
                                    <td></td>
                                </tr>
                                @foreach($this->competitorComparison as $i => $comp)
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 text-gray-700">{{ $comp['domain'] }}</td>
                                        <td class="py-2 text-center {{ ($comp['mobile_score'] ?? 0) < ($this->monitor->latest_mobile_score ?? 0) ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $comp['mobile_score'] ?? '—' }}
                                        </td>
                                        <td class="py-2 text-center {{ ($comp['desktop_score'] ?? 0) < ($this->monitor->latest_desktop_score ?? 0) ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $comp['desktop_score'] ?? '—' }}
                                        </td>
                                        <td class="py-2 text-right">
                                            <button wire:click="removeCompetitor({{ $i }})" class="text-xs text-red-500 hover:text-red-700">Remove</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if(count($this->monitor->competitor_urls ?? []) < 5)
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <x-ui.input type="url" wire:model="newCompetitorUrl" placeholder="https://competitor.com" />
                            @error('newCompetitorUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <x-ui.button wire:click="addCompetitor" size="sm">{{ __('Add Competitor') }}</x-ui.button>
                    </div>
                @endif
            </x-ui.card>
        @endif

        {{-- Test History Table --}}
        @if($this->testHistory->count() > 0)
            <x-ui.card class="overflow-hidden">
                <h3 class="mb-4 text-lg font-semibold text-gray-900">{{ __('Test History') }}</h3>

                {{-- Mobile cards --}}
                <div class="md:hidden space-y-2">
                    @foreach($this->testHistory as $test)
                        @php
                            $scoreColor = match($test->score_color) {
                                'green' => 'text-green-600',
                                'orange' => 'text-yellow-600',
                                'red' => 'text-red-600',
                                default => 'text-gray-400',
                            };
                            $statusVariant = match($test->status) {
                                'completed' => 'green',
                                'running' => 'yellow',
                                'failed' => 'red',
                                default => 'gray',
                            };
                        @endphp
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm text-gray-900">{{ $test->tested_at?->format('M j, H:i') ?? '—' }}</span>
                                <div class="flex items-center gap-1.5">
                                    <x-ui.badge :variant="$test->device === 'mobile' ? 'purple' : 'blue'">{{ ucfirst($test->device) }}</x-ui.badge>
                                    <x-ui.badge :variant="$statusVariant">{{ ucfirst($test->status) }}</x-ui.badge>
                                </div>
                            </div>
                            <div class="mt-2 flex items-center gap-3">
                                @if($test->performance_score !== null)
                                    <span class="text-2xl font-bold {{ $scoreColor }}">{{ $test->performance_score }}</span>
                                @else
                                    <span class="text-2xl font-bold text-gray-400">—</span>
                                @endif
                                <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs text-gray-500">
                                    <span>FCP: <span class="font-medium text-gray-700">{{ $test->formatMetric('fcp') }}</span></span>
                                    <span>LCP: <span class="font-medium text-gray-700">{{ $test->formatMetric('lcp') }}</span></span>
                                    <span>CLS: <span class="font-medium text-gray-700">{{ $test->formatMetric('cls') }}</span></span>
                                    <span>TBT: <span class="font-medium text-gray-700">{{ $test->formatMetric('tbt') }}</span></span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop table --}}
                <div class="-mx-6 hidden overflow-x-auto px-6 md:block">
                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th>Date</x-ui.th>
                        <x-ui.th>Device</x-ui.th>
                        <x-ui.th>Score</x-ui.th>
                        <x-ui.th>FCP</x-ui.th>
                        <x-ui.th>LCP</x-ui.th>
                        <x-ui.th>CLS</x-ui.th>
                        <x-ui.th>TBT</x-ui.th>
                        <x-ui.th>Status</x-ui.th>
                    </x-slot:head>
                    @foreach($this->testHistory as $test)
                        <tr>
                            <x-ui.td>{{ $test->tested_at?->format('M j, H:i') ?? '—' }}</x-ui.td>
                            <x-ui.td>
                                <x-ui.badge :variant="$test->device === 'mobile' ? 'purple' : 'blue'">{{ ucfirst($test->device) }}</x-ui.badge>
                            </x-ui.td>
                            <x-ui.td>
                                @if($test->performance_score !== null)
                                    @php
                                        $scoreColor = match($test->score_color) {
                                            'green' => 'text-green-600',
                                            'orange' => 'text-yellow-600',
                                            'red' => 'text-red-600',
                                            default => 'text-gray-400',
                                        };
                                    @endphp
                                    <span class="font-bold {{ $scoreColor }}">{{ $test->performance_score }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </x-ui.td>
                            <x-ui.td>{{ $test->formatMetric('fcp') }}</x-ui.td>
                            <x-ui.td>{{ $test->formatMetric('lcp') }}</x-ui.td>
                            <x-ui.td>{{ $test->formatMetric('cls') }}</x-ui.td>
                            <x-ui.td>{{ $test->formatMetric('tbt') }}</x-ui.td>
                            <x-ui.td>
                                @php
                                    $statusVariant = match($test->status) {
                                        'completed' => 'green',
                                        'running' => 'yellow',
                                        'failed' => 'red',
                                        default => 'gray',
                                    };
                                @endphp
                                <x-ui.badge :variant="$statusVariant">{{ ucfirst($test->status) }}</x-ui.badge>
                            </x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
                </div>
            </x-ui.card>
        @endif

    @else
        {{-- Empty state --}}
        <x-ui.card>
            <div class="py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <h3 class="mt-4 text-sm font-semibold text-gray-900">{{ __('No performance data yet') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Run your first performance test to see PageSpeed Insights results.') }}</p>
                <div class="mt-6">
                    <button wire:click="runTest"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700 disabled:opacity-50">
                        <svg wire:loading.remove wire:target="runTest" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <x-ui.spinner size="sm" wire:loading wire:target="runTest" />
                        {{ __('Run First Test') }}
                    </button>
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- Settings Modal --}}
    <x-ui.modal name="performance-settings" maxWidth="md">
        <form wire:submit="updateSettings">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Performance Settings') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Configure test frequency and alerts.') }}</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Test Frequency') }}</label>
                    <x-ui.select wire:model="settingsFrequency" class="mt-1">
                        <option value="manual">{{ __('Manual only') }}</option>
                        <option value="daily">{{ __('Daily') }}</option>
                        <option value="weekly">{{ __('Weekly') }}</option>
                    </x-ui.select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Test Time') }}</label>
                    <x-ui.input wire:model="settingsTestTime" type="time" class="mt-1" />
                </div>

                @if($settingsFrequency === 'weekly')
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Day of Week') }}</label>
                        <x-ui.select wire:model="settingsDayOfWeek" class="mt-1">
                            <option value="0">{{ __('Sunday') }}</option>
                            <option value="1">{{ __('Monday') }}</option>
                            <option value="2">{{ __('Tuesday') }}</option>
                            <option value="3">{{ __('Wednesday') }}</option>
                            <option value="4">{{ __('Thursday') }}</option>
                            <option value="5">{{ __('Friday') }}</option>
                            <option value="6">{{ __('Saturday') }}</option>
                        </x-ui.select>
                    </div>
                @endif

                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-gray-700">{{ __('Alert on score drop') }}</label>
                    <x-ui.toggle wire:model="settingsAlertOnDrop" :enabled="$settingsAlertOnDrop" />
                </div>

                @if($settingsAlertOnDrop)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Score drop threshold') }}</label>
                        <x-ui.input wire:model="settingsThreshold" type="number" min="1" max="100" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('Alert when score drops by this many points or more.') }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button"
                        @click="$dispatch('close-modal-performance-settings')"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                    {{ __('Cancel') }}
                </button>
                <button type="submit"
                        class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700">
                    {{ __('Save Settings') }}
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Budget Edit Modal --}}
    <x-ui.modal name="edit-budgets" maxWidth="md">
        <form wire:submit="saveBudgets">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Edit Performance Budgets') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Set thresholds for key metrics. Leave empty to skip.') }}</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Performance Score (min)') }}</label>
                    <x-ui.input wire:model="budgetForm.performance_score" type="number" min="0" max="100" placeholder="e.g. 80" class="mt-1" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('LCP (max, seconds)') }}</label>
                        <x-ui.input wire:model="budgetForm.lcp" type="number" step="0.1" min="0" placeholder="e.g. 2.5" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('FCP (max, seconds)') }}</label>
                        <x-ui.input wire:model="budgetForm.fcp" type="number" step="0.1" min="0" placeholder="e.g. 1.8" class="mt-1" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('CLS (max)') }}</label>
                        <x-ui.input wire:model="budgetForm.cls" type="number" step="0.001" min="0" placeholder="e.g. 0.1" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('TBT (max, ms)') }}</label>
                        <x-ui.input wire:model="budgetForm.tbt" type="number" min="0" placeholder="e.g. 200" class="mt-1" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Speed Index (max, seconds)') }}</label>
                    <x-ui.input wire:model="budgetForm.si" type="number" step="0.1" min="0" placeholder="e.g. 3.4" class="mt-1" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Total Page Size (max, bytes)') }}</label>
                        <x-ui.input wire:model="budgetForm.total_size_bytes" type="number" min="0" placeholder="e.g. 2000000" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('JS Size (max, bytes)') }}</label>
                        <x-ui.input wire:model="budgetForm.js_size" type="number" min="0" placeholder="e.g. 500000" class="mt-1" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Image Size (max, bytes)') }}</label>
                    <x-ui.input wire:model="budgetForm.image_size" type="number" min="0" placeholder="e.g. 1000000" class="mt-1" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button"
                        @click="$dispatch('close-modal-edit-budgets')"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700">
                    {{ __('Save Budgets') }}
                </button>
            </div>
        </form>
    </x-ui.modal>
</div>
