<div class="min-w-0" @if($isRunning) wire:poll.2s="checkProgress" @endif>
    <x-ui.page-header title="SEO Audit" subtitle="Crawl and analyze your site for SEO issues">
        <x-slot:actions>
            <x-ui.button variant="secondary" @click="$dispatch('open-modal-seo-settings')"><x-icons.settings class="h-4 w-4" /> Settings</x-ui.button>
            <x-ui.button wire:click="runAudit" wire:loading.attr="disabled" :disabled="$isRunning">
                <span wire:loading.remove wire:target="runAudit"><x-icons.search class="h-4 w-4" /></span>
                <x-ui.spinner size="sm" wire:loading wire:target="runAudit" />
                {{ $isRunning ? 'Running...' : 'Run Audit' }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($isRunning && $this->latestAudit)
        @php $progress = app(\App\Services\SeoAudit\SiteAuditService::class)->getAuditProgress($this->latestAudit); @endphp
        <x-ui.card class="mb-6 !bg-blue-50 !ring-blue-200">
            <div class="flex items-center gap-3">
                <x-ui.spinner size="md" class="text-blue-600" />
                <div class="flex-1">
                    <p class="text-sm font-medium text-blue-900">{{ $progress['status_label'] }}</p>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-blue-200"><div class="h-full rounded-full bg-blue-600 transition-all duration-500" style="width: {{ $progress['progress_percent'] }}%"></div></div>
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

        <div class="mb-6 flex flex-wrap items-center gap-3">
            @foreach([['Critical',$audit->critical_count,'red'],['High',$audit->high_count,'orange'],['Medium',$audit->medium_count,'yellow'],['Low',$audit->low_count,'blue'],['Info',$audit->info_count,'gray']] as [$l,$c,$v])
                <x-ui.badge :variant="$v">{{ $c }} {{ $l }}</x-ui.badge>
            @endforeach
            <span class="text-sm text-gray-500">{{ $audit->pages_crawled }} pages &middot; {{ $audit->scan_duration ? gmdate('i:s', $audit->scan_duration) : '—' }}</span>
        </div>

        <div class="mb-4 flex gap-1 rounded-lg bg-gray-100 p-1">
            @foreach(['issues'=>'Issues ('.$audit->totalIssues().')','pages'=>'Pages','links'=>'Broken Links','history'=>'History'] as $tab=>$label)
                <button wire:click="$set('activeTab','{{ $tab }}')" class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $activeTab===$tab ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if($activeTab==='issues')
            <div class="space-y-3">
                <div class="flex gap-3">
                    <select wire:model.live="severityFilter" class="rounded-lg border-gray-300 text-sm shadow-sm"><option value="">All Severities</option>@foreach($this->severityOptions as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach</select>
                    <select wire:model.live="categoryFilter" class="rounded-lg border-gray-300 text-sm shadow-sm"><option value="">All Categories</option>@foreach($this->categoryOptions as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach</select>
                </div>
                @forelse($this->issues as $issue)
                    <x-ui.card x-data="{ open: false }">
                        <div class="flex cursor-pointer items-start gap-3" @click="open=!open">
                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ ['critical'=>'bg-red-500','high'=>'bg-orange-500','medium'=>'bg-yellow-500','low'=>'bg-blue-500','info'=>'bg-gray-400'][$issue->severity->value] ?? 'bg-gray-400' }}"></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2"><h4 class="text-sm font-medium text-gray-900">{{ $issue->title }}</h4><x-ui.badge variant="gray">{{ $issue->category->label() }}</x-ui.badge></div>
                                <p class="mt-1 text-sm text-gray-500">{{ $issue->description }}</p>
                                @if($issue->url)<p class="mt-1 truncate text-xs text-gray-400">{{ $issue->url }}</p>@endif
                            </div>
                            <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" :class="open&&'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                        <div x-show="open" x-collapse class="mt-3 border-t border-gray-100 pt-3">@if($issue->recommendation)<p class="text-sm text-gray-600"><span class="font-medium text-gray-900">Recommendation:</span> {{ $issue->recommendation }}</p>@endif</div>
                    </x-ui.card>
                @empty
                    <x-ui.card><x-ui.empty-state title="No issues found" description="Great job — or adjust your filters." icon="check-circle" /></x-ui.card>
                @endforelse
                @if($this->issues instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->issues->hasPages())<div class="mt-4">{{ $this->issues->links() }}</div>@endif
            </div>
        @endif

        @if($activeTab==='pages')
            <div class="mb-4"><input wire:model.live.debounce.300ms="pageSearch" type="text" placeholder="Search pages..." class="rounded-lg border-gray-300 text-sm shadow-sm sm:w-80"></div>
            <x-ui.table><x-slot:head><x-ui.th>URL</x-ui.th><x-ui.th>Status</x-ui.th><x-ui.th>Title</x-ui.th><x-ui.th>Words</x-ui.th><x-ui.th>Links</x-ui.th><x-ui.th>Indexable</x-ui.th></x-slot:head>
            @forelse($this->pages as $p)
                <tr class="hover:bg-gray-50">
                    <x-ui.td class="max-w-xs !whitespace-normal"><a href="{{ $p->url }}" target="_blank" class="text-accent-600 hover:underline">{{ Str::limit(parse_url($p->url,PHP_URL_PATH)?:'/',50) }}</a></x-ui.td>
                    <x-ui.td><x-ui.badge :variant="$p->status_code===200?'green':($p->status_code>=400?'red':'yellow')">{{ $p->status_code??'—' }}</x-ui.badge></x-ui.td>
                    <x-ui.td>@if($p->title_length)<span class="{{ $p->title_length<30||$p->title_length>60?'text-yellow-600':'' }}">{{ $p->title_length }}ch</span>@else<span class="text-red-600">Missing</span>@endif</x-ui.td>
                    <x-ui.td>{{ $p->word_count??'—' }}</x-ui.td>
                    <x-ui.td>{{ $p->internal_link_count }}i/{{ $p->external_link_count }}e</x-ui.td>
                    <x-ui.td><x-ui.badge :variant="$p->is_indexable?'green':'red'">{{ $p->is_indexable?'Yes':'No' }}</x-ui.badge></x-ui.td>
                </tr>
            @empty<tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No pages.</td></tr>@endforelse</x-ui.table>
            @if($this->pages instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->pages->hasPages())<div class="mt-4">{{ $this->pages->links() }}</div>@endif
        @endif

        @if($activeTab==='links')
            <x-ui.table><x-slot:head><x-ui.th>Broken URL</x-ui.th><x-ui.th>Status</x-ui.th><x-ui.th>Type</x-ui.th><x-ui.th>Found On</x-ui.th></x-slot:head>
            @forelse($this->brokenLinks as $lk)
                <tr class="hover:bg-gray-50">
                    <x-ui.td class="max-w-xs !whitespace-normal text-red-600">{{ Str::limit($lk->target_url,60) }}</x-ui.td>
                    <x-ui.td><x-ui.badge variant="red">{{ $lk->status_code??'Error' }}</x-ui.badge></x-ui.td>
                    <x-ui.td>{{ ucfirst($lk->type) }}</x-ui.td>
                    <x-ui.td class="max-w-xs !whitespace-normal">{{ $lk->page?Str::limit(parse_url($lk->page->url,PHP_URL_PATH)?:'/',40):'—' }}</x-ui.td>
                </tr>
            @empty<tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No broken links.</td></tr>@endforelse</x-ui.table>
        @endif

        @if($activeTab==='history')
            <div class="space-y-4">
                @if($this->auditHistory->count()>1)
                    <x-ui.card><h3 class="mb-3 text-sm font-medium text-gray-900">Score Trend</h3>
                    <x-charts.line-chart :labels="$this->auditHistory->reverse()->pluck('scanned_at')->map(fn($d)=>$d?->format('M d'))->values()->toArray()" :datasets="[['label'=>'SEO Score','data'=>$this->auditHistory->reverse()->pluck('score')->values()->toArray(),'color'=>'#8D5CF5']]" height="200px" /></x-ui.card>
                @endif
                @foreach($this->auditHistory as $h)
                    <x-ui.card><div class="flex items-center gap-4">
                        <div class="text-center"><span class="text-2xl font-bold {{ $h->score>=80?'text-green-600':($h->score>=50?'text-yellow-600':'text-red-600') }}">{{ $h->score }}</span><p class="text-xs text-gray-400">Score</p></div>
                        <div class="flex-1"><p class="text-sm font-medium text-gray-900">{{ $h->scanned_at?->format('M d, Y H:i') }}</p><div class="mt-1 flex flex-wrap gap-2">@if($h->critical_count>0)<x-ui.badge variant="red">{{ $h->critical_count }} critical</x-ui.badge>@endif @if($h->high_count>0)<x-ui.badge variant="orange">{{ $h->high_count }} high</x-ui.badge>@endif<span class="text-xs text-gray-400">{{ $h->pages_crawled }} pages</span></div></div>
                        <button wire:click="deleteAudit({{ $h->id }})" wire:confirm="Delete this audit?" class="text-gray-300 hover:text-red-500"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                    </div></x-ui.card>
                @endforeach
            </div>
        @endif
    @endif

    <x-ui.modal name="seo-settings" maxWidth="md">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Audit Settings</h3>
        <div class="space-y-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Audit Interval</label><select wire:model="settingsInterval" class="w-full rounded-lg border-gray-300 text-sm shadow-sm"><option value="10080">Weekly</option><option value="20160">Biweekly</option><option value="43200">Monthly</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Max Pages</label><input wire:model="settingsMaxPages" type="number" min="10" max="1000" class="w-full rounded-lg border-gray-300 text-sm shadow-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Sitemap URL (optional)</label><input wire:model="settingsSitemapUrl" type="url" placeholder="https://example.com/sitemap.xml" class="w-full rounded-lg border-gray-300 text-sm shadow-sm"></div>
            <div class="flex justify-end gap-3 pt-2"><x-ui.button variant="secondary" @click="$dispatch('close-modal-seo-settings')">Cancel</x-ui.button><x-ui.button wire:click="updateSettings">Save</x-ui.button></div>
        </div>
    </x-ui.modal>
</div>
