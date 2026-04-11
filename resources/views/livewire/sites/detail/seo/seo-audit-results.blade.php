<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    <x-ui.page-header title="{{ __('Audit Results') }}" subtitle="{{ __('Detailed SEO issues from the latest audit') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="audit" :jobs="$trackedJobs" title="{{ __('Running SEO audit...') }}" />

    @if($this->audit)
        {{-- Audit meta bar --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-4 text-sm text-gray-500">
                <span>{{ __('Audited') }}: <strong class="text-gray-900">{{ $this->audit->created_at->format('M d, Y H:i') }}</strong></span>
                @if($this->audit->scan_duration)
                    <span>{{ __('Duration') }}: <strong class="text-gray-900">{{ $this->audit->scan_duration }}s</strong></span>
                @endif
                <span>{{ __('Score') }}:
                    @php
                        $score = $this->audit->score;
                        $scoreColor = match(true) {
                            $score === null => 'text-gray-400',
                            $score >= 80    => 'text-green-600',
                            $score >= 50    => 'text-yellow-500',
                            default         => 'text-red-600',
                        };
                    @endphp
                    <strong class="{{ $scoreColor }}">{{ $score ?? '—' }}</strong>
                </span>
            </div>
            <x-ui.button variant="primary" size="sm" wire:click="rerunAudit" wire:loading.attr="disabled" wire:target="rerunAudit">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="rerunAudit" />
                {{ __('Re-run Audit') }}
            </x-ui.button>
        </div>

        {{-- SEO Plugin Status --}}
        @if($this->audit->seo_plugin)
            <div class="mb-4 flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 dark:border-green-800 dark:bg-green-900/20">
                <svg class="h-5 w-5 shrink-0 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <span class="text-sm font-medium text-green-800 dark:text-green-300">{{ __('SEO Plugin') }}:</span>
                    <span class="ml-1 text-sm text-green-700 dark:text-green-400">{{ $this->audit->seo_plugin }}{{ $this->audit->seo_plugin_version ? ' v'.$this->audit->seo_plugin_version : '' }}</span>
                </div>
            </div>
        @else
            <div class="mb-4 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-900/20">
                <svg class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.072 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <div>
                    <span class="text-sm font-medium text-red-800 dark:text-red-300">{{ __('No SEO plugin detected') }}</span>
                    <span class="ml-1 text-sm text-red-600 dark:text-red-400">— {{ __('Install Yoast SEO or Rank Math for better optimization control.') }}</span>
                </div>
            </div>
        @endif

        {{-- Severity Filter --}}
        @php
            $theAudit = $this->audit;
            $filters = [
                ['key' => 'all',      'label' => __('All'),      'count' => $theAudit->total_issues],
                ['key' => 'critical', 'label' => __('Critical'), 'count' => $theAudit->critical_count],
                ['key' => 'high',     'label' => __('High'),     'count' => $theAudit->high_count],
                ['key' => 'medium',   'label' => __('Medium'),   'count' => $theAudit->medium_count],
                ['key' => 'low',      'label' => __('Low'),      'count' => $theAudit->low_count],
                ['key' => 'info',     'label' => __('Info'),     'count' => $theAudit->info_count],
            ];
        @endphp
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($filters as $filter)
                <button wire:click="setFilterSeverity('{{ $filter['key'] }}')"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition
                               {{ $filterSeverity === $filter['key']
                                   ? 'bg-purple-600 text-white'
                                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $filter['label'] }}
                    @if($filter['count'] > 0)
                        <span class="rounded-full px-1.5 py-0.5
                                     {{ $filterSeverity === $filter['key'] ? 'bg-purple-500 text-white' : 'bg-gray-200 text-gray-600' }}">
                            {{ $filter['count'] }}
                        </span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Issues grouped by category --}}
        @php
            $issuesByCategory = $this->issuesByCategory;
            $severityColors = [
                'critical' => 'bg-red-100 text-red-700',
                'high'     => 'bg-orange-100 text-orange-700',
                'medium'   => 'bg-yellow-100 text-yellow-700',
                'low'      => 'bg-blue-100 text-blue-700',
                'info'     => 'bg-gray-100 text-gray-600',
            ];
        @endphp

        @if($issuesByCategory->isNotEmpty())
            <div class="space-y-4" x-data="{ openCategories: {} }">
                @foreach($issuesByCategory as $category => $issues)
                    @php
                        $categoryIssueCount = count($issues);
                        $categoryKey = Str::slug($category);
                        $worstSeverity = collect($issues)->sortBy(fn($i) => match($i->severity ?? 'info') {
                            'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, default => 4,
                        })->first()?->severity ?? 'info';
                    @endphp
                    <x-ui.card :padding="false">
                        {{-- Category header --}}
                        <button
                            class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-gray-50"
                            @click="openCategories['{{ $categoryKey }}'] = !openCategories['{{ $categoryKey }}']"
                            x-init="openCategories['{{ $categoryKey }}'] = true"
                        >
                            <div class="flex items-center gap-3">
                                <h3 class="text-sm font-semibold text-gray-900">{{ $category }}</h3>
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                    {{ $categoryIssueCount }}
                                </span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $severityColors[$worstSeverity] ?? 'bg-gray-100 text-gray-600' }}">
                                    {{ ucfirst($worstSeverity) }}
                                </span>
                            </div>
                            <svg class="h-4 w-4 text-gray-400 transition-transform"
                                 :class="openCategories['{{ $categoryKey }}'] ? 'rotate-180' : ''"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        {{-- Issues list --}}
                        <div x-show="openCategories['{{ $categoryKey }}']"
                             x-collapse
                             class="border-t border-gray-100">
                            <div class="divide-y divide-gray-50">
                                @foreach($issues as $issue)
                                    @php $severity = $issue->severity ?? 'info'; @endphp
                                    <div class="px-4 py-3">
                                        <div class="flex items-start gap-3">
                                            {{-- Severity badge --}}
                                            <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $severityColors[$severity] ?? 'bg-gray-100 text-gray-600' }}">
                                                {{ ucfirst($severity) }}
                                            </span>

                                            {{-- Issue content --}}
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium text-gray-900">{{ $issue->title }}</p>

                                                @if($issue->description)
                                                    <p class="mt-0.5 text-xs text-gray-500">{{ $issue->description }}</p>
                                                @endif

                                                @if($issue->url)
                                                    <a href="{{ $issue->url }}" target="_blank" rel="noopener noreferrer"
                                                       class="mt-1 inline-flex items-center gap-1 text-xs text-purple-600 hover:text-purple-700">
                                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                                        </svg>
                                                        {{ Str::limit($issue->url, 60) }}
                                                    </a>
                                                @endif

                                                @if($issue->recommendation)
                                                    <div class="mt-2 rounded-lg bg-blue-50 px-3 py-2">
                                                        <p class="text-xs text-blue-700">
                                                            <strong>{{ __('Recommendation') }}:</strong> {{ $issue->recommendation }}
                                                        </p>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        @else
            <x-ui.card>
                <x-ui.empty-state
                    title="{{ __('No issues found') }}"
                    description="{{ $filterSeverity ? __('No issues match the selected filter.') : __('Your site passed all SEO checks.') }}"
                    icon="check-circle"
                />
            </x-ui.card>
        @endif

    @else
        {{-- No audit yet --}}
        <x-ui.card>
            <x-ui.empty-state
                title="{{ __('No audit results yet') }}"
                description="{{ __('Run your first SEO audit to see detailed issue reports for this site.') }}"
                icon="search"
            >
                <x-slot:action>
                    <x-ui.button variant="primary" wire:click="rerunAudit" wire:loading.attr="disabled" wire:target="rerunAudit">
                        <span wire:loading.remove wire:target="rerunAudit">{{ __('Run First Audit') }}</span>
                        <span wire:loading wire:target="rerunAudit">{{ __('Starting...') }}</span>
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @endif
</div>
