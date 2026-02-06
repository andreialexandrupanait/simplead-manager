<div class="min-w-0" @if($isRunning) wire:poll.2s="checkTestProgress" @endif>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="Performance" subtitle="Monitor Core Web Vitals and PageSpeed scores" />
        <div class="flex items-center gap-3">
            @if($this->monitor)
                <button wire:click="openBudgetModal"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    Budgets
                </button>
            @endif
            <button wire:click="openSettings"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Settings
            </button>
            <button wire:click="runTest"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="runTest">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </span>
                <svg wire:loading wire:target="runTest" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                Run Test
            </button>
        </div>
    </div>

    {{-- Flash message --}}
    @if(session('message'))
        <x-ui.alert class="mb-6">{{ session('message') }}</x-ui.alert>
    @endif

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
                                <svg class="h-5 w-5 animate-spin text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
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
                                    Analyzing
                                    <span x-show="count > 0 && device === 'mobile'">Mobile</span>
                                    <span x-show="count > 0 && device === 'desktop'">Desktop</span>
                                    Performance&hellip;
                                </span>
                                <span x-show="!running && !failed">Tests Complete</span>
                                <span x-show="!running && failed">Test Failed</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span x-show="running && count > 0">
                                <span x-show="count === 1">1 test running</span>
                                <span x-show="count === 2">2 tests running</span>
                            </span>
                            <span x-show="running && count === 0">Queued</span>
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
                        <span x-show="running">PageSpeed Insights analysis in progress</span>
                        <span x-show="!running && !failed">Results updated</span>
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

        {{-- Page Selector Tabs --}}
        @if($this->pages->isNotEmpty())
            <x-performance.page-selector
                :pages="$this->pages"
                :selectedPageId="$selectedPageId"
                :showAddPage="$showAddPage"
            />
        @endif

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

        {{-- Score Categories --}}
        @php $catTest = $this->latestMobileTest ?? $this->latestDesktopTest; @endphp
        @if($catTest)
            <div class="mb-6 grid grid-cols-4 gap-4">
                @foreach([
                    'Performance' => $catTest->performance_score,
                    'Accessibility' => $catTest->accessibility_score,
                    'Best Practices' => $catTest->best_practices_score,
                    'SEO' => $catTest->seo_score,
                ] as $catLabel => $catScore)
                    <x-ui.card>
                        <div class="text-center">
                            <div class="text-xs font-medium text-gray-500">{{ $catLabel }}</div>
                            @php
                                $catColor = $catScore === null ? 'text-gray-400' : ($catScore >= 90 ? 'text-green-600' : ($catScore >= 50 ? 'text-yellow-600' : 'text-red-600'));
                                $catBg = $catScore === null ? 'bg-gray-100' : ($catScore >= 90 ? 'bg-green-50' : ($catScore >= 50 ? 'bg-yellow-50' : 'bg-red-50'));
                            @endphp
                            <div class="mt-1 inline-flex items-center rounded-full px-3 py-1 text-lg font-bold {{ $catColor }} {{ $catBg }}">
                                {{ $catScore ?? '—' }}
                            </div>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        @endif

        {{-- Budget Status --}}
        @if(!empty($this->budgetViolations))
            <x-performance.budget-status :violations="$this->budgetViolations" />
        @endif

        {{-- Field Data (CrUX) --}}
        @if($this->hasFieldData)
            <x-ui.card class="mb-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900">Field Data (CrUX)</h3>
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

        {{-- DOM Info --}}
        @php $domTest = $this->activeTest ?? $this->latestMobileTest; @endphp
        @if($domTest && $domTest->dom_elements)
            <x-performance.dom-info
                :elements="$domTest->dom_elements"
                :maxDepth="$domTest->dom_max_depth"
                :maxChildren="$domTest->dom_max_children"
                :color="$domTest->dom_color"
            />
        @endif

        {{-- Page Weight Breakdown --}}
        @php $sizeTest = $this->latestMobileTest ?? $this->latestDesktopTest; @endphp
        @if($sizeTest && $sizeTest->total_size_bytes)
            <x-ui.card class="mb-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Page Weight</h3>
                    <div class="text-sm text-gray-500">
                        {{ $sizeTest->formatMetric('total_size_bytes') }} total &middot; {{ $sizeTest->total_requests }} requests
                    </div>
                </div>
                <div class="space-y-3">
                    @php
                        $total = max(1, $sizeTest->total_size_bytes);
                        $sizeItems = [
                            ['label' => 'JavaScript', 'key' => 'js_size', 'color' => 'bg-yellow-500'],
                            ['label' => 'Images', 'key' => 'image_size', 'color' => 'bg-purple-500'],
                            ['label' => 'CSS', 'key' => 'css_size', 'color' => 'bg-blue-500'],
                            ['label' => 'Fonts', 'key' => 'font_size', 'color' => 'bg-green-500'],
                            ['label' => 'HTML', 'key' => 'html_size', 'color' => 'bg-gray-500'],
                        ];
                    @endphp
                    @foreach($sizeItems as $item)
                        @if($sizeTest->{$item['key']})
                            <div>
                                <div class="mb-1 flex items-center justify-between text-sm">
                                    <span class="text-gray-600">{{ $item['label'] }}</span>
                                    <span class="font-medium text-gray-900">{{ $sizeTest->formatMetric($item['key']) }}</span>
                                </div>
                                <div class="h-2 rounded-full bg-gray-100">
                                    <div class="h-2 rounded-full {{ $item['color'] }}"
                                         style="width: {{ min(100, round(($sizeTest->{$item['key']} / $total) * 100, 1)) }}%"></div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        {{-- Unused Code --}}
        @php $unusedTest = $this->activeTest ?? $this->latestMobileTest; @endphp
        @if($unusedTest && ($unusedTest->unused_js_bytes || $unusedTest->unused_css_bytes))
            <x-performance.unused-code
                :jsBytes="$unusedTest->unused_js_bytes"
                :cssBytes="$unusedTest->unused_css_bytes"
                :jsDetails="$unusedTest->unused_js_details"
                :cssDetails="$unusedTest->unused_css_details"
                :totalSize="$unusedTest->total_size_bytes ?? 0"
            />
        @endif

        {{-- Image Audit --}}
        @php $imgTest = $this->activeTest ?? $this->latestMobileTest; @endphp
        @if($imgTest && $imgTest->image_audit)
            <x-performance.image-audit :audit="$imgTest->image_audit" />
        @endif

        {{-- Third-Party Scripts --}}
        @php $tpTest = $this->activeTest ?? $this->latestMobileTest; @endphp
        @if($tpTest && !empty($tpTest->third_party_scripts))
            <x-performance.third-party-table :scripts="$tpTest->third_party_scripts" />
        @endif

        {{-- WP Health Checks --}}
        @php $wpTest = $this->activeTest ?? $this->latestMobileTest; @endphp
        @if($wpTest && !empty($wpTest->wp_health_checks))
            <x-performance.wp-health :checks="$wpTest->wp_health_checks" />
        @endif

        {{-- Top Opportunities --}}
        @php $opTest = $this->latestMobileTest ?? $this->latestDesktopTest; @endphp
        @if($opTest && !empty($opTest->opportunities))
            <x-ui.card class="mb-6">
                <h3 class="mb-4 text-lg font-semibold text-gray-900">Top Opportunities</h3>
                <div class="space-y-3">
                    @foreach($opTest->opportunities as $i => $op)
                        <div class="flex items-start gap-3 rounded-lg border border-gray-100 p-3">
                            <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-medium text-gray-600">
                                {{ $i + 1 }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-gray-900">{{ $op['title'] }}</div>
                                @if($op['savings_ms'] > 0)
                                    <div class="mt-0.5 text-xs text-gray-500">
                                        Potential savings: <span class="font-medium text-orange-600">{{ number_format($op['savings_ms']) }} ms</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        {{-- Loading Filmstrip --}}
        @php $fsTest = $this->activeTest ?? $this->latestMobileTest; @endphp
        @if($fsTest && !empty($fsTest->filmstrip))
            <x-performance.filmstrip
                :frames="$fsTest->filmstrip"
                :fcp="$fsTest->fcp"
                :lcp="$fsTest->lcp"
            />
        @endif

        {{-- Score History Chart --}}
        <x-ui.card class="mb-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Score History</h3>
                <div class="flex rounded-lg border border-gray-200 bg-gray-50">
                    @foreach(['7d' => '7d', '30d' => '30d', '90d' => '90d'] as $key => $label)
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
                    Not enough data yet. Run more tests to see score history.
                </div>
            @endif
        </x-ui.card>

        {{-- Test History Table --}}
        @if($this->testHistory->count() > 0)
            <x-ui.card class="overflow-hidden">
                <h3 class="mb-4 text-lg font-semibold text-gray-900">Test History</h3>
                <div class="-mx-6 overflow-x-auto px-6">
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
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $test->device === 'mobile' ? 'bg-purple-50 text-purple-700' : 'bg-blue-50 text-blue-700' }}">
                                    {{ ucfirst($test->device) }}
                                </span>
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
                <h3 class="mt-4 text-sm font-semibold text-gray-900">No performance data yet</h3>
                <p class="mt-1 text-sm text-gray-500">Run your first performance test to see PageSpeed Insights results.</p>
                <div class="mt-6">
                    <button wire:click="runTest"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700 disabled:opacity-50">
                        <svg wire:loading.remove wire:target="runTest" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <svg wire:loading wire:target="runTest" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Run First Test
                    </button>
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- Settings Modal --}}
    <x-ui.modal name="performance-settings" maxWidth="md">
        <form wire:submit="updateSettings">
            <h2 class="text-lg font-semibold text-gray-900">Performance Settings</h2>
            <p class="mt-1 text-sm text-gray-500">Configure test frequency and alerts.</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Test Frequency</label>
                    <x-ui.select wire:model="settingsFrequency" class="mt-1">
                        <option value="manual">Manual only</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                    </x-ui.select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Test Time</label>
                    <x-ui.input wire:model="settingsTestTime" type="time" class="mt-1" />
                </div>

                @if($settingsFrequency === 'weekly')
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Day of Week</label>
                        <x-ui.select wire:model="settingsDayOfWeek" class="mt-1">
                            <option value="0">Sunday</option>
                            <option value="1">Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </x-ui.select>
                    </div>
                @endif

                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-gray-700">Alert on score drop</label>
                    <x-ui.toggle wire:model="settingsAlertOnDrop" :enabled="$settingsAlertOnDrop" />
                </div>

                @if($settingsAlertOnDrop)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Score drop threshold</label>
                        <x-ui.input wire:model="settingsThreshold" type="number" min="1" max="100" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500">Alert when score drops by this many points or more.</p>
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button"
                        @click="$dispatch('close-modal-performance-settings')"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700">
                    Save Settings
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Budget Edit Modal --}}
    <x-ui.modal name="edit-budgets" maxWidth="md">
        <form wire:submit="saveBudgets">
            <h2 class="text-lg font-semibold text-gray-900">Edit Performance Budgets</h2>
            <p class="mt-1 text-sm text-gray-500">Set thresholds for key metrics. Leave empty to skip.</p>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Performance Score (min)</label>
                    <x-ui.input wire:model="budgetForm.performance_score" type="number" min="0" max="100" placeholder="e.g. 80" class="mt-1" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">LCP (max, seconds)</label>
                        <x-ui.input wire:model="budgetForm.lcp" type="number" step="0.1" min="0" placeholder="e.g. 2.5" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">FCP (max, seconds)</label>
                        <x-ui.input wire:model="budgetForm.fcp" type="number" step="0.1" min="0" placeholder="e.g. 1.8" class="mt-1" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">CLS (max)</label>
                        <x-ui.input wire:model="budgetForm.cls" type="number" step="0.001" min="0" placeholder="e.g. 0.1" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">TBT (max, ms)</label>
                        <x-ui.input wire:model="budgetForm.tbt" type="number" min="0" placeholder="e.g. 200" class="mt-1" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Speed Index (max, seconds)</label>
                    <x-ui.input wire:model="budgetForm.si" type="number" step="0.1" min="0" placeholder="e.g. 3.4" class="mt-1" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Total Page Size (max, bytes)</label>
                        <x-ui.input wire:model="budgetForm.total_size_bytes" type="number" min="0" placeholder="e.g. 2000000" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">JS Size (max, bytes)</label>
                        <x-ui.input wire:model="budgetForm.js_size" type="number" min="0" placeholder="e.g. 500000" class="mt-1" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Image Size (max, bytes)</label>
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
                    Save Budgets
                </button>
            </div>
        </form>
    </x-ui.modal>
</div>
