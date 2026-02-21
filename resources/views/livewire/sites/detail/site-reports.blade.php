<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    {{-- Header actions --}}
    <div class="mb-6 flex justify-end">
        <div class="flex gap-3">
            <x-ui.button variant="secondary" wire:click="openScheduleModal">
                <x-icons.settings class="h-4 w-4" />
                Schedule
            </x-ui.button>
            <x-ui.button wire:click="openGenerateModal">
                Generate Report
            </x-ui.button>
        </div>
    </div>

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="generate" :jobs="$trackedJobs" title="Generating report..." />

    {{-- Flash messages --}}
    @if(session()->has('report-success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">
            {{ session('report-success') }}
        </div>
    @endif
    @if(session()->has('report-error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">
            {{ session('report-error') }}
        </div>
    @endif

    {{-- Schedule Status Card --}}
    @if($schedule)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg {{ $schedule->is_active ? 'bg-green-100' : 'bg-gray-100' }}">
                        <x-icons.refresh-cw class="h-5 w-5 {{ $schedule->is_active ? 'text-green-600' : 'text-gray-400' }}" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-gray-900">Scheduled Reports</span>
                            <x-ui.badge :variant="$schedule->is_active ? 'green' : 'gray'">{{ $schedule->is_active ? 'Active' : 'Paused' }}</x-ui.badge>
                        </div>
                        <p class="text-sm text-gray-500">
                            {{ ucfirst($schedule->frequency) }}
                            @if($schedule->frequency === 'weekly')
                                on {{ \Carbon\Carbon::getDays()[$schedule->day_of_week ?? 0] }}s
                            @else
                                on day {{ $schedule->day_of_month ?? 1 }}
                            @endif
                            at {{ $schedule->time ?? '08:00' }}
                            &middot; Template: {{ $schedule->reportTemplate?->name ?? 'Unknown' }}
                            @if($schedule->next_run_at)
                                &middot; Next: {{ $schedule->next_run_at->format('M d, Y H:i') }}
                            @endif
                        </p>
                    </div>
                </div>
                <button wire:click="openScheduleModal" class="text-sm text-purple-600 hover:text-purple-700 font-medium">
                    Configure
                </button>
            </div>
        </div>
    @else
        <div class="mb-6 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-6 text-center">
            <x-icons.refresh-cw class="mx-auto h-8 w-8 text-gray-400" />
            <p class="mt-2 text-sm font-medium text-gray-900">No scheduled reports</p>
            <p class="mt-1 text-sm text-gray-500">Set up automatic report generation and delivery</p>
            <button wire:click="openScheduleModal" class="mt-3 text-sm font-medium text-purple-600 hover:text-purple-700">
                Set Up Schedule
            </button>
        </div>
    @endif

    {{-- Report History --}}
    @if($reports->count() > 0)
    <div x-data="{
        selected: [],
        showBulkSend: false,
        bulkEmail: '',
        allIds: @js($reports->pluck('id')->values()->toArray()),
        get allSelected() { return this.selected.length === this.allIds.length && this.selected.length > 0 },
        toggleAll() { this.selected = this.allSelected ? [] : [...this.allIds] },
        resetSelection() { this.selected = []; this.showBulkSend = false; this.bulkEmail = ''; },
    }" class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h2 class="font-medium text-gray-900">Report History</h2>
        </div>

        {{-- Bulk action bar --}}
        <div x-show="selected.length > 0" x-cloak class="flex flex-wrap items-center gap-3 border-b border-gray-200 bg-purple-50 px-5 py-3">
            <span class="text-sm font-medium text-purple-700" x-text="selected.length + ' selected'"></span>

            <button @click="if (confirm('Delete ' + selected.length + ' report(s)?')) { $wire.bulkDelete(selected).then(() => resetSelection()) }"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition">
                <x-icons.x class="h-3.5 w-3.5" />
                Delete
            </button>

            <div class="relative" x-show="!showBulkSend">
                <button @click="showBulkSend = true"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                    <x-icons.inbox class="h-3.5 w-3.5" />
                    Send
                </button>
            </div>

            <div x-show="showBulkSend" x-cloak class="flex items-center gap-2">
                <input type="email" x-model="bulkEmail" placeholder="recipient@example.com"
                       class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs focus:border-purple-500 focus:ring-purple-500 w-56" />
                <button @click="if (bulkEmail) { $wire.bulkSend(selected, bulkEmail).then(() => resetSelection()) }"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-purple-700 transition">
                    Send
                </button>
                <button @click="showBulkSend = false; bulkEmail = ''"
                        class="text-gray-400 hover:text-gray-600">
                    <x-icons.x class="h-4 w-4" />
                </button>
            </div>

            <a :href="'{{ route('reports.bulk-download', $site) }}?ids=' + selected.join(',')"
               class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                <x-icons.hard-drive class="h-3.5 w-3.5" />
                Download ZIP
            </a>

            <button @click="resetSelection()" class="ml-auto text-xs text-gray-500 hover:text-gray-700">
                Clear selection
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="w-10 px-5 py-3">
                            <input type="checkbox" :checked="allSelected" @change="toggleAll()"
                                   class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                        </th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Date</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Period</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Template</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Size</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($reports as $report)
                        <tr class="hover:bg-gray-50" :class="selected.includes({{ $report->id }}) && 'bg-purple-50/50'">
                            <td class="px-5 py-3">
                                <input type="checkbox" value="{{ $report->id }}" x-model.number="selected"
                                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
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
                                        'completed' => 'green',
                                        'generating' => 'blue',
                                        'pending' => 'yellow',
                                        default => 'red',
                                    };
                                @endphp
                                <x-ui.badge :variant="$statusVariant">{{ ucfirst($report->status) }}</x-ui.badge>
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-500">
                                {{ $report->file_size ? $report->file_size_formatted : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if($report->status === 'completed' && $report->file_path)
                                        <a href="{{ route('reports.download', ['report' => $report, 'preview' => 1]) }}"
                                           target="_blank"
                                           class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition"
                                           title="Preview">
                                            <x-icons.search class="h-3.5 w-3.5" />
                                        </a>
                                        <a href="{{ route('reports.download', $report) }}"
                                           class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition"
                                           title="Download">
                                            <x-icons.hard-drive class="h-3.5 w-3.5" />
                                        </a>
                                        <button wire:click="openSendModal({{ $report->id }})"
                                                class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition"
                                                title="Send via email">
                                            <x-icons.inbox class="h-3.5 w-3.5" />
                                        </button>
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
    </div>
    @endif

    {{-- Generate Report Modal --}}
    @if($showGenerateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="$set('showGenerateModal', false)"></div>
            <div class="relative w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-xl bg-white shadow-xl">
                {{-- Header --}}
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4 rounded-t-xl">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Generate Report</h3>
                        <p class="text-sm text-gray-500">
                            @if($generateStep === 1)
                                Step 1 of 2 — Review section data
                            @else
                                Step 2 of 2 — Review recommendations
                            @endif
                        </p>
                    </div>
                    <button wire:click="$set('showGenerateModal', false)" class="text-gray-400 hover:text-gray-600">
                        <x-icons.x class="h-5 w-5" />
                    </button>
                </div>

                {{-- Step indicator --}}
                <div class="px-6 pt-4 pb-2">
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5">
                            <div class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold {{ $generateStep === 1 ? 'bg-purple-600 text-white' : 'bg-purple-100 text-purple-600' }}">1</div>
                            <span class="text-sm {{ $generateStep === 1 ? 'font-medium text-gray-900' : 'text-gray-500' }}">Data Preview</span>
                        </div>
                        <div class="h-px flex-1 bg-gray-200"></div>
                        <div class="flex items-center gap-1.5">
                            <div class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold {{ $generateStep === 2 ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-500' }}">2</div>
                            <span class="text-sm {{ $generateStep === 2 ? 'font-medium text-gray-900' : 'text-gray-500' }}">Recommendations</span>
                        </div>
                    </div>
                </div>

                {{-- Body --}}
                <div class="px-6 py-4">
                    @if($generateStep === 1)
                        {{-- ═══ STEP 1: Data Preview ═══ --}}
                        @if(count($sectionPreviews) > 0)
                            <div class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                                @foreach($sectionPreviews as $sectionKey => $preview)
                                    @php
                                        $isExcluded = in_array($sectionKey, $excludedSections);
                                        $status = $preview['status'];
                                        $dotColor = match($status) {
                                            'bad' => 'bg-red-500',
                                            'warning' => 'bg-amber-500',
                                            'good' => 'bg-green-500',
                                            'no-data' => 'bg-gray-300',
                                            default => 'bg-blue-400',
                                        };
                                    @endphp
                                    <div class="flex items-center gap-3 px-3 py-2.5 transition {{ $isExcluded ? 'opacity-40 bg-gray-50' : 'hover:bg-gray-50' }}">
                                        <input type="checkbox"
                                               wire:click="toggleSection('{{ $sectionKey }}')"
                                               {{ !$isExcluded ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 flex-shrink-0">
                                        <span class="h-2 w-2 rounded-full {{ $dotColor }} flex-shrink-0"></span>
                                        <span class="text-sm font-medium text-gray-900 min-w-0 truncate">{{ $preview['label'] }}</span>
                                        @if(!empty($preview['metrics']))
                                            <div class="ml-auto flex items-center gap-3 flex-shrink-0">
                                                @foreach($preview['metrics'] as $metric)
                                                    <span class="text-xs text-gray-500 whitespace-nowrap">
                                                        <span class="text-gray-400">{{ $metric['label'] }}:</span>
                                                        <span class="font-medium text-gray-700">{{ $metric['value'] }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    {{-- Sub-items (e.g. overview cards) --}}
                                    @if(!empty($preview['sub_items']) && !$isExcluded)
                                        @foreach($preview['sub_items'] as $sub)
                                            @php $subExcluded = in_array($sub['key'], $excludedSections); @endphp
                                            <div class="flex items-center gap-3 pl-9 pr-3 py-1.5 transition {{ $subExcluded ? 'opacity-40 bg-gray-50' : 'hover:bg-gray-50' }}">
                                                <input type="checkbox"
                                                       wire:click="toggleSection('{{ $sub['key'] }}')"
                                                       {{ !$subExcluded ? 'checked' : '' }}
                                                       class="h-3.5 w-3.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 flex-shrink-0">
                                                <span class="text-xs text-gray-600">{{ $sub['label'] }}</span>
                                            </div>
                                        @endforeach
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-8 text-gray-400">
                                <p class="text-sm">No sections configured in the selected template.</p>
                            </div>
                        @endif

                        @php
                            $excludedTopLevel = collect($excludedSections)->reject(fn($s) => str_contains($s, ':'))->count();
                            $excludedSubItems = collect($excludedSections)->filter(fn($s) => str_contains($s, ':'))->count();
                        @endphp
                        @if($excludedTopLevel > 0 || $excludedSubItems > 0)
                            <div class="mt-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-700">
                                @if($excludedTopLevel > 0)
                                    {{ $excludedTopLevel }} section(s) excluded.
                                @endif
                                @if($excludedSubItems > 0)
                                    {{ $excludedSubItems }} overview card(s) excluded.
                                @endif
                            </div>
                        @endif
                    @else
                        {{-- ═══ STEP 2: Recommendations ═══ --}}
                        @if(count($draftRecommendations) > 0)
                            <div class="space-y-2 mb-4">
                                @foreach($draftRecommendations as $rec)
                                    <div class="flex items-start gap-3 rounded-lg border {{ $rec['is_included'] ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60' }} p-3 group">
                                        <div class="flex-shrink-0 pt-0.5">
                                            <input type="checkbox"
                                                   wire:click="toggleRecommendation({{ $rec['id'] }})"
                                                   {{ $rec['is_included'] ? 'checked' : '' }}
                                                   class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-0.5">
                                                <span class="font-medium text-sm text-gray-900">{{ $rec['title'] }}</span>
                                                @php
                                                    $prioVariant = match($rec['priority']) { 'high' => 'red', 'medium' => 'yellow', default => 'gray' };
                                                @endphp
                                                <x-ui.badge :variant="$prioVariant">{{ ucfirst($rec['priority']) }}</x-ui.badge>
                                                <x-ui.badge variant="blue">{{ ucfirst($rec['category']) }}</x-ui.badge>
                                                @if($rec['is_auto_generated'])
                                                    <span class="text-xs text-gray-400">auto</span>
                                                @endif
                                            </div>
                                            <p class="text-sm text-gray-500 line-clamp-2">{{ $rec['description'] }}</p>
                                        </div>
                                        <button wire:click="removeRecommendation({{ $rec['id'] }})"
                                                class="flex-shrink-0 text-gray-400 hover:text-red-500 p-1 opacity-0 group-hover:opacity-100 transition"
                                                title="Remove">
                                            <x-icons.x class="h-4 w-4" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-8 text-gray-400">
                                <p class="text-sm">No recommendations for this report.</p>
                                <p class="text-xs mt-1">Add a custom one below or generate will proceed without recommendations.</p>
                            </div>
                        @endif

                        {{-- Add custom recommendation --}}
                        <div x-data="{ showForm: false }">
                            <button @click="showForm = !showForm"
                                    class="flex items-center gap-2 text-sm font-medium text-purple-600 hover:text-purple-700 mb-3">
                                <svg x-show="!showForm" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                <svg x-show="showForm" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                Add Custom Recommendation
                            </button>

                            <div x-show="showForm" x-cloak class="rounded-lg border border-dashed border-gray-300 p-4">
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                                        <x-ui.input type="text" wire:model="newRecTitle" placeholder="Recommendation title" />
                                        @error('newRecTitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                                            <x-ui.select wire:model="newRecPriority">
                                                <option value="high">High</option>
                                                <option value="medium">Medium</option>
                                                <option value="low">Low</option>
                                            </x-ui.select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                                            <x-ui.select wire:model="newRecCategory">
                                                <option value="technical">Technical</option>
                                                <option value="performance">Performance</option>
                                                <option value="seo">SEO</option>
                                            </x-ui.select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                        <textarea wire:model="newRecDescription" rows="2"
                                                  placeholder="Detailed recommendation description"
                                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                                        @error('newRecDescription') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <x-ui.button wire:click="addCustomRecommendation" size="sm">
                                        Add
                                    </x-ui.button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Footer --}}
                <div class="sticky bottom-0 z-10 flex items-center justify-between border-t border-gray-200 bg-gray-50 px-6 py-4 rounded-b-xl">
                    <div>
                        @if($generateStep === 2)
                            <button wire:click="backToPreview" class="text-sm font-medium text-gray-600 hover:text-gray-800">
                                &larr; Back to Data Preview
                            </button>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <x-ui.button variant="secondary" wire:click="$set('showGenerateModal', false)">
                            Cancel
                        </x-ui.button>
                        @if($generateStep === 1)
                            <x-ui.button wire:click="proceedToRecommendations">
                                Next: Recommendations &rarr;
                            </x-ui.button>
                        @else
                            <x-ui.button wire:click="confirmGenerate">
                                Generate Report
                            </x-ui.button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Schedule Configuration Modal --}}
    @if($showScheduleModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="$set('showScheduleModal', false)"></div>
            <div class="relative w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Report Schedule</h3>
                    <button wire:click="$set('showScheduleModal', false)" class="text-gray-400 hover:text-gray-600">
                        <x-icons.x class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    {{-- Active toggle --}}
                    <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3">
                        <span class="text-sm font-medium text-gray-700">Enable automatic reports</span>
                        <button wire:click="$toggle('scheduleActive')"
                                class="relative inline-flex h-6 w-11 items-center rounded-full transition {{ $scheduleActive ? 'bg-purple-600' : 'bg-gray-200' }}">
                            <span class="inline-block h-4 w-4 rounded-full bg-white transition {{ $scheduleActive ? 'translate-x-6' : 'translate-x-1' }}"></span>
                        </button>
                    </div>

                    {{-- Template --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Report Template</label>
                        <x-ui.select wire:model="scheduleTemplateId">
                            @foreach($templates as $tpl)
                                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Frequency --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Frequency</label>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model.live="scheduleFrequency" value="weekly" class="text-purple-600 focus:ring-purple-500">
                                <span class="text-sm">Weekly</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model.live="scheduleFrequency" value="monthly" class="text-purple-600 focus:ring-purple-500">
                                <span class="text-sm">Monthly</span>
                            </label>
                        </div>
                    </div>

                    {{-- Day & Time --}}
                    <div class="grid grid-cols-2 gap-3">
                        @if($scheduleFrequency === 'weekly')
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Day of Week</label>
                                <x-ui.select wire:model="scheduleDayOfWeek">
                                    <option value="0">Sunday</option>
                                    <option value="1">Monday</option>
                                    <option value="2">Tuesday</option>
                                    <option value="3">Wednesday</option>
                                    <option value="4">Thursday</option>
                                    <option value="5">Friday</option>
                                    <option value="6">Saturday</option>
                                </x-ui.select>
                            </div>
                        @else
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Day of Month</label>
                                <x-ui.select wire:model="scheduleDayOfMonth">
                                    @for($i = 1; $i <= 28; $i++)
                                        <option value="{{ $i }}">{{ $i }}</option>
                                    @endfor
                                </x-ui.select>
                            </div>
                        @endif
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Time</label>
                            <x-ui.input type="time" wire:model="scheduleTime" />
                        </div>
                    </div>

                    {{-- Period --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Report Period</label>
                        <x-ui.select wire:model="schedulePeriod">
                            <option value="last_7_days">Last 7 days</option>
                            <option value="last_30_days">Last 30 days</option>
                            <option value="last_month">Last calendar month</option>
                        </x-ui.select>
                    </div>

                    {{-- Recipients --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Recipients</label>
                        <x-ui.input type="text" wire:model="scheduleRecipientEmails"
                               placeholder="client@example.com, team@example.com" />
                        <p class="mt-1 text-xs text-gray-500">Comma-separated email addresses</p>
                    </div>

                    {{-- Admin copy --}}
                    <div class="flex items-center gap-2">
                        <input type="checkbox" wire:model="scheduleSendCopyToAdmin" id="adminCopy"
                               class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <label for="adminCopy" class="text-sm text-gray-700">Send copy to admin email</label>
                    </div>

                    {{-- Client branding --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client Name (optional)</label>
                        <x-ui.input type="text" wire:model="scheduleClientName"
                               placeholder="Client company name" />
                    </div>

                    {{-- Custom email --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Subject (optional)</label>
                        <x-ui.input type="text" wire:model="scheduleEmailSubject"
                               placeholder="Leave empty for default" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Body (optional)</label>
                        <textarea wire:model="scheduleEmailBody" rows="3"
                                  placeholder="Custom message in the email body"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <div>
                        @if($editingScheduleId)
                            <button wire:click="deleteSchedule"
                                    wire:confirm="Are you sure you want to delete this schedule?"
                                    class="text-sm text-red-600 hover:text-red-700">
                                Delete Schedule
                            </button>
                        @endif
                    </div>
                    <div class="flex gap-3">
                        <x-ui.button variant="secondary" wire:click="$set('showScheduleModal', false)">
                            Cancel
                        </x-ui.button>
                        <x-ui.button wire:click="saveSchedule">
                            Save Schedule
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Send Report Modal --}}
    @if($showSendModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="$set('showSendModal', false)"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-5">
                    <h3 class="text-lg font-semibold text-gray-900">Send Report</h3>
                    <p class="mt-1 text-sm text-gray-500">Send the report PDF to an email address</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <x-ui.input type="email" wire:model="sendToEmail"
                           placeholder="recipient@example.com" />
                    @error('sendToEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="$set('showSendModal', false)">
                        Cancel
                    </x-ui.button>
                    <x-ui.button wire:click="sendReport">
                        Send
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
