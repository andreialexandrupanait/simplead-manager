<div>
    <x-ui.page-header title="{{ __('SEO Crawler') }}" subtitle="{{ __('All crawl sessions across sites') }}">
        <a href="{{ route('seo.crawler.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition">
            <x-dynamic-component component="icons.plus" class="h-4 w-4" />
            {{ __('New Crawl') }}
        </a>
    </x-ui.page-header>

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['' => __('All'), 'running' => __('Running'), 'completed' => __('Completed'), 'failed' => __('Failed')]"
            :selected="$statusFilter"
            wire="statusFilter"
        />
        <select wire:model.live="siteFilter" class="rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
            <option value="">{{ __('All Sites') }}</option>
            @foreach($this->sites as $site)
                <option value="{{ $site->id }}">{{ $site->name }}</option>
            @endforeach
        </select>
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search sites...') }}"
            class="ml-auto w-64"
        />
    </div>

    <x-ui.card class="!p-0 overflow-hidden">
        @if($this->crawls->isEmpty())
            <x-ui.empty-state
                title="{{ __('No crawls yet') }}"
                description="{{ __('Start a new crawl to analyze your sites.') }}"
                icon="globe"
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-medium text-gray-500">{{ __('Site') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Status') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Pages') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Issues') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Duration') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Date') }}</th>
                            <th class="px-4 py-2.5 text-right font-medium text-gray-500">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($this->crawls as $crawl)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 font-medium text-gray-900">
                                    {{ $crawl->site?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    <span @class([
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-yellow-100 text-yellow-800' => $crawl->status === 'pending',
                                        'bg-blue-100 text-blue-800' => $crawl->status === 'running',
                                        'bg-green-100 text-green-800' => $crawl->status === 'completed',
                                        'bg-red-100 text-red-800' => $crawl->status === 'failed',
                                        'bg-gray-100 text-gray-800' => $crawl->status === 'cancelled',
                                    ])>
                                        {{ ucfirst($crawl->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-center text-gray-600">
                                    {{ $crawl->pages_crawled ?? 0 }}
                                </td>
                                <td class="px-4 py-2.5 text-center text-gray-600">
                                    {{ $crawl->pages_with_issues ?? 0 }}
                                </td>
                                <td class="px-4 py-2.5 text-center text-xs text-gray-500">
                                    @if($crawl->duration_seconds)
                                        {{ gmdate('H:i:s', $crawl->duration_seconds) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-center text-xs text-gray-500">
                                    {{ $crawl->created_at->format('M d, H:i') }}
                                </td>
                                <td class="px-4 py-2.5 text-right space-x-2">
                                    <a href="{{ route('seo.crawler.show', $crawl) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">{{ __('View') }}</a>
                                    @if(!$crawl->isRunning())
                                        <button wire:click="deleteCrawl({{ $crawl->id }})" wire:confirm="{{ __('Delete this crawl?') }}" class="text-xs font-medium text-red-600 hover:text-red-800">{{ __('Delete') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $this->crawls->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
