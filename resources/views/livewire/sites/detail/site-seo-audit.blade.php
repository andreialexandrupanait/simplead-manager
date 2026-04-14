<div class="min-w-0" @if($isRunning) wire:poll.2s="checkProgress" @endif>
    <x-ui.page-header title="SEO Audit" subtitle="Crawl and analyze your site for SEO issues">
        <x-slot:actions>
            @if($this->latestCompletedAudit)
                <x-ui.button variant="secondary" wire:click="exportXls"><x-icons.file-text class="h-4 w-4" /> Export XLS</x-ui.button>
            @endif
            <x-ui.button variant="secondary" @click="$dispatch('open-modal-seo-settings')"><x-icons.settings class="h-4 w-4" /> Settings</x-ui.button>
            <x-ui.button wire:click="runAudit" wire:loading.attr="disabled" :disabled="$isRunning">
                <span wire:loading.remove wire:target="runAudit"><x-icons.search class="h-4 w-4" /></span>
                <x-ui.spinner size="sm" wire:loading wire:target="runAudit" />
                {{ $isRunning ? 'Running...' : 'Run Audit' }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Progress stepper --}}
    @if($isRunning && $this->latestAudit)
        @php $progress = app(\App\Services\SeoAudit\SiteAuditService::class)->getAuditProgress($this->latestAudit); $currentStatus = $progress['status']; @endphp
        <x-ui.card class="mb-6 !bg-blue-50 !ring-blue-200">
            <div class="mb-4 flex items-center justify-between">
                @foreach(['crawling' => 'Crawling', 'analyzing' => 'Analyzing', 'scoring' => 'Scoring', 'completed' => 'Complete'] as $step => $label)
                    @php
                        $steps = ['pending' => 0, 'crawling' => 1, 'analyzing' => 2, 'scoring' => 3, 'completed' => 4];
                        $currentIdx = $steps[$currentStatus] ?? 0;
                        $stepIdx = $steps[$step] ?? 0;
                        $isActive = $stepIdx === $currentIdx;
                        $isDone = $stepIdx < $currentIdx;
                    @endphp
                    <div class="flex items-center gap-2 {{ $isActive ? 'text-blue-700 font-semibold' : ($isDone ? 'text-blue-500' : 'text-blue-300') }}">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs {{ $isDone ? 'bg-blue-500 text-white' : ($isActive ? 'bg-blue-600 text-white' : 'bg-blue-200 text-blue-400') }}">
                            @if($isDone) &#10003; @else {{ $stepIdx }} @endif
                        </span>
                        <span class="hidden text-xs sm:inline">{{ $label }}</span>
                    </div>
                    @if(!$loop->last) <div class="mx-1 h-px flex-1 {{ $isDone ? 'bg-blue-400' : 'bg-blue-200' }}"></div> @endif
                @endforeach
            </div>
            <div class="flex items-center gap-3">
                <x-ui.spinner size="md" class="text-blue-600" />
                <div class="flex-1">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-blue-200"><div class="h-full rounded-full bg-blue-600 transition-all duration-500" style="width: {{ $progress['progress_percent'] }}%"></div></div>
                    <p class="mt-1 text-xs text-blue-600">{{ $progress['pages_crawled'] }} / {{ $progress['max_pages'] }} pages</p>
                </div>
            </div>
        </x-ui.card>
    @endif

    @if(! $this->latestCompletedAudit && ! $isRunning)
        <x-ui.card><x-ui.empty-state title="No SEO audit yet" description="Run your first audit to analyze your site." icon="search"><x-slot:action><x-ui.button wire:click="runAudit">Run First Audit</x-ui.button></x-slot:action></x-ui.empty-state></x-ui.card>
    @endif

    @if($this->latestCompletedAudit)
        @php $audit = $this->latestCompletedAudit; @endphp
        {{-- Score cards --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
            <x-ui.card class="text-center lg:col-span-1">
                @php $score=$audit->score; $sc=$score>=80?'text-green-600':($score>=50?'text-yellow-600':'text-red-600'); $rc=$score>=80?'stroke-green-500':($score>=50?'stroke-yellow-500':'stroke-red-500'); @endphp
                <div class="relative mx-auto h-28 w-28">
                    <svg class="h-28 w-28 -rotate-90" viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="none" class="stroke-gray-200" stroke-width="8"/><circle cx="50" cy="50" r="42" fill="none" class="{{ $rc }}" stroke-width="8" stroke-linecap="round" stroke-dasharray="{{ $score*2.64 }} 264"/></svg>
                    <div class="absolute inset-0 flex items-center justify-center"><span class="text-3xl font-bold {{ $sc }}">{{ $score }}</span></div>
                </div>
                <p class="mt-2 text-sm font-medium text-gray-500">Overall Score</p>
                @if($this->auditDiff) @php $d=$this->auditDiff['score_delta']; @endphp <p class="mt-1 text-xs {{ $d>0?'text-green-600':($d<0?'text-red-600':'text-gray-400') }}">{{ $d>0?'+':'' }}{{ $d }} vs previous</p> @endif
            </x-ui.card>
            @foreach(['technical'=>'Technical SEO','on_page'=>'On-Page','performance'=>'Performance','other'=>'Other'] as $key=>$label)
                @php $cs=$this->categoryScores[$key]??0; $cc=$cs>=80?'text-green-600':($cs>=50?'text-yellow-600':'text-red-600'); $bc=$cs>=80?'bg-green-500':($cs>=50?'bg-yellow-500':'bg-red-500'); @endphp
                <x-ui.card>
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold {{ $cc }}">{{ $cs }}</p>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200"><div class="h-full rounded-full {{ $bc }}" style="width:{{ $cs }}%"></div></div>
                    <p class="mt-1 text-xs text-gray-400">{{ config("seo.scoring.weights.{$key}") }}% weight</p>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Severity badges + filters row --}}
        <div class="mb-6 flex flex-wrap items-center gap-3">
            @foreach([['Critical',$audit->critical_count,'red','critical'],['High',$audit->high_count,'orange','high'],['Medium',$audit->medium_count,'yellow','medium'],['Low',$audit->low_count,'blue','low'],['Info',$audit->info_count,'gray','info']] as [$l,$c,$v,$filter])
                <button wire:click="$set('severityFilter','{{ $severityFilter === $filter ? '' : $filter }}');$set('activeTab','issues')" class="transition-opacity {{ $severityFilter !== '' && $severityFilter !== $filter ? 'opacity-40' : '' }}">
                    <x-ui.badge :variant="$v">{{ $c }} {{ $l }}</x-ui.badge>
                </button>
            @endforeach
            <span class="text-sm text-gray-500">{{ $audit->pages_crawled }} pages &middot; {{ $audit->scan_duration ? gmdate('i:s', $audit->scan_duration) : '—' }}</span>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <select wire:model.live="severityFilter" class="rounded-lg border-gray-300 text-sm shadow-sm">
                    <option value="">All Severities</option>
                    @foreach($this->severityOptions as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach
                </select>
                <select wire:model.live="categoryFilter" class="rounded-lg border-gray-300 text-sm shadow-sm">
                    <option value="">All Categories</option>
                    @foreach($this->categoryOptions as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach
                </select>
                @php
                    $totalIssueGroups = $this->groupedIssues->count();
                    $fixableMap = $this->fixableIssueTitles;
                    $fixableCount = $this->groupedIssues->filter(fn($g) => isset($fixableMap[$g->title]))->count();
                    $fixablePct = $totalIssueGroups > 0 ? round(($fixableCount / $totalIssueGroups) * 100) : 0;
                @endphp
                @if($site->is_connected && !($site->is_prospect ?? false) && $fixableCount > 0)
                    <span class="inline-flex items-center gap-1 text-xs text-gray-500">
                        <svg class="h-3.5 w-3.5 text-accent-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span class="font-medium text-accent-600">{{ $fixablePct }}%</span> auto-fixable
                    </span>
                @endif
            </div>
        </div>

        {{-- Tabs --}}
        <div class="mb-4 flex gap-1 overflow-x-auto rounded-lg bg-gray-100 p-1">
            @foreach(['issues'=>'Issues ('.$this->groupedIssues->count().')','pages'=>'Pages','links'=>'Broken Links ('.$this->brokenLinksCount.')','images'=>'Broken Images ('.$this->brokenImagesCount.')','redirects'=>'Redirects ('.$this->redirectPagesCount.')','infrastructure'=>'Infrastructure','history'=>'History'] as $tab=>$label)
                <button wire:click="$set('activeTab','{{ $tab }}')" class="whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $activeTab===$tab ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">{{ $label }}</button>
            @endforeach
        </div>

        {{-- ISSUES TAB --}}
        @if($activeTab==='issues')
            <div class="space-y-2">
                @forelse($this->groupedIssues as $group)
                    @php
                        $fixType = $fixableMap[$group->title] ?? null;
                        $canFix = $site->is_connected && !($site->is_prospect ?? false) && $fixType !== null;
                        $sevColors = ['critical'=>'border-l-red-500 bg-white','high'=>'border-l-orange-400 bg-white','medium'=>'border-l-yellow-400 bg-white','low'=>'border-l-blue-400 bg-white','info'=>'border-l-gray-300 bg-white'];
                        $sevBadge = ['critical'=>'bg-red-100 text-red-700','high'=>'bg-orange-100 text-orange-700','medium'=>'bg-yellow-100 text-yellow-700','low'=>'bg-blue-100 text-blue-700','info'=>'bg-gray-100 text-gray-600'];
                    @endphp
                    <div class="rounded-lg border border-gray-200 border-l-4 {{ $sevColors[$group->severity->value] ?? 'border-l-gray-300' }}" x-data="{ showUrls: false }">
                        <div class="px-4 py-3">
                            {{-- Top row: severity badge + title + meta --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold {{ $sevBadge[$group->severity->value] ?? 'bg-gray-100 text-gray-600' }}">{{ $group->severity->label() }}</span>
                                <h4 class="text-sm font-semibold text-gray-900">{{ $group->title }}</h4>
                                @if($canFix)
                                    <span class="inline-flex items-center gap-0.5 rounded bg-accent-50 px-1.5 py-0.5 text-xs font-medium text-accent-700">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                        Auto-fix
                                    </span>
                                @endif
                                <span class="text-xs text-gray-400">{{ $group->category->label() }}</span>
                                @if($group->affected_count > 1)
                                    <span class="text-xs font-medium text-gray-500">{{ $group->affected_count }} pages</span>
                                @endif
                            </div>

                            {{-- Description --}}
                            @if($group->description)
                                <p class="mt-1.5 text-sm text-gray-600">{{ $group->description }}</p>
                            @endif

                            {{-- Recommendation --}}
                            @if($group->recommendation)
                                <div class="mt-2 flex items-start gap-1.5 rounded-md bg-white/60 px-2.5 py-1.5 text-sm">
                                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-accent-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                    <span class="text-gray-500">{{ $group->recommendation }}</span>
                                </div>
                            @endif

                            {{-- Affected URLs --}}
                            @if($group->urls->isNotEmpty())
                                <div class="mt-2">
                                    <button @click="showUrls = !showUrls" class="inline-flex items-center gap-1 text-xs font-medium text-accent-600 hover:text-accent-700">
                                        <svg class="h-3 w-3 transition-transform" :class="showUrls && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        <span x-text="showUrls ? 'Hide URLs' : 'Show {{ $group->urls->count() }} affected URL{{ $group->urls->count() > 1 ? 's' : '' }}'"></span>
                                    </button>
                                    <div x-show="showUrls" x-collapse class="mt-2 max-h-64 overflow-y-auto space-y-1 border-l-2 border-gray-200 pl-3">
                                        @foreach($group->urls as $url)
                                            <div class="flex items-center gap-2">
                                                <a href="{{ $url }}" target="_blank" class="block truncate text-xs text-gray-400 hover:text-accent-600 flex-1">{{ Str::limit($url, 70) }}</a>
                                                @if($canFix)
                                                    @if($fixType === 'meta')
                                                        <button wire:click="openFixModal('{{ $url }}')" class="shrink-0 rounded bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700 hover:bg-accent-100">Fix</button>
                                                    @elseif($fixType === 'robots')
                                                        <button wire:click="openRobotsFix('{{ $url }}')" class="shrink-0 rounded bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700 hover:bg-accent-100">Fix</button>
                                                    @elseif($fixType === 'canonical')
                                                        <button wire:click="openCanonicalFix('{{ $url }}')" class="shrink-0 rounded bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700 hover:bg-accent-100">Fix</button>
                                                    @elseif($fixType === 'og')
                                                        <button wire:click="openOgFix('{{ $url }}')" class="shrink-0 rounded bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700 hover:bg-accent-100">Fix</button>
                                                    @endif
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-ui.card><x-ui.empty-state title="No issues found" description="Great job — or adjust your filters." icon="check-circle" /></x-ui.card>
                @endforelse
            </div>
        @endif

        {{-- PAGES TAB --}}
        @if($activeTab==='pages')
            {{-- TTFB Insights --}}
            @if(!empty($this->ttfbInsights) && !empty($this->ttfbInsights['slowest']))
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-ui.card class="!p-3">
                        <p class="text-xs font-medium text-gray-500 mb-1">Avg TTFB</p>
                        @php $avg = $this->ttfbInsights['avg_ttfb']; @endphp
                        <p class="text-lg font-bold {{ $avg < 0.2 ? 'text-green-600' : ($avg < 0.5 ? 'text-yellow-600' : 'text-red-600') }}">{{ number_format($avg * 1000) }}ms</p>
                    </x-ui.card>
                    <x-ui.card class="!p-3">
                        <p class="text-xs font-medium text-gray-500 mb-1">Slowest Pages</p>
                        @foreach($this->ttfbInsights['slowest'] as $sp)
                            <p class="text-xs text-red-600 truncate">{{ number_format($sp['ttfb'] * 1000) }}ms — {{ Str::limit(parse_url($sp['url'], PHP_URL_PATH) ?: '/', 30) }}</p>
                        @endforeach
                    </x-ui.card>
                    <x-ui.card class="!p-3">
                        <p class="text-xs font-medium text-gray-500 mb-1">Largest Pages</p>
                        @foreach($this->ttfbInsights['largest'] as $lp)
                            <p class="text-xs text-orange-600 truncate">{{ $lp['size_kb'] }}KB — {{ Str::limit(parse_url($lp['url'], PHP_URL_PATH) ?: '/', 30) }}</p>
                        @endforeach
                    </x-ui.card>
                </div>
            @endif

            <div class="mb-4"><input wire:model.live.debounce.300ms="pageSearch" type="text" placeholder="Search pages..." class="rounded-lg border-gray-300 text-sm shadow-sm sm:w-80"></div>
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>URL</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th>Title Length</x-ui.th>
                    <x-ui.th>Word Count</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Internal Links</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">External Links</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">TTFB</x-ui.th>
                    <x-ui.th>Indexable</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Sitemap</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Blocked</x-ui.th>
                </x-slot:head>
            @forelse($this->pages as $p)
                <tbody x-data="{ expanded: false }">
                <tr class="{{ $p->status_code >= 400 ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50' }} cursor-pointer" @click="expanded = !expanded">
                    <x-ui.td class="max-w-xs !whitespace-normal"><a href="{{ $p->url }}" target="_blank" class="text-accent-600 hover:underline" @click.stop>{{ Str::limit(parse_url($p->url,PHP_URL_PATH)?:'/',50) }}</a></x-ui.td>
                    <x-ui.td><x-ui.badge :variant="$p->status_code===200?'green':($p->status_code>=400?'red':'yellow')">{{ $p->status_code??'—' }}</x-ui.badge></x-ui.td>
                    <x-ui.td title="{{ $p->title }}">@if($p->title_length)<span class="{{ $p->title_length<30||$p->title_length>60?'text-red-600 font-medium':'text-green-600' }}">{{ $p->title_length }}</span>@else<span class="text-red-600 font-medium">Missing</span>@endif</x-ui.td>
                    <x-ui.td><span class="{{ $p->word_count!==null && $p->word_count<300?'text-orange-600 font-medium':'' }}">{{ $p->word_count??'—' }}</span></x-ui.td>
                    <x-ui.td class="hidden lg:table-cell">{{ $p->internal_link_count }}</x-ui.td>
                    <x-ui.td class="hidden lg:table-cell">{{ $p->external_link_count }}</x-ui.td>
                    <x-ui.td class="hidden lg:table-cell">
                        @if($p->ttfb_seconds)
                            <span class="{{ $p->ttfb_seconds < 0.2 ? 'text-green-600' : ($p->ttfb_seconds < 0.5 ? 'text-yellow-600' : 'text-red-600') }}">{{ number_format($p->ttfb_seconds * 1000) }}ms</span>
                        @else — @endif
                    </x-ui.td>
                    <x-ui.td><x-ui.badge :variant="$p->is_indexable?'green':'red'">{{ $p->is_indexable?'Yes':'No' }}</x-ui.badge></x-ui.td>
                    <x-ui.td class="hidden lg:table-cell"><x-ui.badge :variant="$p->in_sitemap ? 'green' : 'gray'">{{ $p->in_sitemap ? 'Yes' : 'No' }}</x-ui.badge></x-ui.td>
                    <x-ui.td class="hidden lg:table-cell">@if($p->blocked_by_robots)<x-ui.badge variant="red">Blocked</x-ui.badge>@else<span class="text-gray-400">—</span>@endif</x-ui.td>
                </tr>
                <tr x-show="expanded" x-collapse>
                    <td colspan="10" class="bg-gray-50 px-6 py-3">
                        <div class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2 lg:grid-cols-3">
                            <div><span class="font-medium text-gray-500">Title:</span> <span class="text-gray-700">{{ $p->title ?? '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Meta Description:</span> <span class="text-gray-700">{{ Str::limit($p->meta_description ?? '—', 100) }}</span></div>
                            <div><span class="font-medium text-gray-500">Canonical:</span> <span class="text-gray-700">{{ $p->canonical_url ? ($p->is_self_canonical ? 'Self' : Str::limit($p->canonical_url, 50)) : 'Missing' }}</span></div>
                            <div><span class="font-medium text-gray-500">H1 Tags:</span> <span class="text-gray-700">{{ !empty($p->h1_tags) ? implode(', ', array_map(fn($h) => Str::limit($h, 40), $p->h1_tags)) : '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Images:</span> <span class="text-gray-700">{{ $p->image_count ?? 0 }} total, {{ $p->images_without_alt ?? 0 }} missing alt</span></div>
                            <div><span class="font-medium text-gray-500">Page Size:</span> <span class="text-gray-700">{{ $p->page_size_bytes ? round($p->page_size_bytes / 1024, 1) . ' KB' : '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Depth:</span> <span class="text-gray-700">{{ $p->depth ?? '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Structured Data:</span> <span class="text-gray-700">{{ !empty($p->structured_data_types) ? implode(', ', $p->structured_data_types) : 'None' }}</span></div>
                            <div><span class="font-medium text-gray-500">Meta Robots:</span> <span class="text-gray-700">{{ $p->meta_robots ?? 'None' }}</span></div>
                            @if(!empty($p->og_tags))
                                <div class="sm:col-span-2"><span class="font-medium text-gray-500">OG Tags:</span> <span class="text-gray-700">{{ collect($p->og_tags)->keys()->map(fn($k) => str_replace('og:', '', $k))->implode(', ') }}</span></div>
                            @endif
                        </div>
                    </td>
                </tr>
                </tbody>
            @empty<tr><td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500">No pages.</td></tr>@endforelse</x-ui.table>
            @if($this->pages instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->pages->hasPages())<div class="mt-4">{{ $this->pages->links() }}</div>@endif
        @endif

        {{-- BROKEN LINKS TAB --}}
        @if($activeTab==='links')
            <div class="mb-4">
                <select wire:model.live="linkTypeFilter" class="rounded-lg border-gray-300 text-sm shadow-sm">
                    <option value="">All Types</option>
                    <option value="internal">Internal</option>
                    <option value="external">External</option>
                </select>
            </div>

            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Broken URL</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th>Type</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Anchor Text</x-ui.th>
                    <x-ui.th>Found On</x-ui.th>
                </x-slot:head>
                @forelse($this->brokenLinks as $lk)
                    <tr class="hover:bg-gray-50">
                        <x-ui.td class="max-w-xs !whitespace-normal text-red-600">{{ Str::limit($lk->target_url, 60) }}</x-ui.td>
                        <x-ui.td><x-ui.badge variant="red">{{ $lk->status_code ?? 'Error' }}</x-ui.badge></x-ui.td>
                        <x-ui.td><x-ui.badge :variant="$lk->type === 'internal' ? 'blue' : 'gray'">{{ ucfirst($lk->type) }}</x-ui.badge></x-ui.td>
                        <x-ui.td class="hidden lg:table-cell max-w-[200px] truncate text-gray-500">{{ $lk->anchor_text ? Str::limit($lk->anchor_text, 40) : '—' }}</x-ui.td>
                        <x-ui.td class="max-w-xs !whitespace-normal">@if($lk->page)<a href="{{ $lk->page->url }}" target="_blank" class="text-accent-600 hover:underline">{{ Str::limit(parse_url($lk->page->url, PHP_URL_PATH) ?: '/', 40) }}</a>@else — @endif</x-ui.td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No broken links found.</td></tr>
                @endforelse
            </x-ui.table>
            @if($this->brokenLinks instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->brokenLinks->hasPages())<div class="mt-4">{{ $this->brokenLinks->links() }}</div>@endif
        @endif

        {{-- BROKEN IMAGES TAB --}}
        @if($activeTab==='images')
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Broken Image URL</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th class="hidden lg:table-cell">Alt Text</x-ui.th>
                    <x-ui.th>Found On</x-ui.th>
                </x-slot:head>
                @forelse($this->brokenImages as $img)
                    <tr class="hover:bg-gray-50">
                        <x-ui.td class="max-w-xs !whitespace-normal text-red-600">{{ Str::limit($img->image_url, 60) }}</x-ui.td>
                        <x-ui.td><x-ui.badge variant="red">{{ $img->status_code ?? 'Error' }}</x-ui.badge></x-ui.td>
                        <x-ui.td class="hidden lg:table-cell max-w-[200px] truncate text-gray-500">{{ $img->has_alt ? Str::limit($img->alt_text, 40) : '—' }}</x-ui.td>
                        <x-ui.td class="max-w-xs !whitespace-normal">
                            @if($img->page)
                                <a href="{{ $img->page->url }}" target="_blank" class="text-accent-600 hover:underline">{{ Str::limit(parse_url($img->page->url, PHP_URL_PATH) ?: '/', 40) }}</a>
                            @else — @endif
                        </x-ui.td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No broken images found.</td></tr>
                @endforelse
            </x-ui.table>
            @if($this->brokenImages instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->brokenImages->hasPages())<div class="mt-4">{{ $this->brokenImages->links() }}</div>@endif
        @endif

        {{-- REDIRECTS TAB --}}
        @if($activeTab==='redirects')
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Source URL</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th>Redirect Target</x-ui.th>
                    <x-ui.th>Chain</x-ui.th>
                </x-slot:head>
                @forelse($this->redirectPages as $rp)
                    <tr class="hover:bg-gray-50">
                        <x-ui.td class="max-w-xs !whitespace-normal">
                            <a href="{{ $rp->url }}" target="_blank" class="text-accent-600 hover:underline">{{ Str::limit(parse_url($rp->url, PHP_URL_PATH) ?: $rp->url, 50) }}</a>
                        </x-ui.td>
                        <x-ui.td><x-ui.badge variant="yellow">{{ $rp->status_code }}</x-ui.badge></x-ui.td>
                        <x-ui.td class="max-w-xs !whitespace-normal text-gray-600">{{ Str::limit($rp->redirect_target, 50) }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.badge :variant="$rp->redirect_chain_length > 2 ? 'red' : ($rp->redirect_chain_length > 1 ? 'yellow' : 'green')">
                                {{ $rp->redirect_chain_length }} hop{{ $rp->redirect_chain_length !== 1 ? 's' : '' }}
                            </x-ui.badge>
                        </x-ui.td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No redirects found.</td></tr>
                @endforelse
            </x-ui.table>
            @if($this->redirectPages instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->redirectPages->hasPages())<div class="mt-4">{{ $this->redirectPages->links() }}</div>@endif

            {{-- Redirect Management (connected sites only) --}}
            @if($site->is_connected && !($site->is_prospect ?? false))
                <div class="mt-6" x-data="{ redirects: [], loading: true, plugin: '', newSource: '', newTarget: '', newType: 301, saving: false }" x-init="
                    fetch('{{ rtrim($site->url, '/') }}/wp-json/simplead/v1/seo/redirects', { headers: { 'X-SAM-API-Key': '{{ $site->api_key ?? '' }}' } })
                        .then(r => r.json())
                        .then(data => { redirects = data.redirects || []; plugin = data.plugin || ''; loading = false; })
                        .catch(() => { loading = false; })
                ">
                    <x-ui.card>
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-medium text-gray-900">Redirect Management</h3>
                            <template x-if="plugin">
                                <x-ui.badge variant="blue"><span x-text="'via ' + plugin"></span></x-ui.badge>
                            </template>
                        </div>

                        {{-- Create redirect form --}}
                        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 min-w-[180px]">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Source Path</label>
                                <input x-model="newSource" type="text" placeholder="/old-page" class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                            </div>
                            <div class="flex-1 min-w-[180px]">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Target URL</label>
                                <input x-model="newTarget" type="text" placeholder="/new-page" class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                            </div>
                            <div class="w-24">
                                <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                                <select x-model="newType" class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                    <option value="301">301</option>
                                    <option value="302">302</option>
                                    <option value="307">307</option>
                                    <option value="410">410</option>
                                </select>
                            </div>
                            <button
                                @click="
                                    if (!newSource || !newTarget) return;
                                    saving = true;
                                    fetch('{{ rtrim($site->url, '/') }}/wp-json/simplead/v1/seo/redirects', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-SAM-API-Key': '{{ $site->api_key ?? '' }}' },
                                        body: JSON.stringify({ source: newSource, target: newTarget, type: parseInt(newType) })
                                    })
                                    .then(r => r.json())
                                    .then(data => {
                                        if (data.redirect_id) { redirects.unshift({ id: data.redirect_id, source: newSource, target: newTarget, type: parseInt(newType) }); newSource = ''; newTarget = ''; }
                                        saving = false;
                                    })
                                    .catch(() => { saving = false; })
                                "
                                :disabled="saving || !newSource || !newTarget"
                                class="rounded-lg bg-accent-600 px-4 py-2 text-sm font-medium text-white hover:bg-accent-700 disabled:opacity-50"
                            >
                                <span x-show="!saving">Add</span>
                                <span x-show="saving">...</span>
                            </button>
                        </div>

                        {{-- Existing redirects table --}}
                        <template x-if="loading">
                            <div class="py-4 text-center text-sm text-gray-400">Loading redirects...</div>
                        </template>
                        <template x-if="!loading && redirects.length === 0">
                            <div class="py-4 text-center text-sm text-gray-400">No managed redirects found.</div>
                        </template>
                        <template x-if="!loading && redirects.length > 0">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead><tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                                        <th class="px-3 py-2">Source</th><th class="px-3 py-2">Target</th><th class="px-3 py-2">Type</th><th class="px-3 py-2 text-right">Action</th>
                                    </tr></thead>
                                    <tbody>
                                        <template x-for="r in redirects" :key="r.id">
                                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                                <td class="px-3 py-2 text-gray-700" x-text="r.source"></td>
                                                <td class="px-3 py-2 text-gray-600" x-text="r.target"></td>
                                                <td class="px-3 py-2"><span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium" x-text="r.type"></span></td>
                                                <td class="px-3 py-2 text-right">
                                                    <button
                                                        @click="
                                                            if (!confirm('Delete this redirect?')) return;
                                                            fetch('{{ rtrim($site->url, '/') }}/wp-json/simplead/v1/seo/redirects/' + r.id, {
                                                                method: 'DELETE',
                                                                headers: { 'X-SAM-API-Key': '{{ $site->api_key ?? '' }}' }
                                                            })
                                                            .then(() => { redirects = redirects.filter(x => x.id !== r.id); })
                                                        "
                                                        class="text-xs text-red-500 hover:text-red-700"
                                                    >Delete</button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </x-ui.card>
                </div>
            @endif
        @endif

        {{-- INFRASTRUCTURE TAB --}}
        @if($activeTab==='infrastructure')
            @php $infra = $this->infrastructureData; $linkStats = $this->internalLinkingStats; @endphp
            @if(!empty($infra))
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {{-- Sitemap --}}
                <x-ui.card>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-medium text-gray-900">Sitemap</h3>
                        @if($infra['sitemap'] && ($infra['sitemap']['found'] ?? false))
                            <x-ui.badge variant="green">Found</x-ui.badge>
                        @else
                            <x-ui.badge variant="red">Not Found</x-ui.badge>
                        @endif
                    </div>
                    @if($infra['sitemap'] && ($infra['sitemap']['found'] ?? false))
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">URL</span><a href="{{ $infra['sitemap']['url'] ?? '' }}" target="_blank" class="text-accent-600 hover:underline truncate ml-4">{{ Str::limit($infra['sitemap']['url'] ?? '—', 40) }}</a></div>
                            <div class="flex justify-between"><span class="text-gray-500">URLs in Sitemap</span><span class="font-medium">{{ $infra['sitemap_urls_count'] ?? $infra['sitemap']['url_count'] ?? '—' }}</span></div>
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No XML sitemap was found. Consider adding one to help search engines discover your pages.</p>
                    @endif
                </x-ui.card>

                {{-- Robots.txt --}}
                <x-ui.card>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-medium text-gray-900">Robots.txt</h3>
                        @if($infra['robots'] && ($infra['robots']['exists'] ?? false))
                            <x-ui.badge variant="green">Found</x-ui.badge>
                        @else
                            <x-ui.badge variant="red">Missing</x-ui.badge>
                        @endif
                    </div>
                    @if($infra['robots'] && ($infra['robots']['exists'] ?? false))
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Allows Crawling</span>
                                @php $blocked = !empty($infra['robots']['disallow_rules']) && in_array('/', $infra['robots']['disallow_rules']); @endphp
                                <x-ui.badge :variant="$blocked ? 'red' : 'green'">{{ $blocked ? 'Blocked' : 'Yes' }}</x-ui.badge>
                            </div>
                            <div class="flex justify-between"><span class="text-gray-500">Sitemap Directive</span><x-ui.badge :variant="!empty($infra['robots']['sitemap_urls']) ? 'green' : 'yellow'">{{ !empty($infra['robots']['sitemap_urls']) ? 'Yes' : 'Missing' }}</x-ui.badge></div>
                            @if(!empty($infra['robots']['disallow_rules']))
                                <div><span class="text-gray-500">Disallow Rules ({{ count($infra['robots']['disallow_rules']) }})</span>
                                    <div class="mt-1 rounded bg-gray-50 p-2 text-xs font-mono text-gray-600 max-h-24 overflow-y-auto">
                                        @foreach(array_slice($infra['robots']['disallow_rules'], 0, 10) as $rule)
                                            <div>Disallow: {{ $rule }}</div>
                                        @endforeach
                                        @if(count($infra['robots']['disallow_rules']) > 10)
                                            <div class="text-gray-400">... and {{ count($infra['robots']['disallow_rules']) - 10 }} more</div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No robots.txt file found. Consider adding one to control search engine crawling.</p>
                    @endif
                </x-ui.card>

                {{-- Security Headers --}}
                <x-ui.card>
                    <h3 class="text-sm font-medium text-gray-900 mb-3">Security Headers</h3>
                    @php
                        $headers = $infra['security_headers'] ?? [];
                        $headerInfo = [
                            'hsts' => [
                                'label' => 'HSTS (Strict-Transport-Security)',
                                'desc' => 'Forces browsers to use HTTPS, preventing protocol downgrade attacks and cookie hijacking.',
                                'fix' => 'Add to your web server (Nginx/Apache) or WordPress .htaccess:',
                                'code' => 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload',
                                'nginx' => 'add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;',
                                'apache' => 'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"',
                            ],
                            'x_frame_options' => [
                                'label' => 'X-Frame-Options',
                                'desc' => 'Prevents your site from being loaded in iframes, protecting against clickjacking attacks.',
                                'fix' => 'Add to your web server config:',
                                'code' => 'X-Frame-Options: SAMEORIGIN',
                                'nginx' => 'add_header X-Frame-Options "SAMEORIGIN" always;',
                                'apache' => 'Header always set X-Frame-Options "SAMEORIGIN"',
                            ],
                            'x_content_type_options' => [
                                'label' => 'X-Content-Type-Options',
                                'desc' => 'Prevents browsers from MIME-sniffing, reducing drive-by download attacks.',
                                'fix' => 'Add to your web server config:',
                                'code' => 'X-Content-Type-Options: nosniff',
                                'nginx' => 'add_header X-Content-Type-Options "nosniff" always;',
                                'apache' => 'Header always set X-Content-Type-Options "nosniff"',
                            ],
                            'csp' => [
                                'label' => 'Content-Security-Policy',
                                'desc' => 'Controls which resources can be loaded, mitigating XSS and data injection attacks.',
                                'fix' => 'Start with a permissive policy and tighten as needed:',
                                'code' => "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;",
                                'nginx' => "add_header Content-Security-Policy \"default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;\" always;",
                                'apache' => "Header always set Content-Security-Policy \"default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;\"",
                            ],
                        ];
                        $missingCount = collect($headerInfo)->filter(fn($h, $k) => empty($headers[$k]))->count();
                    @endphp
                    <div class="space-y-3">
                        @foreach($headerInfo as $key => $info)
                            @php $present = !empty($headers[$key]); @endphp
                            <div class="{{ !$present ? 'rounded-lg border border-red-100 bg-red-50/30 p-3' : '' }}">
                                <div class="flex items-center justify-between text-sm">
                                    <div class="flex items-center gap-2">
                                        @if($present)
                                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-green-100 text-green-600"><svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></span>
                                        @else
                                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-red-100 text-red-500"><svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg></span>
                                        @endif
                                        <span class="font-medium {{ $present ? 'text-gray-700' : 'text-red-700' }}">{{ $info['label'] }}</span>
                                    </div>
                                    <x-ui.badge :variant="$present ? 'green' : 'red'">{{ $present ? 'Present' : 'Missing' }}</x-ui.badge>
                                </div>
                                @if(!$present)
                                    <div class="mt-2 ml-7 space-y-1.5">
                                        <p class="text-xs text-gray-500">{{ $info['desc'] }}</p>
                                        <p class="text-xs font-medium text-gray-600">{{ $info['fix'] }}</p>
                                        <div class="rounded bg-gray-800 px-3 py-2 text-xs font-mono text-green-400 overflow-x-auto">{{ $info['code'] }}</div>
                                        <details class="text-xs">
                                            <summary class="cursor-pointer text-accent-600 hover:text-accent-700 font-medium">Server config examples</summary>
                                            <div class="mt-1 space-y-1">
                                                <p class="text-gray-500 font-medium">Nginx:</p>
                                                <div class="rounded bg-gray-800 px-2 py-1 text-xs font-mono text-green-400 overflow-x-auto">{{ $info['nginx'] }}</div>
                                                <p class="text-gray-500 font-medium">Apache / .htaccess:</p>
                                                <div class="rounded bg-gray-800 px-2 py-1 text-xs font-mono text-green-400 overflow-x-auto">{{ $info['apache'] }}</div>
                                            </div>
                                        </details>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if($missingCount > 0)
                        <div class="mt-3 rounded-md bg-amber-50 p-2.5 text-xs text-amber-700">
                            <span class="font-medium">{{ $missingCount }} missing header{{ $missingCount > 1 ? 's' : '' }}.</span>
                            You can also add these via a WordPress plugin like <strong>HTTP Headers</strong> or <strong>Really Simple SSL Pro</strong>.
                        </div>
                    @endif
                </x-ui.card>

                {{-- SSL Certificate --}}
                <x-ui.card>
                    <h3 class="text-sm font-medium text-gray-900 mb-3">SSL Certificate</h3>
                    @php $ssl = $infra['ssl'] ?? []; @endphp
                    @if(!empty($ssl))
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Status</span><x-ui.badge :variant="($ssl['valid'] ?? false) ? 'green' : 'red'">{{ ($ssl['valid'] ?? false) ? 'Valid' : 'Invalid / Expired' }}</x-ui.badge></div>
                            @if($ssl['issuer'] ?? null)<div class="flex justify-between"><span class="text-gray-500">Issuer</span><span class="font-medium text-gray-700">{{ $ssl['issuer'] }}</span></div>@endif
                            @if($ssl['expiry'] ?? null)<div class="flex justify-between"><span class="text-gray-500">Expires</span><span class="font-medium text-gray-700">{{ $ssl['expiry'] }}</span></div>@endif
                            @if(isset($ssl['days_until_expiry']))<div class="flex justify-between"><span class="text-gray-500">Days Remaining</span><span class="font-medium {{ $ssl['days_until_expiry'] < 30 ? 'text-red-600' : 'text-green-600' }}">{{ $ssl['days_until_expiry'] }}</span></div>@endif
                        </div>
                    @else
                        <p class="text-sm text-gray-400">SSL information not available.</p>
                    @endif
                </x-ui.card>

                {{-- SEO Plugin --}}
                <x-ui.card>
                    <h3 class="text-sm font-medium text-gray-900 mb-3">SEO Plugin</h3>
                    @if($infra['seo_plugin'])
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Plugin</span><span class="font-medium text-gray-700">{{ $infra['seo_plugin'] }}</span></div>
                            @if($infra['seo_plugin_version'])<div class="flex justify-between"><span class="text-gray-500">Version</span><span class="font-medium text-gray-700">{{ $infra['seo_plugin_version'] }}</span></div>@endif
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No SEO plugin detected.</p>
                    @endif
                </x-ui.card>

                {{-- Internal Linking --}}
                <x-ui.card>
                    <h3 class="text-sm font-medium text-gray-900 mb-3">Internal Linking</h3>
                    @if(!empty($linkStats))
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Avg Internal Links / Page</span><span class="font-medium text-gray-700">{{ $linkStats['avg_internal_links'] }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Orphan Pages</span><span class="font-medium {{ $linkStats['orphan_count'] > 0 ? 'text-orange-600' : 'text-green-600' }}">{{ $linkStats['orphan_count'] }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Deep Pages (depth > 3)</span><span class="font-medium {{ $linkStats['deep_pages_count'] > 0 ? 'text-yellow-600' : 'text-green-600' }}">{{ $linkStats['deep_pages_count'] }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Total Pages</span><span class="font-medium text-gray-700">{{ $linkStats['total_pages'] }}</span></div>
                        </div>
                    @endif
                </x-ui.card>

                {{-- Search Visibility --}}
                @if($infra['search_visibility'])
                    <x-ui.card>
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Search Visibility</h3>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Visible to Search Engines</span>
                            <x-ui.badge :variant="($infra['search_visibility']['visible'] ?? true) ? 'green' : 'red'">
                                {{ ($infra['search_visibility']['visible'] ?? true) ? 'Yes' : 'Discouraged' }}
                            </x-ui.badge>
                        </div>
                        @if(!($infra['search_visibility']['visible'] ?? true) && $site->is_connected && !($site->is_prospect ?? false))
                            <button wire:click="toggleSearchVisibility" class="mt-3 text-xs font-medium text-accent-600 hover:text-accent-800">Enable Search Visibility</button>
                        @endif
                    </x-ui.card>
                @endif

                {{-- Redirect Info --}}
                @if(!empty($infra['redirect']) && !empty($infra['redirect']['chain']))
                    <x-ui.card>
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Homepage Redirects</h3>
                        <div class="space-y-1 text-sm">
                            @foreach($infra['redirect']['chain'] as $step)
                                <div class="flex items-center gap-2">
                                    <x-ui.badge :variant="($step['status'] ?? 200) >= 300 ? 'yellow' : 'green'">{{ $step['status'] ?? '—' }}</x-ui.badge>
                                    <span class="truncate text-gray-600">{{ Str::limit($step['url'] ?? '', 60) }}</span>
                                </div>
                            @endforeach
                            @if($infra['redirect']['has_mixed_ssl'] ?? false)
                                <p class="mt-2 text-xs text-red-500">Mixed HTTP/HTTPS detected in redirect chain.</p>
                            @endif
                        </div>
                    </x-ui.card>
                @endif
            </div>
            @else
                <x-ui.card><x-ui.empty-state title="No infrastructure data" description="Run an audit to collect infrastructure information." icon="server" /></x-ui.card>
            @endif
        @endif

        {{-- HISTORY TAB --}}
        @if($activeTab==='history')
            <div class="space-y-4">
                @if(!empty($this->trendData))
                    <x-ui.card>
                        <h3 class="mb-3 text-sm font-medium text-gray-900">Score Trend (Overall + Categories)</h3>
                        <x-charts.line-chart
                            :labels="$this->trendData['labels']"
                            :datasets="[
                                ['label' => 'Overall', 'data' => $this->trendData['overall'], 'color' => '#8D5CF5'],
                                ['label' => 'Technical', 'data' => $this->trendData['technical'], 'color' => '#3b82f6'],
                                ['label' => 'On-Page', 'data' => $this->trendData['on_page'], 'color' => '#10b981'],
                                ['label' => 'Performance', 'data' => $this->trendData['performance'], 'color' => '#f59e0b'],
                            ]"
                            height="250px"
                        />
                    </x-ui.card>
                @endif
                @foreach($this->auditHistory as $h)
                    <x-ui.card><div class="flex items-center gap-4">
                        <div class="text-center">
                            <span class="text-2xl font-bold {{ $h->score>=80?'text-green-600':($h->score>=50?'text-yellow-600':'text-red-600') }}">{{ $h->score }}</span>
                            @if(!$loop->last)
                                @php $prev=$this->auditHistory[$loop->index+1]->score; $delta=$h->score-$prev; @endphp
                                <p class="text-xs {{ $delta>0?'text-green-600':($delta<0?'text-red-600':'text-gray-400') }}">{{ $delta>0?'+':'' }}{{ $delta }}</p>
                            @else
                                <p class="text-xs text-gray-400">Score</p>
                            @endif
                        </div>
                        <div class="flex-1"><p class="text-sm font-medium text-gray-900">{{ $h->scanned_at?->format('M d, Y H:i') }}</p><div class="mt-1 flex flex-wrap gap-2">@if($h->critical_count>0)<x-ui.badge variant="red">{{ $h->critical_count }} critical</x-ui.badge>@endif @if($h->high_count>0)<x-ui.badge variant="orange">{{ $h->high_count }} high</x-ui.badge>@endif<span class="text-xs text-gray-400">{{ $h->pages_crawled }} pages</span></div></div>
                        <button wire:click="deleteAudit({{ $h->id }})" wire:confirm="Delete this audit?" class="text-gray-300 hover:text-red-500"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                    </div></x-ui.card>
                @endforeach
            </div>
        @endif
    @endif

    {{-- SETTINGS MODAL --}}
    <x-ui.modal name="seo-settings" maxWidth="md">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Audit Settings</h3>
        <div class="space-y-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3">
                <div>
                    <p class="text-sm font-medium text-gray-700">Enable automatic audits</p>
                    <p class="text-xs text-gray-400">
                        @if($this->monitor?->is_active && $this->monitor?->next_audit_at)
                            Next scheduled: {{ $this->monitor->next_audit_at->format('M d, Y H:i') }}
                        @else
                            Manual only — audits run when you click "Run Audit"
                        @endif
                    </p>
                </div>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" wire:model="settingsAutoAudit" class="peer sr-only">
                    <div class="peer h-6 w-11 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-accent-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-accent-300"></div>
                </label>
            </div>
            @if($settingsAutoAudit)
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Audit Interval</label><select wire:model="settingsInterval" class="w-full rounded-lg border-gray-300 text-sm shadow-sm"><option value="10080">Weekly</option><option value="20160">Biweekly</option><option value="43200">Monthly</option></select></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Preferred Time</label><input wire:model="settingsPreferredTime" type="time" class="w-full rounded-lg border-gray-300 text-sm shadow-sm"><p class="mt-1 text-xs text-gray-400">Audit will run at this time (server timezone)</p></div>
            @endif
            <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3">
                <div>
                    <p class="text-sm font-medium text-gray-700">Daily broken resource check</p>
                    <p class="text-xs text-gray-400">Re-check broken links and images daily without a full re-crawl</p>
                </div>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" wire:model="settingsCrawlEnabled" class="peer sr-only">
                    <div class="peer h-6 w-11 rounded-full bg-gray-200 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-accent-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-accent-300"></div>
                </label>
            </div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Max Pages</label><input wire:model="settingsMaxPages" type="number" min="10" max="1000" class="w-full rounded-lg border-gray-300 text-sm shadow-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Sitemap URL (optional)</label><input wire:model="settingsSitemapUrl" type="url" placeholder="https://example.com/sitemap.xml" class="w-full rounded-lg border-gray-300 text-sm shadow-sm"></div>
            <div class="flex justify-end gap-3 pt-2"><x-ui.button variant="secondary" @click="$dispatch('close-modal-seo-settings')">Cancel</x-ui.button><x-ui.button wire:click="updateSettings">Save</x-ui.button></div>
        </div>
    </x-ui.modal>

    {{-- FIX META MODAL --}}
    <x-ui.modal name="seo-fix" maxWidth="md">
        <h3 class="text-lg font-medium text-gray-900 mb-2">Edit SEO Meta</h3>
        <p class="text-xs text-gray-400 mb-4 truncate">{{ $fixUrl }}</p>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Title</label>
                <input wire:model="fixTitle" type="text" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" maxlength="200">
                <p class="mt-1 text-xs {{ strlen($fixTitle) >= 30 && strlen($fixTitle) <= 60 ? 'text-green-600' : 'text-red-500' }}">{{ strlen($fixTitle) }} characters (recommended: 30-60)</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Description</label>
                <textarea wire:model="fixDescription" rows="3" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" maxlength="300"></textarea>
                <p class="mt-1 text-xs {{ strlen($fixDescription) >= 70 && strlen($fixDescription) <= 160 ? 'text-green-600' : 'text-red-500' }}">{{ strlen($fixDescription) }} characters (recommended: 70-160)</p>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <x-ui.button variant="secondary" @click="$dispatch('close-modal-seo-fix')">Cancel</x-ui.button>
                <x-ui.button wire:click="pushMetaFix">
                    <x-ui.spinner size="sm" wire:loading wire:target="pushMetaFix" />
                    <span wire:loading.remove wire:target="pushMetaFix">Apply to Site</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- FIX ROBOTS MODAL --}}
    <x-ui.modal name="seo-fix-robots" maxWidth="md">
        <h3 class="text-lg font-medium text-gray-900 mb-2">Fix Indexing</h3>
        <p class="text-xs text-gray-400 mb-4 truncate">{{ $fixRobotsUrl }}</p>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Action</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-3 rounded-lg border border-gray-200 p-3 cursor-pointer hover:bg-gray-50">
                        <input type="radio" wire:model="fixRobotsAction" value="index" class="text-accent-600">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Allow Indexing</p>
                            <p class="text-xs text-gray-500">Remove noindex — allow search engines to index this page</p>
                        </div>
                    </label>
                    <label class="flex items-center gap-3 rounded-lg border border-gray-200 p-3 cursor-pointer hover:bg-gray-50">
                        <input type="radio" wire:model="fixRobotsAction" value="noindex" class="text-accent-600">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Block Indexing</p>
                            <p class="text-xs text-gray-500">Set noindex — prevent search engines from indexing this page</p>
                        </div>
                    </label>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <x-ui.button variant="secondary" @click="$dispatch('close-modal-seo-fix-robots')">Cancel</x-ui.button>
                <x-ui.button wire:click="pushRobotsFix">
                    <x-ui.spinner size="sm" wire:loading wire:target="pushRobotsFix" />
                    <span wire:loading.remove wire:target="pushRobotsFix">Apply to Site</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- FIX CANONICAL MODAL --}}
    <x-ui.modal name="seo-fix-canonical" maxWidth="md">
        <h3 class="text-lg font-medium text-gray-900 mb-2">Fix Canonical URL</h3>
        <p class="text-xs text-gray-400 mb-4 truncate">{{ $fixCanonicalUrl }}</p>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Canonical URL</label>
                <input wire:model="fixCanonicalTarget" type="url" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" placeholder="https://example.com/page">
                <p class="mt-1 text-xs text-gray-500">The canonical URL tells search engines which version of this page to index.</p>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <x-ui.button variant="secondary" @click="$dispatch('close-modal-seo-fix-canonical')">Cancel</x-ui.button>
                <x-ui.button wire:click="pushCanonicalFix">
                    <x-ui.spinner size="sm" wire:loading wire:target="pushCanonicalFix" />
                    <span wire:loading.remove wire:target="pushCanonicalFix">Apply to Site</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- FIX OG MODAL --}}
    <x-ui.modal name="seo-fix-og" maxWidth="md">
        <h3 class="text-lg font-medium text-gray-900 mb-2">Fix Open Graph Tags</h3>
        <p class="text-xs text-gray-400 mb-4 truncate">{{ $fixOgUrl }}</p>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">OG Title</label>
                <input wire:model="fixOgTitle" type="text" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" maxlength="200">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">OG Description</label>
                <textarea wire:model="fixOgDescription" rows="2" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" maxlength="300"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">OG Image URL</label>
                <input wire:model="fixOgImage" type="url" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" placeholder="https://example.com/image.jpg">
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <x-ui.button variant="secondary" @click="$dispatch('close-modal-seo-fix-og')">Cancel</x-ui.button>
                <x-ui.button wire:click="pushOgFix">
                    <x-ui.spinner size="sm" wire:loading wire:target="pushOgFix" />
                    <span wire:loading.remove wire:target="pushOgFix">Apply to Site</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
