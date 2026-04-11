<div>
    <x-ui.page-header title="{{ __('Core Web Vitals') }}" subtitle="{{ __('Real-world performance metrics for mobile and desktop') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    @php
        $mobile  = $this->latestMobile;
        $desktop = $this->latestDesktop;
        $hasData = $mobile !== null || $desktop !== null;

        /**
         * Vitals definition: [metric key, label, unit, format callback label]
         * We handle formatting inline for clarity.
         */
        $vitals = [
            ['key' => 'field_lcp', 'label' => 'LCP',  'unit' => 's',   'desc' => __('Largest Contentful Paint')],
            ['key' => 'field_cls', 'label' => 'CLS',  'unit' => '',    'desc' => __('Cumulative Layout Shift')],
            ['key' => 'field_inp', 'label' => 'INP',  'unit' => 'ms',  'desc' => __('Interaction to Next Paint')],
            ['key' => 'field_fcp', 'label' => 'FCP',  'unit' => 's',   'desc' => __('First Contentful Paint')],
        ];
    @endphp

    @if(!$hasData)
        {{-- Empty state --}}
        <div class="rounded-xl border border-gray-200 bg-white p-12 text-center dark:border-gray-700 dark:bg-gray-800">
            <svg class="mx-auto mb-4 h-12 w-12 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            <h3 class="mb-1 text-sm font-semibold text-gray-900 dark:text-white">{{ __('No performance data yet') }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Run a PageSpeed test from the Performance module to collect Core Web Vitals data.') }}
            </p>
        </div>
    @else
        {{-- Mobile vs Desktop side-by-side --}}
        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach([['test' => $mobile, 'device' => 'mobile', 'label' => __('Mobile')], ['test' => $desktop, 'device' => 'desktop', 'label' => __('Desktop')]] as $col)
                @php $test = $col['test']; @endphp
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">

                    {{-- Device header --}}
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                        <div class="flex items-center gap-2.5">
                            @if($col['device'] === 'mobile')
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            @else
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                            @endif
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $col['label'] }}</h3>
                        </div>

                        @if($test)
                            @php
                                $score = $test->performance_score;
                                $scoreColorClass = match(true) {
                                    $score === null  => 'text-gray-400 dark:text-gray-500',
                                    $score >= 90     => 'text-green-600 dark:text-green-400',
                                    $score >= 50     => 'text-amber-600 dark:text-amber-400',
                                    default          => 'text-red-600 dark:text-red-400',
                                };
                                $scoreBgClass = match(true) {
                                    $score === null  => 'bg-gray-100 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600',
                                    $score >= 90     => 'bg-green-50 ring-green-300 dark:bg-green-900/20 dark:ring-green-700',
                                    $score >= 50     => 'bg-amber-50 ring-amber-300 dark:bg-amber-900/20 dark:ring-amber-700',
                                    default          => 'bg-red-50 ring-red-300 dark:bg-red-900/20 dark:ring-red-700',
                                };
                            @endphp
                            <div class="flex items-center gap-2">
                                <div class="flex h-11 w-11 items-center justify-center rounded-full ring-2 {{ $scoreBgClass }}">
                                    <span class="text-sm font-bold {{ $scoreColorClass }}">
                                        {{ $score ?? '—' }}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Score') }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $test->tested_at?->diffForHumans() ?? '—' }}</p>
                                </div>
                            </div>
                        @else
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                                {{ __('No data') }}
                            </span>
                        @endif
                    </div>

                    {{-- Vitals grid --}}
                    @if($test)
                        <div class="grid grid-cols-2 gap-px bg-gray-100 dark:bg-gray-700">
                            @foreach($vitals as $vital)
                                @php
                                    $rawValue = $test->{$vital['key']};
                                    $colors   = $this->metricColorClasses($vital['key'], $rawValue !== null ? (float) $rawValue : null);
                                    $formatted = $test->formatMetric($vital['key']);
                                    $ratingLabel = match(true) {
                                        $rawValue === null => __('N/A'),
                                        $colors['text'] === 'text-green-600 dark:text-green-400' => __('Good'),
                                        $colors['text'] === 'text-amber-600 dark:text-amber-400' => __('Needs Improvement'),
                                        default => __('Poor'),
                                    };
                                @endphp
                                <div class="{{ $colors['bg'] }} p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        {{ $vital['label'] }}
                                    </p>
                                    <p class="mt-1 text-xl font-bold {{ $colors['text'] }}">{{ $formatted }}</p>
                                    <div class="mt-1.5 flex items-center justify-between">
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $vital['desc'] }}</p>
                                        <span class="rounded-full px-1.5 py-0.5 text-xs font-medium {{ $colors['badge'] }}">
                                            {{ $ratingLabel }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="px-5 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                            {{ __('No :device test results available.', ['device' => $col['label']]) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Thresholds legend --}}
        <div class="mb-6 flex flex-wrap items-center gap-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/50">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Thresholds') }}:</span>
            <span class="text-xs text-gray-600 dark:text-gray-400">
                <span class="font-semibold text-green-600 dark:text-green-400">{{ __('Good') }}</span>
                — LCP ≤ 2.5s &middot; CLS ≤ 0.1 &middot; INP ≤ 200ms &middot; FCP ≤ 1.8s
            </span>
            <span class="text-xs text-gray-600 dark:text-gray-400">
                <span class="font-semibold text-amber-600 dark:text-amber-400">{{ __('Needs Improvement') }}</span>
                — LCP ≤ 4.0s &middot; CLS ≤ 0.25 &middot; INP ≤ 500ms &middot; FCP ≤ 3.0s
            </span>
            <span class="text-xs text-gray-600 dark:text-gray-400">
                <span class="font-semibold text-red-600 dark:text-red-400">{{ __('Poor') }}</span>
                — {{ __('above those values') }}
            </span>
        </div>

        {{-- Recent Test History --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Recent Test History') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Last 10 performance tests across all devices') }}</p>
            </div>

            @php $history = $this->recentHistory; @endphp
            @if($history->isEmpty())
                <div class="px-5 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                    {{ __('No test history available.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/30">
                                <th class="px-5 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Date') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Device') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Score') }}</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">LCP</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">CLS</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">INP</th>
                                <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">FCP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($history as $test)
                                @php
                                    $rowScore = $test->performance_score;
                                    $rowScoreColor = match(true) {
                                        $rowScore === null => 'text-gray-400 dark:text-gray-500',
                                        $rowScore >= 90   => 'text-green-600 dark:text-green-400',
                                        $rowScore >= 50   => 'text-amber-600 dark:text-amber-400',
                                        default           => 'text-red-600 dark:text-red-400',
                                    };
                                @endphp
                                <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="whitespace-nowrap px-5 py-2.5 text-xs text-gray-600 dark:text-gray-400">
                                        {{ $test->tested_at?->format('M d, Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $test->device === 'mobile'
                                                ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                                : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-400' }}">
                                            {{ ucfirst($test->device) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center text-xs font-bold {{ $rowScoreColor }}">
                                        {{ $rowScore ?? '—' }}
                                    </td>
                                    @foreach([
                                        ['field_lcp', 'field_lcp'],
                                        ['field_cls', 'field_cls'],
                                        ['field_inp', 'field_inp'],
                                        ['field_fcp', 'field_fcp'],
                                    ] as [$metricKey, $colorKey])
                                        @php
                                            $mv = $test->{$metricKey};
                                            $mc = $this->metricColorClasses($colorKey, $mv !== null ? (float) $mv : null);
                                        @endphp
                                        <td class="px-4 py-2.5 text-center text-xs font-medium {{ $mc['text'] }}">
                                            {{ $test->formatMetric($metricKey) }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
