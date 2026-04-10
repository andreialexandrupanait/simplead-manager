<div>
    <x-ui.page-header title="{{ __('SEO Dashboard') }}" subtitle="{{ __('Global SEO overview across all sites') }}" />

    {{-- KPI Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <x-ui.stat-card
            label="{{ __('Sites Monitored') }}"
            :value="$this->kpis['monitored']"
        />
        <x-ui.stat-card
            label="{{ __('Active Crawls') }}"
            :value="$this->kpis['active_crawls']"
        />
        <x-ui.stat-card
            label="{{ __('Critical Issues') }}"
            :value="$this->kpis['critical_issues']"
        />
        <x-ui.stat-card
            label="{{ __('Articles (Month)') }}"
            :value="$this->kpis['articles_this_month']"
        />
        <x-ui.stat-card
            label="{{ __('Keywords Tracked') }}"
            :value="$this->kpis['keywords_tracked']"
        />
        <x-ui.stat-card
            label="{{ __('Avg SEO Score') }}"
            :value="$this->kpis['avg_score'] ?? '—'"
        />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Sites Table (2/3) --}}
        <div class="lg:col-span-2">
            <x-ui.card class="!p-0 overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Sites SEO Status') }}</h3>
                    <div class="flex items-center gap-3">
                        <x-ui.filter-tabs
                            :options="['' => __('All'), 'critical' => __('Critical'), 'warning' => __('Warning'), 'good' => __('Good')]"
                            :selected="$scoreFilter"
                            wire="scoreFilter"
                        />
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search...') }}"
                            class="w-48"
                        />
                    </div>
                </div>

                @if($this->sites->isEmpty())
                    <x-ui.empty-state
                        title="{{ __('No sites found') }}"
                        description="{{ __('Add sites and enable SEO monitoring to see them here.') }}"
                        icon="target"
                    />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2.5 text-left font-medium text-gray-500">{{ __('Site') }}</th>
                                    <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Score') }}</th>
                                    <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Critical') }}</th>
                                    <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Keywords') }}</th>
                                    <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Last Crawl') }}</th>
                                    <th class="px-4 py-2.5 text-right font-medium text-gray-500">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach($this->sites as $site)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('sites.seo', $site) }}" class="font-medium text-gray-900 hover:text-purple-600">
                                                {{ $site->name }}
                                            </a>
                                            <div class="text-xs text-gray-500">{{ $site->url }}</div>
                                        </td>
                                        <td class="px-4 py-2.5 text-center">
                                            @if($site->latestSeoAudit)
                                                <span @class([
                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
                                                    'bg-green-100 text-green-800' => $site->latestSeoAudit->score >= 80,
                                                    'bg-yellow-100 text-yellow-800' => $site->latestSeoAudit->score >= 50 && $site->latestSeoAudit->score < 80,
                                                    'bg-red-100 text-red-800' => $site->latestSeoAudit->score < 50,
                                                ])>
                                                    {{ $site->latestSeoAudit->score }}
                                                </span>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-center">
                                            @if($site->critical_issues_count > 0)
                                                <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">
                                                    {{ $site->critical_issues_count }}
                                                </span>
                                            @else
                                                <span class="text-gray-400">0</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-center text-gray-600">
                                            {{ $site->tracked_keywords_count }}
                                        </td>
                                        <td class="px-4 py-2.5 text-center text-xs text-gray-500">
                                            {{ $site->latestSiteCrawl?->created_at?->diffForHumans() ?? '—' }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right">
                                            <a href="{{ route('sites.seo', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">
                                                {{ __('Details') }}
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-gray-200 px-4 py-3">
                        {{ $this->sites->links() }}
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- Activity Feed (1/3) --}}
        <div>
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('Recent Activity') }}</h3>
                <div class="space-y-3">
                    @forelse($this->activityFeed as $item)
                        <div class="flex items-start gap-3 text-sm">
                            <div @class([
                                'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full',
                                'bg-blue-100 text-blue-600' => $item['type'] === 'crawl' && $item['status'] === 'completed',
                                'bg-red-100 text-red-600' => $item['type'] === 'crawl' && $item['status'] === 'failed',
                                'bg-green-100 text-green-600' => $item['type'] === 'audit',
                            ])>
                                @if($item['type'] === 'crawl')
                                    <x-dynamic-component component="icons.globe" class="h-3.5 w-3.5" />
                                @else
                                    <x-dynamic-component component="icons.target" class="h-3.5 w-3.5" />
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-900">
                                    @if($item['site_slug'])
                                        <a href="{{ route('sites.seo', $item['site_slug']) }}" class="hover:text-purple-600">{{ $item['site'] }}</a>
                                    @else
                                        {{ $item['site'] }}
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500">{{ $item['detail'] }}</p>
                                <p class="text-xs text-gray-400">{{ $item['date']?->diffForHumans() }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('No recent activity.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
