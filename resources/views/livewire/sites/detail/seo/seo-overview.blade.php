<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    <x-ui.page-header title="{{ __('SEO Overview') }}" subtitle="{{ __('Monitor your site\'s search engine optimization health') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Module not active banner --}}
    @if(!$this->isModuleActive)
        <x-ui.module-activation-banner
            title="{{ __('SEO monitoring is not active') }}"
            description="{{ __('Enable SEO auditing and keyword tracking for this site.') }}"
            icon="search"
        >
            <x-ui.button size="sm" wire:click="activateModule">{{ __('Activate') }}</x-ui.button>
        </x-ui.module-activation-banner>
    @endif

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="audit" :jobs="$trackedJobs" title="{{ __('Running SEO audit...') }}" />

    @if($this->isModuleActive)
        @php
            $latestAudit = $this->latestAudit;
            $score = $latestAudit?->score;
            $scoreColor = match(true) {
                $score === null => 'text-gray-400',
                $score >= 80    => 'text-green-600',
                $score >= 50    => 'text-yellow-500',
                default         => 'text-red-600',
            };
            $scoreBg = match(true) {
                $score === null => 'bg-gray-100',
                $score >= 80    => 'bg-green-50',
                $score >= 50    => 'bg-yellow-50',
                default         => 'bg-red-50',
            };
            $scoreRing = match(true) {
                $score === null => 'ring-gray-200',
                $score >= 80    => 'ring-green-400',
                $score >= 50    => 'ring-yellow-400',
                default         => 'ring-red-400',
            };
            $scoreLabel = match(true) {
                $score === null => __('Not Audited'),
                $score >= 80    => __('Good'),
                $score >= 50    => __('Needs Attention'),
                default         => __('Poor'),
            };
        @endphp

        {{-- Score + Quick Actions --}}
        <div class="mb-6">
            <x-ui.card>
                <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                    {{-- Score Circle --}}
                    <div class="flex items-center gap-6">
                        <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full ring-4 {{ $scoreBg }} {{ $scoreRing }}">
                            <span class="text-3xl font-bold {{ $scoreColor }}">
                                {{ $score !== null ? $score : '—' }}
                            </span>
                        </div>
                        <div>
                            <p class="text-lg font-semibold text-gray-900">{{ $scoreLabel }}</p>
                            @if($latestAudit)
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('Last audit') }}: {{ $latestAudit->created_at->diffForHumans() }}
                                </p>
                            @else
                                <p class="mt-1 text-sm text-gray-400">{{ __('Run your first SEO audit to get a score.') }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Quick Actions --}}
                    <div class="flex items-center gap-3">
                        <x-ui.button variant="primary" size="sm" wire:click="runAudit" wire:loading.attr="disabled" wire:target="runAudit">
                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="runAudit" />
                            {{ __('Run Audit') }}
                        </x-ui.button>
                        <select wire:change="updateSchedule($event.target.value)" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-600 focus:border-purple-500 focus:outline-none dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            <option value="off" {{ !$this->auditSchedule ? 'selected' : '' }}>{{ __('No schedule') }}</option>
                            <option value="daily" {{ $this->auditSchedule === 'daily' ? 'selected' : '' }}>{{ __('Daily') }}</option>
                            <option value="weekly" {{ $this->auditSchedule === 'weekly' ? 'selected' : '' }}>{{ __('Weekly') }}</option>
                            <option value="monthly" {{ $this->auditSchedule === 'monthly' ? 'selected' : '' }}>{{ __('Monthly') }}</option>
                        </select>
                        @if($latestAudit)
                            <a href="{{ route('sites.seo.audit', $site) }}"
                               class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50">
                                {{ __('View Audit Results') }} &rarr;
                            </a>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        </div>

        {{-- Connector Warning --}}
        @if($latestAudit && ($latestAudit->data['_connector_failed'] ?? false))
            <div class="mb-4 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-900/20">
                <svg class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                <div>
                    <span class="text-sm font-medium text-red-800 dark:text-red-300">{{ __('Last audit has incomplete data') }}</span>
                    <span class="ml-1 text-sm text-red-600 dark:text-red-400">— {{ __('WordPress connector was unreachable. Re-run the audit to get full results (plugin detection, robots.txt, sitemaps, etc.).') }}</span>
                </div>
            </div>
        @endif

        {{-- Connection Status --}}
        <div class="mb-6 flex flex-wrap gap-3">
            @if($this->seoPlugin)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 ring-1 ring-green-200">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                    {{ $this->seoPlugin }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-500 ring-1 ring-gray-200">
                    {{ __('No SEO Plugin') }}
                </span>
            @endif
            @if($this->hasSearchConsole)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 ring-1 ring-green-200">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                    {{ __('Search Console Connected') }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-yellow-50 px-3 py-1 text-xs font-medium text-yellow-700 ring-1 ring-yellow-200">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                    {{ __('Search Console Not Connected') }}
                </span>
            @endif
        </div>

        {{-- Issue Summary --}}
        @if($latestAudit)
            @php
                $issueCounts = [
                    'critical' => $latestAudit->critical_count,
                    'high' => $latestAudit->high_count,
                    'medium' => $latestAudit->medium_count,
                    'low' => $latestAudit->low_count,
                ];
                $issueCards = [
                    ['key' => 'critical', 'label' => __('Critical'), 'bg' => 'bg-red-50', 'border' => 'border-red-200', 'text' => 'text-red-700', 'count_class' => 'text-red-600'],
                    ['key' => 'high',     'label' => __('High'),     'bg' => 'bg-orange-50', 'border' => 'border-orange-200', 'text' => 'text-orange-700', 'count_class' => 'text-orange-600'],
                    ['key' => 'medium',   'label' => __('Medium'),   'bg' => 'bg-yellow-50', 'border' => 'border-yellow-200', 'text' => 'text-yellow-700', 'count_class' => 'text-yellow-600'],
                    ['key' => 'low',      'label' => __('Low'),      'bg' => 'bg-blue-50',   'border' => 'border-blue-200',   'text' => 'text-blue-700',   'count_class' => 'text-blue-600'],
                ];
            @endphp
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach($issueCards as $card)
                    <a href="{{ route('sites.seo.audit', $site) }}?severity={{ $card['key'] }}">
                        <div class="rounded-xl border {{ $card['border'] }} {{ $card['bg'] }} p-4 transition hover:shadow-sm">
                            <p class="text-2xl font-bold {{ $card['count_class'] }}">
                                {{ $issueCounts[$card['key']] ?? 0 }}
                            </p>
                            <p class="mt-0.5 text-sm font-medium {{ $card['text'] }}">{{ $card['label'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- SEO Plugin Status --}}
            <x-ui.card>
                <h3 class="mb-4 text-base font-semibold text-gray-900">{{ __('SEO Plugin') }}</h3>
                @php $seoPlugin = $latestAudit?->data['seo_plugin'] ?? null; @endphp
                @if($seoPlugin)
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ __('Plugin') }}</span>
                            <span class="text-sm font-medium text-gray-900">{{ $seoPlugin['name'] ?? __('Unknown') }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ __('Version') }}</span>
                            <span class="text-sm text-gray-900">{{ $seoPlugin['version'] ?? '—' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ __('Status') }}</span>
                            @if($seoPlugin['active'] ?? false)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
                                    {{ __('Active') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                    {{ __('Inactive') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="rounded-lg border border-dashed border-gray-200 py-6 text-center">
                        <p class="text-sm text-gray-500">{{ __('No SEO plugin detected') }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ __('Install Yoast SEO, Rank Math, or All in One SEO.') }}</p>
                    </div>
                @endif
            </x-ui.card>

            {{-- Keyword Tracking Summary --}}
            <x-ui.card>
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Keyword Tracking') }}</h3>
                    <a href="{{ route('sites.seo.keywords', $site) }}" class="text-xs text-purple-600 hover:text-purple-700">
                        {{ __('Manage') }} &rarr;
                    </a>
                </div>
                @php $keywordCount = $this->keywordsCount; @endphp
                @if($keywordCount > 0)
                    <div class="flex items-center gap-4">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-purple-50 ring-2 ring-purple-200">
                            <span class="text-xl font-bold text-purple-600">{{ $keywordCount }}</span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">
                                {{ $keywordCount }} {{ Str::plural(__('keyword'), $keywordCount) }} {{ __('tracked') }}
                            </p>
                            <a href="{{ route('sites.seo.keywords', $site) }}" class="mt-1 text-xs text-purple-600 hover:text-purple-700">
                                {{ __('View rankings') }} &rarr;
                            </a>
                        </div>
                    </div>
                @else
                    <div class="rounded-lg border border-dashed border-gray-200 py-6 text-center">
                        <p class="text-sm text-gray-500">{{ __('No keywords tracked yet') }}</p>
                        <a href="{{ route('sites.seo.keywords', $site) }}" class="mt-1 inline-block text-xs text-purple-600 hover:text-purple-700">
                            {{ __('Add keywords') }} &rarr;
                        </a>
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- Recent Audits Table --}}
        @if($this->recentAudits->isNotEmpty())
            <div class="mt-6">
                <x-ui.card>
                    <h3 class="mb-4 text-base font-semibold text-gray-900">{{ __('Recent Audits') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="pb-2 text-left font-medium text-gray-500">{{ __('Date') }}</th>
                                    <th class="pb-2 text-center font-medium text-gray-500">{{ __('Score') }}</th>
                                    <th class="pb-2 text-center font-medium text-gray-500">{{ __('Issues') }}</th>
                                    <th class="pb-2 text-right font-medium text-gray-500">{{ __('Duration') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($this->recentAudits as $audit)
                                    @php
                                        $auditScore = $audit->score;
                                        $auditScoreColor = match(true) {
                                            $auditScore === null => 'text-gray-400',
                                            $auditScore >= 80    => 'text-green-600',
                                            $auditScore >= 50    => 'text-yellow-500',
                                            default              => 'text-red-600',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="py-2.5 text-gray-700">
                                            {{ $audit->created_at->format('M d, Y H:i') }}
                                        </td>
                                        <td class="py-2.5 text-center font-bold {{ $auditScoreColor }}">
                                            {{ $auditScore ?? '—' }}
                                        </td>
                                        <td class="py-2.5 text-center text-gray-600">
                                            {{ $audit->total_issues ?? 0 }}
                                        </td>
                                        <td class="py-2.5 text-right text-gray-500">
                                            @if($audit->scan_duration)
                                                {{ $audit->scan_duration }}s
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            </div>
        @endif
    @endif
</div>
