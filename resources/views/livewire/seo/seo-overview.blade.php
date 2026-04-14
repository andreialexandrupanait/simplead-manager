<div class="min-w-0">
    <x-ui.page-header title="SEO Overview" subtitle="SEO audit scores across all your sites">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('seo.quick-audit') }}"><x-icons.search class="h-4 w-4" /> Quick Audit</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Stat cards --}}
    <div class="mb-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-4">
        <x-ui.stat-card label="Total Sites" :value="$this->stats['total_sites']" icon="globe" color="purple" />
        <x-ui.stat-card label="Audited" :value="$this->stats['audited_sites']" icon="check-circle" color="blue" />
        <x-ui.stat-card label="Avg Score" :value="$this->stats['avg_score']" icon="zap" :color="$this->stats['avg_score']>=80?'green':($this->stats['avg_score']>=50?'yellow':'red')" />
        <x-ui.stat-card label="Needs Attention" :value="$this->stats['needs_attention']" icon="alert-triangle" :color="$this->stats['needs_attention']>0?'orange':'green'" />
        <x-ui.stat-card label="Critical Issues" :value="$this->stats['total_critical']" icon="shield-alert" :color="$this->stats['total_critical']>0?'red':'green'" />
        <x-ui.stat-card label="Broken Links" :value="$this->stats['total_broken_links']" icon="link" :color="$this->stats['total_broken_links']>0?'red':'green'" />
        <x-ui.stat-card label="Broken Images" :value="$this->stats['total_broken_images']" icon="image" :color="$this->stats['total_broken_images']>0?'red':'green'" />
    </div>

    {{-- Charts row --}}
    @if($this->stats['audited_sites'] > 0)
    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Score distribution --}}
        <x-ui.card>
            <h3 class="mb-3 text-sm font-medium text-gray-900">Score Distribution</h3>
            <x-charts.bar-chart
                :labels="array_keys($this->scoreDistribution)"
                :data="array_values($this->scoreDistribution)"
                :horizontal="false"
                height="200px"
                color="#8D5CF5"
            />
        </x-ui.card>

        {{-- Category averages --}}
        <x-ui.card>
            <h3 class="mb-3 text-sm font-medium text-gray-900">Average Category Scores</h3>
            <x-charts.bar-chart
                :labels="['Technical', 'On-Page', 'Performance', 'Other']"
                :data="array_values($this->categoryAverages)"
                :horizontal="true"
                height="200px"
                color="#10B981"
            />
        </x-ui.card>
    </div>

    {{-- Top Issues --}}
    @if($this->topIssues->isNotEmpty())
    <x-ui.card class="mb-6">
        <h3 class="mb-3 text-sm font-medium text-gray-900">Most Common Issues</h3>
        <div class="space-y-2">
            @foreach($this->topIssues as $ti)
                <div class="flex items-center gap-3">
                    @php $sevVal = $ti->severity instanceof \App\Enums\SeoIssueSeverity ? $ti->severity->value : $ti->severity; @endphp
                    <span class="h-2 w-2 shrink-0 rounded-full {{ ['critical'=>'bg-red-500','high'=>'bg-orange-500','medium'=>'bg-yellow-500','low'=>'bg-blue-500','info'=>'bg-gray-400'][$sevVal] ?? 'bg-gray-400' }}"></span>
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-700">{{ $ti->title }}</span>
                    <x-ui.badge variant="gray">{{ $ti->sites_affected }} {{ (int) $ti->sites_affected === 1 ? 'site' : 'sites' }}</x-ui.badge>
                </div>
            @endforeach
        </div>
    </x-ui.card>
    @endif
    @endif

    {{-- Tabs --}}
    <div class="mb-4 flex gap-1 rounded-lg bg-gray-100 p-1">
        <button wire:click="$set('activeTab','portfolio')" class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $activeTab==='portfolio' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">Portfolio Sites</button>
        <button wire:click="$set('activeTab','quick-audits')" class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $activeTab==='quick-audits' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
            Quick Audits
            @if($this->prospectSites->isNotEmpty()) <span class="ml-1 text-xs text-gray-400">({{ $this->prospectSites->count() }})</span> @endif
        </button>
    </div>

    {{-- PORTFOLIO TAB --}}
    @if($activeTab === 'portfolio')
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="Search sites..." class="w-full sm:w-64" />
            <select wire:model.live="scoreFilter" class="rounded-lg border-gray-300 text-sm shadow-sm"><option value="">All Scores</option><option value="good">Good (80+)</option><option value="needs_work">Needs Work (50-79)</option><option value="poor">Poor (&lt;50)</option><option value="no_audit">No Audit</option></select>
            <select wire:model.live="sort" class="rounded-lg border-gray-300 text-sm shadow-sm"><option value="manual">Manual Order</option><option value="score_asc">Score: Low→High</option><option value="score_desc">Score: High→Low</option><option value="issues">Most Critical</option><option value="name">Name</option></select>
        </div>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th>Site</x-ui.th>
                <x-ui.th class="text-center">Score</x-ui.th>
                <x-ui.th class="hidden text-center xl:table-cell">Technical</x-ui.th>
                <x-ui.th class="hidden text-center xl:table-cell">On-Page</x-ui.th>
                <x-ui.th class="hidden text-center xl:table-cell">Performance</x-ui.th>
                <x-ui.th>Issues</x-ui.th>
                <x-ui.th class="hidden xl:table-cell text-center">Links</x-ui.th>
                <x-ui.th class="hidden xl:table-cell text-center">Images</x-ui.th>
                <x-ui.th class="hidden lg:table-cell">Last Scan</x-ui.th>
                <x-ui.th class="text-right">Action</x-ui.th>
            </x-slot:head>
            @forelse($this->sites as $site)
                @php
                    $audit = $site->latestSeoAudit;
                    $score = $audit?->score;
                    $sc = $score===null?'text-gray-400':($score>=80?'text-green-600':($score>=50?'text-yellow-600':'text-red-600'));
                    $cats = $audit?->category_scores ?? [];
                    $running = $site->running_audits_count > 0;
                @endphp
                <tr class="hover:bg-gray-50">
                    <x-ui.td>
                        <div class="flex items-center gap-2">
                            <x-site-favicon :site="$site" size="xs" />
                            <div class="min-w-0">
                                <a href="{{ route('sites.seo', $site) }}" class="text-sm font-medium text-gray-900 hover:text-accent-600 truncate block">{{ $site->name }}</a>
                                <p class="text-xs text-gray-400 truncate">{{ parse_url($site->url, PHP_URL_HOST) }}</p>
                            </div>
                        </div>
                    </x-ui.td>
                    <x-ui.td class="text-center"><span class="text-lg font-bold {{ $sc }}">{{ $score ?? '—' }}</span></x-ui.td>
                    <x-ui.td class="hidden text-center xl:table-cell">
                        @if($cats) @php $v=$cats['technical']??0; @endphp <span class="text-sm font-medium {{ $v>=80?'text-green-600':($v>=50?'text-yellow-600':'text-red-600') }}">{{ $v }}</span> @else <span class="text-gray-300">—</span> @endif
                    </x-ui.td>
                    <x-ui.td class="hidden text-center xl:table-cell">
                        @if($cats) @php $v=$cats['on_page']??0; @endphp <span class="text-sm font-medium {{ $v>=80?'text-green-600':($v>=50?'text-yellow-600':'text-red-600') }}">{{ $v }}</span> @else <span class="text-gray-300">—</span> @endif
                    </x-ui.td>
                    <x-ui.td class="hidden text-center xl:table-cell">
                        @if($cats) @php $v=$cats['performance']??0; @endphp <span class="text-sm font-medium {{ $v>=80?'text-green-600':($v>=50?'text-yellow-600':'text-red-600') }}">{{ $v }}</span> @else <span class="text-gray-300">—</span> @endif
                    </x-ui.td>
                    <x-ui.td>
                        <div class="flex flex-wrap gap-1">
                            @if($audit)
                                @if($audit->critical_count > 0)<x-ui.badge variant="red">{{ $audit->critical_count }} Critical</x-ui.badge>@endif
                                @if($audit->high_count > 0)<x-ui.badge variant="orange">{{ $audit->high_count }} High</x-ui.badge>@endif
                                @if($audit->medium_count > 0)<x-ui.badge variant="yellow">{{ $audit->medium_count }} Medium</x-ui.badge>@endif
                            @else
                                <span class="text-xs text-gray-300">No audit</span>
                            @endif
                        </div>
                    </x-ui.td>
                    <x-ui.td class="hidden xl:table-cell text-center">
                        @if($audit && ($audit->broken_links_count ?? 0) > 0)
                            <span class="text-sm font-medium text-red-600">{{ $audit->broken_links_count }}</span>
                        @elseif($audit)
                            <span class="text-sm text-green-600">0</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="hidden xl:table-cell text-center">
                        @if($audit && ($audit->broken_images_count ?? 0) > 0)
                            <span class="text-sm font-medium text-red-600">{{ $audit->broken_images_count }}</span>
                        @elseif($audit)
                            <span class="text-sm text-green-600">0</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </x-ui.td>
                    <x-ui.td class="hidden lg:table-cell"><span class="text-xs text-gray-400">{{ $audit?->scanned_at?->diffForHumans() ?? 'Never' }}</span></x-ui.td>
                    <x-ui.td class="text-right">
                        @if($running)
                            <span class="inline-flex items-center gap-1 text-xs text-blue-600"><x-ui.spinner size="xs" /> Running</span>
                        @else
                            <x-ui.button variant="ghost" size="xs" wire:click="runAudit({{ $site->id }})">Audit</x-ui.button>
                        @endif
                    </x-ui.td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500">No sites match your filters.</td></tr>
            @endforelse
        </x-ui.table>
    @endif

    {{-- QUICK AUDITS TAB --}}
    @if($activeTab === 'quick-audits')
        <div class="mb-4">
            <x-ui.button href="{{ route('seo.quick-audit') }}"><x-icons.plus class="h-4 w-4" /> New Quick Audit</x-ui.button>
        </div>

        @if($this->prospectSites->isNotEmpty())
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Site</x-ui.th>
                    <x-ui.th class="text-center">Score</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Issues</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Pages</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Last Scan</x-ui.th>
                    <x-ui.th class="text-right">Action</x-ui.th>
                </x-slot:head>
                @foreach($this->prospectSites as $ps)
                    @php $pa = $ps->latestSeoAudit; $psc = $pa?->score; @endphp
                    <tr class="hover:bg-gray-50">
                        <x-ui.td>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $ps->name }}</p>
                                <p class="text-xs text-gray-400 truncate">{{ $ps->url }}</p>
                            </div>
                        </x-ui.td>
                        <x-ui.td class="text-center">
                            <span class="text-lg font-bold {{ $psc!==null?($psc>=80?'text-green-600':($psc>=50?'text-yellow-600':'text-red-600')):'text-gray-400' }}">{{ $psc ?? '—' }}</span>
                        </x-ui.td>
                        <x-ui.td class="hidden lg:table-cell">
                            @if($pa)
                                @if($pa->critical_count > 0)<x-ui.badge variant="red">{{ $pa->critical_count }} Critical</x-ui.badge>@endif
                                @if($pa->high_count > 0)<x-ui.badge variant="orange">{{ $pa->high_count }} High</x-ui.badge>@endif
                                @if($pa->medium_count > 0)<x-ui.badge variant="yellow">{{ $pa->medium_count }} Medium</x-ui.badge>@endif
                            @endif
                        </x-ui.td>
                        <x-ui.td class="hidden lg:table-cell">{{ $pa?->pages_crawled ?? '—' }}</x-ui.td>
                        <x-ui.td class="hidden lg:table-cell"><span class="text-xs text-gray-400">{{ $pa?->scanned_at?->diffForHumans() ?? 'Running' }}</span></x-ui.td>
                        <x-ui.td class="text-right">
                            <x-ui.button variant="ghost" size="xs" href="{{ route('seo.quick-audit') }}?view={{ $ps->id }}">View</x-ui.button>
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @else
            <x-ui.card><x-ui.empty-state title="No quick audits yet" description="Run a quick audit to analyze any website." icon="search"><x-slot:action><x-ui.button href="{{ route('seo.quick-audit') }}">New Quick Audit</x-ui.button></x-slot:action></x-ui.empty-state></x-ui.card>
        @endif
    @endif
</div>
