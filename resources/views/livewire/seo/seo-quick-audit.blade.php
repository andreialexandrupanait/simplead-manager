<div class="min-w-0" @if($isRunning) wire:poll.2s="checkProgress" @endif>
    <x-ui.page-header title="Quick SEO Audit" subtitle="Audit any website — no setup required">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('seo.index') }}"><x-icons.arrow-left class="h-4 w-4" /> SEO Overview</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- URL Input --}}
    <x-ui.card class="mb-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label class="mb-1 block text-sm font-medium text-gray-700">Website URL</label>
                <input wire:model="url" type="url" placeholder="https://example.com" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" @if($isRunning) disabled @endif>
                @error('url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-ui.button wire:click="runQuickAudit" wire:loading.attr="disabled" :disabled="$isRunning" class="shrink-0">
                <x-ui.spinner size="sm" wire:loading wire:target="runQuickAudit" />
                <span wire:loading.remove wire:target="runQuickAudit"><x-icons.search class="h-4 w-4" /></span>
                {{ $isRunning ? 'Auditing...' : 'Run Audit' }}
            </x-ui.button>
        </div>
    </x-ui.card>

    {{-- Progress --}}
    @if($isRunning && $this->currentAudit)
        @php $progress = app(\App\Services\SeoAudit\SiteAuditService::class)->getAuditProgress($this->currentAudit); @endphp
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

    {{-- Results --}}
    @if($this->completedAudit && !$isRunning)
        @php $audit = $this->completedAudit; @endphp

        <div class="mb-4 flex justify-end">
            <x-ui.button variant="secondary" wire:click="exportXls"><x-icons.file-text class="h-4 w-4" /> Export XLS</x-ui.button>
        </div>

        {{-- Score --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
            <x-ui.card class="text-center lg:col-span-1">
                @php $score=$audit->score; $sc=$score>=80?'text-green-600':($score>=50?'text-yellow-600':'text-red-600'); $rc=$score>=80?'stroke-green-500':($score>=50?'stroke-yellow-500':'stroke-red-500'); @endphp
                <div class="relative mx-auto h-28 w-28">
                    <svg class="h-28 w-28 -rotate-90" viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" fill="none" class="stroke-gray-200" stroke-width="8"/><circle cx="50" cy="50" r="42" fill="none" class="{{ $rc }}" stroke-width="8" stroke-linecap="round" stroke-dasharray="{{ $score*2.64 }} 264"/></svg>
                    <div class="absolute inset-0 flex items-center justify-center"><span class="text-3xl font-bold {{ $sc }}">{{ $score }}</span></div>
                </div>
                <p class="mt-2 text-sm font-medium text-gray-500">Overall Score</p>
            </x-ui.card>
            @foreach(['technical'=>'Technical SEO','on_page'=>'On-Page','performance'=>'Performance','other'=>'Other'] as $key=>$label)
                @php $cs=$this->categoryScores[$key]??0; $cc=$cs>=80?'text-green-600':($cs>=50?'text-yellow-600':'text-red-600'); $bc=$cs>=80?'bg-green-500':($cs>=50?'bg-yellow-500':'bg-red-500'); @endphp
                <x-ui.card>
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold {{ $cc }}">{{ $cs }}</p>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200"><div class="h-full rounded-full {{ $bc }}" style="width:{{ $cs }}%"></div></div>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Severity summary --}}
        <div class="mb-6 flex flex-wrap items-center gap-3">
            @foreach([['Critical',$audit->critical_count,'red'],['High',$audit->high_count,'orange'],['Medium',$audit->medium_count,'yellow'],['Low',$audit->low_count,'blue'],['Info',$audit->info_count,'gray']] as [$l,$c,$v])
                <x-ui.badge :variant="$v">{{ $c }} {{ $l }}</x-ui.badge>
            @endforeach
            <span class="text-sm text-gray-500">{{ $audit->pages_crawled }} pages crawled</span>
        </div>

        {{-- Grouped Issues --}}
        <h3 class="mb-3 text-sm font-semibold text-gray-900">Issues Found</h3>
        <div class="space-y-3">
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
                                        <span x-text="showUrls ? 'Hide URLs' : 'Show {{ $group->urls->count() }} URL{{ $group->urls->count() > 1 ? 's' : '' }}'"></span>
                                    </button>
                                    <div x-show="showUrls" x-collapse class="mt-2 space-y-1 border-l-2 border-gray-100 pl-3">
                                        @foreach($group->urls->take(20) as $url)
                                            <a href="{{ $url }}" target="_blank" class="block truncate text-xs text-gray-400 hover:text-accent-600">{{ Str::limit($url, 80) }}</a>
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
                <x-ui.card><x-ui.empty-state title="No issues found" description="This site looks great!" icon="check-circle" /></x-ui.card>
            @endforelse
        </div>
    @endif

    {{-- Past Quick Audits --}}
    @if($this->pastAudits->isNotEmpty())
        <h3 class="mb-3 mt-8 text-sm font-semibold text-gray-900">Past Quick Audits</h3>
        <div class="space-y-2">
            @foreach($this->pastAudits as $ps)
                @php $pa = $ps->latestSeoAudit; $psc = $pa?->score; @endphp
                <x-ui.card>
                    <div class="flex items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $ps->name }}</p>
                            <p class="text-xs text-gray-400 truncate">{{ $ps->url }}</p>
                        </div>
                        <div class="w-12 text-center">
                            <span class="text-lg font-bold {{ $psc!==null?($psc>=80?'text-green-600':($psc>=50?'text-yellow-600':'text-red-600')):'text-gray-400' }}">{{ $psc ?? '—' }}</span>
                        </div>
                        <p class="hidden text-xs text-gray-400 sm:block">{{ $pa?->scanned_at?->diffForHumans() ?? 'Running' }}</p>
                        <div class="flex gap-2">
                            <x-ui.button variant="ghost" size="xs" wire:click="viewAudit({{ $ps->id }})">View</x-ui.button>
                            <button wire:click="deleteProspect({{ $ps->id }})" wire:confirm="Delete this prospect audit and all its data?" class="text-gray-300 hover:text-red-500">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
