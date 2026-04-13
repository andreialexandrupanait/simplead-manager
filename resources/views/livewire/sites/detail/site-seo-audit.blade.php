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

        {{-- Clickable severity badges --}}
        <div class="mb-6 flex flex-wrap items-center gap-3">
            @foreach([['Critical',$audit->critical_count,'red','critical'],['High',$audit->high_count,'orange','high'],['Medium',$audit->medium_count,'yellow','medium'],['Low',$audit->low_count,'blue','low'],['Info',$audit->info_count,'gray','info']] as [$l,$c,$v,$filter])
                <button wire:click="$set('severityFilter','{{ $severityFilter === $filter ? '' : $filter }}');$set('activeTab','issues')" class="transition-opacity {{ $severityFilter !== '' && $severityFilter !== $filter ? 'opacity-40' : '' }}">
                    <x-ui.badge :variant="$v">{{ $c }} {{ $l }}</x-ui.badge>
                </button>
            @endforeach
            <span class="text-sm text-gray-500">{{ $audit->pages_crawled }} pages &middot; {{ $audit->scan_duration ? gmdate('i:s', $audit->scan_duration) : '—' }}</span>
        </div>

        {{-- Tabs --}}
        <div class="mb-4 flex gap-1 rounded-lg bg-gray-100 p-1">
            @foreach(['issues'=>'Issues ('.$this->groupedIssues->count().')','pages'=>'Pages','links'=>'Broken Links ('.$this->brokenLinksCount.')','history'=>'History'] as $tab=>$label)
                <button wire:click="$set('activeTab','{{ $tab }}')" class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $activeTab===$tab ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">{{ $label }}</button>
            @endforeach
        </div>

        {{-- ISSUES TAB --}}
        @if($activeTab==='issues')
            <div class="space-y-3">
                <div class="flex gap-3">
                    <select wire:model.live="severityFilter" class="rounded-lg border-gray-300 text-sm shadow-sm"><option value="">All Severities</option>@foreach($this->severityOptions as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach</select>
                    <select wire:model.live="categoryFilter" class="rounded-lg border-gray-300 text-sm shadow-sm"><option value="">All Categories</option>@foreach($this->categoryOptions as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach</select>
                </div>
                @forelse($this->groupedIssues as $group)
                    <x-ui.card x-data="{ showUrls: false }">
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ ['critical'=>'bg-red-500','high'=>'bg-orange-500','medium'=>'bg-yellow-500','low'=>'bg-blue-500','info'=>'bg-gray-400'][$group->severity->value] ?? 'bg-gray-400' }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-sm font-medium text-gray-900">{{ $group->title }}</h4>
                                    <x-ui.badge variant="gray">{{ $group->category->label() }}</x-ui.badge>
                                    @if($group->affected_count > 1)
                                        <x-ui.badge variant="blue">{{ $group->affected_count }} pages</x-ui.badge>
                                    @endif
                                </div>
                                @if($group->recommendation)
                                    <p class="mt-1.5 text-sm text-gray-500">{{ $group->recommendation }}</p>
                                @endif
                                @if($group->urls->isNotEmpty())
                                    <div class="mt-2">
                                        <button @click="showUrls = !showUrls" class="inline-flex items-center gap-1 text-xs font-medium text-accent-600 hover:text-accent-700">
                                            <svg class="h-3 w-3 transition-transform" :class="showUrls && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                            <span x-text="showUrls ? 'Hide URLs' : 'Show {{ $group->urls->count() }} affected URL{{ $group->urls->count() > 1 ? 's' : '' }}'"></span>
                                        </button>
                                        @php $isFixable = $site->is_connected && !($site->is_prospect ?? false) && in_array($group->title, ['Missing title tag', 'Title too short', 'Title too long', 'Missing meta description', 'Meta description too short', 'Meta description too long']); @endphp
                                        <div x-show="showUrls" x-collapse class="mt-2 space-y-1 border-l-2 border-gray-100 pl-3">
                                            @foreach($group->urls->take(20) as $url)
                                                <div class="flex items-center gap-2">
                                                    <a href="{{ $url }}" target="_blank" class="block truncate text-xs text-gray-400 hover:text-accent-600 flex-1">{{ Str::limit($url, 70) }}</a>
                                                    @if($isFixable)
                                                        <button wire:click="openFixModal('{{ $url }}')" class="shrink-0 text-xs font-medium text-accent-600 hover:text-accent-800">Fix</button>
                                                    @endif
                                                </div>
                                            @endforeach
                                            @if($group->urls->count() > 20)
                                                <p class="text-xs text-gray-300">... and {{ $group->urls->count() - 20 }} more</p>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </x-ui.card>
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
                </x-slot:head>
            @forelse($this->pages as $p)
                <tbody x-data="{ expanded: false }">
                <tr class="hover:bg-gray-50 cursor-pointer" @click="expanded = !expanded">
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
                </tr>
                <tr x-show="expanded" x-collapse>
                    <td colspan="8" class="bg-gray-50 px-6 py-3">
                        <div class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2 lg:grid-cols-3">
                            <div><span class="font-medium text-gray-500">Title:</span> <span class="text-gray-700">{{ $p->title ?? '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Meta Description:</span> <span class="text-gray-700">{{ Str::limit($p->meta_description ?? '—', 100) }}</span></div>
                            <div><span class="font-medium text-gray-500">Canonical:</span> <span class="text-gray-700">{{ $p->canonical_url ? ($p->is_self_canonical ? 'Self' : Str::limit($p->canonical_url, 50)) : 'Missing' }}</span></div>
                            <div><span class="font-medium text-gray-500">H1 Tags:</span> <span class="text-gray-700">{{ !empty($p->h1_tags) ? implode(', ', array_map(fn($h) => Str::limit($h, 40), $p->h1_tags)) : '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Images:</span> <span class="text-gray-700">{{ $p->image_count ?? 0 }} total, {{ $p->images_without_alt ?? 0 }} missing alt</span></div>
                            <div><span class="font-medium text-gray-500">Page Size:</span> <span class="text-gray-700">{{ $p->page_size_bytes ? round($p->page_size_bytes / 1024, 1) . ' KB' : '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Depth:</span> <span class="text-gray-700">{{ $p->depth ?? '—' }}</span></div>
                            <div><span class="font-medium text-gray-500">Structured Data:</span> <span class="text-gray-700">{{ !empty($p->structured_data_types) ? implode(', ', $p->structured_data_types) : 'None' }}</span></div>
                            <div><span class="font-medium text-gray-500">In Sitemap:</span> <x-ui.badge :variant="$p->in_sitemap ? 'green' : 'gray'">{{ $p->in_sitemap ? 'Yes' : 'No' }}</x-ui.badge></div>
                            @if(!empty($p->og_tags))
                                <div class="sm:col-span-2"><span class="font-medium text-gray-500">OG Tags:</span> <span class="text-gray-700">{{ collect($p->og_tags)->keys()->map(fn($k) => str_replace('og:', '', $k))->implode(', ') }}</span></div>
                            @endif
                        </div>
                    </td>
                </tr>
                </tbody>
            @empty<tr><td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500">No pages.</td></tr>@endforelse</x-ui.table>
            @if($this->pages instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->pages->hasPages())<div class="mt-4">{{ $this->pages->links() }}</div>@endif
        @endif

        {{-- BROKEN LINKS TAB --}}
        @if($activeTab==='links')
            <x-ui.table><x-slot:head><x-ui.th>Broken URL</x-ui.th><x-ui.th>Status</x-ui.th><x-ui.th>Type</x-ui.th><x-ui.th>Found On</x-ui.th></x-slot:head>
            @forelse($this->brokenLinks as $lk)
                <tr class="hover:bg-gray-50">
                    <x-ui.td class="max-w-xs !whitespace-normal text-red-600">{{ Str::limit($lk->target_url,60) }}</x-ui.td>
                    <x-ui.td><x-ui.badge variant="red">{{ $lk->status_code??'Error' }}</x-ui.badge></x-ui.td>
                    <x-ui.td>{{ ucfirst($lk->type) }}</x-ui.td>
                    <x-ui.td class="max-w-xs !whitespace-normal">@if($lk->page)<a href="{{ $lk->page->url }}" target="_blank" class="text-accent-600 hover:underline">{{ Str::limit(parse_url($lk->page->url,PHP_URL_PATH)?:'/',40) }}</a>@else — @endif</x-ui.td>
                </tr>
            @empty<tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No broken links found.</td></tr>@endforelse</x-ui.table>
            @if($this->brokenLinks instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->brokenLinks->hasPages())<div class="mt-4">{{ $this->brokenLinks->links() }}</div>@endif
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
</div>
