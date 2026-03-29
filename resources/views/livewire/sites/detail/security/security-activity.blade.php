<div>
    <x-ui.page-header title="Activity Log" subtitle="Monitor login attempts and security events" />

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    {{-- Failed Login Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card
            label="Failed Logins"
            :value="$this->failedLoginStats['total']"
            description="Last {{ $filterDays }} days"
        />
        <x-ui.stat-card
            label="Unique IPs"
            :value="$this->failedLoginStats['unique_ips']"
            description="Distinct source IPs"
        />
        <x-ui.stat-card
            label="Unique Usernames"
            :value="$this->failedLoginStats['unique_usernames']"
            description="Targeted accounts"
        />
    </div>

    {{-- Filters --}}
    <x-ui.card class="mb-6">
        <div class="flex flex-wrap items-end gap-4">
            <div class="w-40">
                <label class="block text-xs font-medium text-gray-700 mb-1">Event Type</label>
                <x-ui.select wire:model.live="filterEventType" class="text-sm">
                    <option value="">All Events</option>
                    @foreach($this->eventTypes as $type)
                        <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="w-40">
                <label class="block text-xs font-medium text-gray-700 mb-1">IP Address</label>
                <x-ui.input type="text" wire:model.live.debounce.300ms="filterIp" placeholder="Filter by IP" class="text-sm" />
            </div>

            <div class="w-40">
                <label class="block text-xs font-medium text-gray-700 mb-1">Username</label>
                <x-ui.input type="text" wire:model.live.debounce.300ms="filterUsername" placeholder="Filter by user" class="text-sm" />
            </div>

            <div class="w-32">
                <label class="block text-xs font-medium text-gray-700 mb-1">Period</label>
                <x-ui.select wire:model.live="filterDays" class="text-sm">
                    <option value="1">24 hours</option>
                    <option value="7">7 days</option>
                    <option value="30">30 days</option>
                    <option value="90">90 days</option>
                </x-ui.select>
            </div>
        </div>
    </x-ui.card>

    {{-- Activity Table --}}
    <x-ui.card>
        @if($logs->isEmpty())
            <x-ui.empty-state
                title="No activity logs"
                description="Activity logs will appear here once the WordPress agent starts reporting events."
                icon="activity"
            />
        @else
            {{-- Mobile cards --}}
            <div class="md:hidden space-y-2">
                @foreach($logs as $log)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $log->event_type === 'failed_login' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700' }}">
                                {{ str_replace('_', ' ', $log->event_type) }}
                            </span>
                            <span class="text-xs text-gray-500">{{ $log->occurred_at->format('M d, H:i') }}</span>
                        </div>
                        <p class="mt-1.5 text-sm text-gray-700">
                            @if($log->object_type)
                                {{ $log->object_type }}: {{ $log->object_name }}
                            @else
                                {{ $log->action ?? '—' }}
                            @endif
                        </p>
                        <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                            <span>User: <span class="text-gray-700">{{ $log->username ?? '—' }}</span></span>
                            <span class="font-mono">{{ $log->ip_address ?? '—' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase text-gray-500">
                            <x-ui.sortable-th column="occurred_at" :sortBy="$sortBy" :sortDir="$sortDir">Time</x-ui.sortable-th>
                            <x-ui.sortable-th column="event_type" :sortBy="$sortBy" :sortDir="$sortDir">Event</x-ui.sortable-th>
                            <x-ui.sortable-th column="username" :sortBy="$sortBy" :sortDir="$sortDir">User</x-ui.sortable-th>
                            <th class="pb-2 pr-4">Action</th>
                            <x-ui.sortable-th column="ip_address" :sortBy="$sortBy" :sortDir="$sortDir">IP</x-ui.sortable-th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($logs as $log)
                            <tr>
                                <td class="py-2 pr-4 whitespace-nowrap text-xs text-gray-500">
                                    {{ $log->occurred_at->format('M d, H:i') }}
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $log->event_type === 'failed_login' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700' }}">
                                        {{ str_replace('_', ' ', $log->event_type) }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-sm text-gray-900">{{ $log->username ?? '—' }}</td>
                                <td class="py-2 pr-4 text-sm text-gray-500">
                                    @if($log->object_type)
                                        {{ $log->object_type }}: {{ $log->object_name }}
                                    @else
                                        {{ $log->action ?? '—' }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-xs text-gray-500 font-mono">{{ $log->ip_address ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $logs->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
