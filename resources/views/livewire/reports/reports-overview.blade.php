<div>
    <x-ui.page-header title="Reports" subtitle="All reports across sites">
        <x-slot:actions>
            <button wire:click="generateAllReports"
                    wire:confirm="{{ __('Queue report generation for all connected sites using the default template?') }}"
                    wire:loading.attr="disabled"
                    wire:target="generateAllReports"
                    class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-accent-500 transition disabled:opacity-60 dark:bg-accent-700 dark:hover:bg-accent-600">
                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span wire:loading.remove wire:target="generateAllReports">{{ __('Generate All') }}</span>
                <span wire:loading wire:target="generateAllReports">{{ __('Queuing...') }}</span>
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <div class="flex-1">
            <x-ui.input type="text" wire:model.live.debounce.300ms="search" placeholder="Search reports..." />
        </div>
        <select wire:model.live="siteFilter" class="rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            <option value="">All Sites</option>
            @foreach($sites as $site)
                <option value="{{ $site->id }}">{{ $site->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            <option value="all">All Statuses</option>
            <option value="completed">Completed</option>
            <option value="generating">Generating</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
        </select>
    </div>

    {{-- Reports table --}}
    @if($reports->isEmpty())
        <x-ui.card>
            <p class="py-8 text-center text-sm text-gray-500">No reports found.</p>
        </x-ui.card>
    @else
        <x-ui.card class="overflow-hidden !p-0">
            {{-- Mobile cards --}}
            <div class="md:hidden divide-y divide-gray-200">
                @foreach($reports as $report)
                    @php
                        $statusVariant = match($report->status) {
                            \App\Enums\ReportStatus::Completed => 'green',
                            \App\Enums\ReportStatus::Generating => 'blue',
                            \App\Enums\ReportStatus::Pending => 'yellow',
                            default => 'red',
                        };
                    @endphp
                    <div class="px-5 py-3 space-y-2">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <a href="{{ route('sites.reports', $report->site) }}" class="text-sm font-medium text-accent-600 hover:text-accent-800">{{ $report->site?->name ?? '—' }}</a>
                                @if($report->site?->client)
                                    <p class="text-xs text-gray-400">{{ $report->site->client->name }}</p>
                                @endif
                            </div>
                            <x-ui.badge :variant="$statusVariant">{{ $report->status->label() }}</x-ui.badge>
                        </div>
                        <div class="text-sm text-gray-900">{{ $report->created_at->format('M d, Y H:i') }}</div>
                        <div class="flex items-center justify-between text-xs text-gray-500">
                            <span>{{ $report->period_start->format('M d') }} — {{ $report->period_end->format('M d, Y') }}</span>
                            <span>{{ $report->file_size ? $report->file_size_formatted : '—' }}</span>
                        </div>
                        <div class="text-xs text-gray-500">{{ $report->reportTemplate?->name ?? '—' }}</div>
                        <div class="flex items-center gap-2">
                            @if($report->status === \App\Enums\ReportStatus::Completed && $report->file_path)
                                <a href="{{ route('reports.download', ['report' => $report, 'preview' => 1]) }}"
                                   target="_blank"
                                   class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition"
                                   title="Preview">
                                    <x-icons.search class="h-3.5 w-3.5" />
                                </a>
                                @if($report->site?->client?->portal_enabled && $report->data_snapshot)
                                    <a href="{{ route('client-portal.report', [$report->site->client->portal_token, $report]) }}"
                                       target="_blank"
                                       class="inline-flex items-center rounded-lg border border-accent-300 px-2.5 py-1.5 text-xs font-medium text-accent-700 hover:bg-accent-50 transition"
                                       title="View online">
                                        <x-icons.globe class="h-3.5 w-3.5" />
                                    </a>
                                @endif
                                <a href="{{ route('reports.download', $report) }}"
                                   class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition"
                                   title="Download">
                                    <x-icons.hard-drive class="h-3.5 w-3.5" />
                                </a>
                            @endif
                            <button wire:click="deleteReport({{ $report->id }})"
                                    wire:confirm="Are you sure you want to delete this report?"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50"
                                    title="Delete">
                                <x-icons.x class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Site</th>
                            <x-ui.sortable-th column="created_at" :sortBy="$sortBy" :sortDir="$sortDir">Date</x-ui.sortable-th>
                            <x-ui.sortable-th column="period_start" :sortBy="$sortBy" :sortDir="$sortDir">Period</x-ui.sortable-th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Template</th>
                            <x-ui.sortable-th column="status" :sortBy="$sortBy" :sortDir="$sortDir">Status</x-ui.sortable-th>
                            <x-ui.sortable-th column="file_size" :sortBy="$sortBy" :sortDir="$sortDir">Size</x-ui.sortable-th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($reports as $report)
                            <tr class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-5 py-3 text-sm">
                                    <a href="{{ route('sites.reports', $report->site) }}" class="font-medium text-accent-600 hover:text-accent-800">{{ $report->site?->name ?? '—' }}</a>
                                    @if($report->site?->client)
                                        <p class="text-xs text-gray-400">{{ $report->site->client->name }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-900">
                                    {{ $report->created_at->format('M d, Y H:i') }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-500">
                                    {{ $report->period_start->format('M d') }} — {{ $report->period_end->format('M d, Y') }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-500">
                                    {{ $report->reportTemplate?->name ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm">
                                    @php
                                        $statusVariant = match($report->status) {
                                            \App\Enums\ReportStatus::Completed => 'green',
                                            \App\Enums\ReportStatus::Generating => 'blue',
                                            \App\Enums\ReportStatus::Pending => 'yellow',
                                            default => 'red',
                                        };
                                    @endphp
                                    <x-ui.badge :variant="$statusVariant">{{ $report->status->label() }}</x-ui.badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-500">
                                    {{ $report->file_size ? $report->file_size_formatted : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($report->status === \App\Enums\ReportStatus::Completed && $report->file_path)
                                            <a href="{{ route('reports.download', ['report' => $report, 'preview' => 1]) }}"
                                               target="_blank"
                                               class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition"
                                               title="Preview">
                                                <x-icons.search class="h-3.5 w-3.5" />
                                            </a>
                                            @if($report->site?->client?->portal_enabled && $report->data_snapshot)
                                                <a href="{{ route('client-portal.report', [$report->site->client->portal_token, $report]) }}"
                                                   target="_blank"
                                                   class="inline-flex items-center rounded-lg border border-accent-300 px-2.5 py-1.5 text-xs font-medium text-accent-700 hover:bg-accent-50 transition"
                                                   title="View online">
                                                    <x-icons.globe class="h-3.5 w-3.5" />
                                                </a>
                                            @endif
                                            <a href="{{ route('reports.download', $report) }}"
                                               class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition"
                                               title="Download">
                                                <x-icons.hard-drive class="h-3.5 w-3.5" />
                                            </a>
                                        @endif
                                        <button wire:click="deleteReport({{ $report->id }})"
                                                wire:confirm="Are you sure you want to delete this report?"
                                                wire:loading.attr="disabled"
                                                class="inline-flex items-center rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50"
                                                title="Delete">
                                            <x-icons.x class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($reports->hasPages())
                <div class="border-t border-gray-200 px-5 py-3">
                    {{ $reports->links() }}
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
