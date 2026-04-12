<div class="min-w-0">
    <x-ui.page-header title="SEO Overview" subtitle="SEO audit scores across all your sites" />

    <div class="mb-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <x-ui.stat-card label="Total Sites" :value="$this->stats['total_sites']" icon="globe" color="purple" />
        <x-ui.stat-card label="Audited" :value="$this->stats['audited_sites']" icon="check-circle" color="blue" />
        <x-ui.stat-card label="Avg Score" :value="$this->stats['avg_score']" icon="zap" :color="$this->stats['avg_score'] >= 80 ? 'green' : ($this->stats['avg_score'] >= 50 ? 'yellow' : 'red')" />
        <x-ui.stat-card label="Needs Attention" :value="$this->stats['needs_attention']" icon="alert-triangle" :color="$this->stats['needs_attention'] > 0 ? 'orange' : 'green'" />
        <x-ui.stat-card label="Critical Issues" :value="$this->stats['total_critical']" icon="shield-alert" :color="$this->stats['total_critical'] > 0 ? 'red' : 'green'" />
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="Search sites..." class="w-full sm:w-64" />
        <select wire:model.live="scoreFilter" class="rounded-lg border-gray-300 text-sm shadow-sm">
            <option value="">All Scores</option>
            <option value="good">Good (80+)</option>
            <option value="needs_work">Needs Work (50-79)</option>
            <option value="poor">Poor (&lt;50)</option>
            <option value="no_audit">No Audit</option>
        </select>
        <select wire:model.live="sort" class="rounded-lg border-gray-300 text-sm shadow-sm">
            <option value="score_asc">Score: Low to High</option>
            <option value="score_desc">Score: High to Low</option>
            <option value="issues">Most Critical</option>
            <option value="name">Name</option>
        </select>
    </div>

    <div class="space-y-3">
        @forelse($this->sites as $site)
            @php
                $audit = $site->latestSeoAudit;
                $score = $audit?->score;
                $scoreColor = $score === null ? 'text-gray-400' : ($score >= 80 ? 'text-green-600' : ($score >= 50 ? 'text-yellow-600' : 'text-red-600'));
                $barColor = $score === null ? 'bg-gray-200' : ($score >= 80 ? 'bg-green-500' : ($score >= 50 ? 'bg-yellow-500' : 'bg-red-500'));
                $isRunning = $site->running_audits_count > 0;
            @endphp
            <x-ui.card>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <x-site-favicon :site="$site" size="sm" />
                        <div class="min-w-0">
                            <a href="{{ route('sites.seo', $site) }}" class="text-sm font-medium text-gray-900 hover:text-accent-600 truncate block">{{ $site->name }}</a>
                            <p class="text-xs text-gray-400 truncate">{{ parse_url($site->url, PHP_URL_HOST) }}</p>
                        </div>
                    </div>
                    <div class="w-16 text-center">
                        <span class="text-2xl font-bold {{ $scoreColor }}">{{ $score ?? '—' }}</span>
                    </div>
                    <div class="hidden w-32 lg:block">
                        <div class="h-2 w-full rounded-full bg-gray-200">
                            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $score ?? 0 }}%"></div>
                        </div>
                    </div>
                    <div class="hidden w-36 lg:flex gap-1.5">
                        @if($audit)
                            @if($audit->critical_count > 0)<x-ui.badge variant="red">{{ $audit->critical_count }}C</x-ui.badge>@endif
                            @if($audit->high_count > 0)<x-ui.badge variant="orange">{{ $audit->high_count }}H</x-ui.badge>@endif
                            @if($audit->medium_count > 0)<x-ui.badge variant="yellow">{{ $audit->medium_count }}M</x-ui.badge>@endif
                        @endif
                    </div>
                    <div class="hidden w-28 text-right lg:block">
                        <p class="text-xs text-gray-400">{{ $audit?->scanned_at?->diffForHumans() ?? 'Never' }}</p>
                    </div>
                    <div class="w-24 text-right">
                        @if($isRunning)
                            <span class="inline-flex items-center gap-1 text-xs text-blue-600"><x-ui.spinner size="xs" /> Running</span>
                        @else
                            <x-ui.button variant="ghost" size="xs" wire:click="runAudit({{ $site->id }})">Run Audit</x-ui.button>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty-state title="No sites found" description="No sites match your filters." icon="search" />
            </x-ui.card>
        @endforelse
    </div>
</div>
