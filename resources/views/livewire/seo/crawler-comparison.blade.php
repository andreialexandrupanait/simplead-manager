<div>
    <x-ui.page-header title="{{ __('Crawl Comparison') }}" subtitle="{{ $siteCrawl->site?->name ?? '' }}">
        <a href="{{ route('seo.crawler.show', $siteCrawl) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
            {{ __('Back to Results') }}
        </a>
    </x-ui.page-header>

    @php $cmp = $this->comparison; @endphp

    {{-- Overview --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-ui.stat-card label="{{ __('New Pages') }}" :value="$cmp['new_pages_count']" />
        <x-ui.stat-card label="{{ __('Disappeared Pages') }}" :value="$cmp['disappeared_pages_count']" />
        <x-ui.stat-card label="{{ __('New Issues') }}" :value="$cmp['new_issues']" />
        <x-ui.stat-card label="{{ __('Resolved Issues') }}" :value="$cmp['resolved_issues']" />
    </div>

    {{-- Metrics comparison --}}
    <x-ui.card class="mb-6">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Metrics') }}</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="py-2 text-left font-medium text-gray-500">{{ __('Metric') }}</th>
                        <th class="py-2 text-center font-medium text-gray-500">{{ __('Previous') }}</th>
                        <th class="py-2 text-center font-medium text-gray-500">{{ __('Current') }}</th>
                        <th class="py-2 text-center font-medium text-gray-500">{{ __('Change') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cmp['metrics'] as $metric => $vals)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-700">{{ str_replace('_', ' ', ucfirst($metric)) }}</td>
                            <td class="py-2 text-center text-gray-600">{{ $vals['old'] ?? '—' }}</td>
                            <td class="py-2 text-center text-gray-600">{{ $vals['new'] ?? '—' }}</td>
                            <td class="py-2 text-center">
                                @if(is_numeric($vals['old'] ?? null) && is_numeric($vals['new'] ?? null))
                                    @php $diff = ($vals['new'] ?? 0) - ($vals['old'] ?? 0); @endphp
                                    <span @class(['text-green-600' => $diff <= 0, 'text-red-600' => $diff > 0])>
                                        {{ $diff > 0 ? '+' : '' }}{{ $diff }}
                                    </span>
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

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- New Pages --}}
        <x-ui.card>
            <h3 class="mb-3 text-sm font-semibold text-green-700">{{ __('New Pages') }} ({{ $cmp['new_pages_count'] }})</h3>
            @if(empty($cmp['new_pages']))
                <p class="text-sm text-gray-400">{{ __('No new pages.') }}</p>
            @else
                <ul class="max-h-64 space-y-1 overflow-y-auto">
                    @foreach($cmp['new_pages'] as $url)
                        <li class="truncate text-xs text-gray-600">{{ $url }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- Disappeared Pages --}}
        <x-ui.card>
            <h3 class="mb-3 text-sm font-semibold text-red-700">{{ __('Disappeared Pages') }} ({{ $cmp['disappeared_pages_count'] }})</h3>
            @if(empty($cmp['disappeared_pages']))
                <p class="text-sm text-gray-400">{{ __('No disappeared pages.') }}</p>
            @else
                <ul class="max-h-64 space-y-1 overflow-y-auto">
                    @foreach($cmp['disappeared_pages'] as $url)
                        <li class="truncate text-xs text-gray-600">{{ $url }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    {{-- Status Changes --}}
    @if(!empty($cmp['status_changes']))
        <x-ui.card class="mt-6">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Status Code Changes') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="py-2 text-left font-medium text-gray-500">URL</th>
                            <th class="py-2 text-center font-medium text-gray-500">{{ __('Previous') }}</th>
                            <th class="py-2 text-center font-medium text-gray-500">{{ __('Current') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($cmp['status_changes'] as $change)
                            <tr class="border-b border-gray-100">
                                <td class="max-w-xs truncate py-2 text-xs text-gray-700">{{ $change['url'] }}</td>
                                <td class="py-2 text-center"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs">{{ $change['old_status'] }}</span></td>
                                <td class="py-2 text-center"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs">{{ $change['new_status'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
