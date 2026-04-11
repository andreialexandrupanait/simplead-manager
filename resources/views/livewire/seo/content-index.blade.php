<div>
    <x-ui.page-header title="{{ __('Content AI') }}" subtitle="{{ __('AI-generated SEO articles') }}">
        <a href="{{ route('seo.content.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition">
            <x-dynamic-component component="icons.plus" class="h-4 w-4" />
            {{ __('New Article') }}
        </a>
    </x-ui.page-header>

    <x-ui.flash-alert type="success" key="success" />

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['' => __('All'), 'draft' => __('Draft'), 'review' => __('Review'), 'scheduled' => __('Scheduled'), 'published' => __('Published'), 'failed' => __('Failed')]"
            :selected="$statusFilter"
            wire="statusFilter"
        />
        <select wire:model.live="siteFilter" class="rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
            <option value="">{{ __('All Sites') }}</option>
            @foreach($this->sites as $site)
                <option value="{{ $site->id }}">{{ $site->name }}</option>
            @endforeach
        </select>
        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" class="ml-auto w-64" />
    </div>

    <x-ui.card class="!p-0 overflow-hidden">
        @if($this->contents->isEmpty())
            <x-ui.empty-state
                title="{{ __('No articles yet') }}"
                description="{{ __('Create your first AI-generated article.') }}"
                icon="file-text"
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-medium text-gray-500">{{ __('Title') }}</th>
                            <th class="px-4 py-2.5 text-left font-medium text-gray-500">{{ __('Keyword') }}</th>
                            <th class="px-4 py-2.5 text-left font-medium text-gray-500">{{ __('Site') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Status') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('SEO') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Ranking') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Words') }}</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">{{ __('Date') }}</th>
                            <th class="px-4 py-2.5 text-right font-medium text-gray-500">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($this->contents as $item)
                            <tr class="hover:bg-gray-50">
                                <td class="max-w-xs truncate px-4 py-2.5 font-medium text-gray-900">
                                    <a href="{{ route('seo.content.edit', $item) }}" wire:navigate class="hover:text-purple-600">{{ $item->title }}</a>
                                </td>
                                <td class="px-4 py-2.5 text-sm text-gray-600">{{ $item->target_keyword ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-sm text-gray-500">{{ $item->site?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="inline-flex rounded-full bg-{{ $item->status_color }}-100 px-2 py-0.5 text-xs font-semibold text-{{ $item->status_color }}-800">
                                        {{ $item->status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-center text-sm text-gray-600">{{ $item->seo_score ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    @if($item->ranking_position)
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-bold {{ $item->ranking_position <= 3 ? 'bg-green-100 text-green-700' : ($item->ranking_position <= 10 ? 'bg-blue-100 text-blue-700' : ($item->ranking_position <= 20 ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600')) }}">#{{ round($item->ranking_position) }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-center text-sm text-gray-600">{{ $item->word_count ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-center text-xs text-gray-500">{{ $item->created_at->format('M d') }}</td>
                                <td class="px-4 py-2.5 text-right space-x-2">
                                    <a href="{{ route('seo.content.edit', $item) }}" wire:navigate class="text-xs font-medium text-purple-600 hover:text-purple-800">{{ __('Edit') }}</a>
                                    <button wire:click="deleteContent({{ $item->id }})" wire:confirm="{{ __('Delete this article?') }}" class="text-xs font-medium text-red-600 hover:text-red-800">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $this->contents->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
