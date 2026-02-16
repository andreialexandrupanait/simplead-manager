<div>
    {{-- Header actions --}}
    <div class="mb-6 flex justify-end">
        <div class="flex gap-3">
            <x-ui.button variant="secondary" wire:click="openScheduleModal">
                <x-icons.settings class="h-4 w-4" />
                Schedule
            </x-ui.button>
            <x-ui.button wire:click="openGenerateModal">
                <x-icons.file-text class="h-4 w-4" />
                Generate Report
            </x-ui.button>
        </div>
    </div>

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
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h2 class="font-medium text-gray-900">Report History</h2>
        </div>

        @if($reports->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
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
                            <tr class="hover:bg-gray-50">
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
        @else
            <x-ui.empty-state
                title="No reports generated yet"
                description="Generate your first report to get started."
                icon="file-text"
            >
                <x-slot:action>
                    <x-ui.button wire:click="openGenerateModal">Generate Report</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @endif
    </div>

    {{-- Generate Report Modal --}}
    @if($showGenerateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="$set('showGenerateModal', false)"></div>
            <div class="relative w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Generate Report</h3>
                    <button wire:click="$set('showGenerateModal', false)" class="text-gray-400 hover:text-gray-600">
                        <x-icons.x class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    {{-- Template --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Report Template</label>
                        <x-ui.select wire:model="selectedTemplateId">
                            @foreach($templates as $tpl)
                                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Period --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Report Period</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="period" value="last_7_days" class="text-purple-600 focus:ring-purple-500">
                                <span class="text-sm">Last 7 days</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="period" value="last_30_days" class="text-purple-600 focus:ring-purple-500">
                                <span class="text-sm">Last 30 days</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="period" value="last_month" class="text-purple-600 focus:ring-purple-500">
                                <span class="text-sm">Last month</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model.live="period" value="custom" class="text-purple-600 focus:ring-purple-500">
                                <span class="text-sm">Custom dates</span>
                            </label>
                        </div>

                        @if($period === 'custom')
                            <div class="mt-3 grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Start Date</label>
                                    <x-ui.input type="date" wire:model="customStart" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">End Date</label>
                                    <x-ui.input type="date" wire:model="customEnd" />
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Recipients --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Send to (optional)</label>
                        <x-ui.input type="text" wire:model="recipientEmails"
                               placeholder="email@example.com, another@example.com" />
                        <p class="mt-1 text-xs text-gray-500">Comma-separated email addresses. Leave empty to just generate.</p>
                    </div>

                    @error('selectedTemplateId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('customStart') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('customEnd') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="$set('showGenerateModal', false)">
                        Cancel
                    </x-ui.button>
                    <x-ui.button wire:click="generateReport">
                        Generate Report
                    </x-ui.button>
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
