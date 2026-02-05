<div
    x-data="{
        autoDismiss(key) {
            setTimeout(() => { $wire.clearResult(key); }, 5000);
        }
    }"
>
    <div class="mb-6 flex justify-end">
        <x-ui.button variant="secondary" wire:click="syncCronJobs" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span wire:loading.remove wire:target="syncCronJobs">Sync Cron Jobs</span>
            <span wire:loading wire:target="syncCronJobs">Syncing...</span>
        </x-ui.button>
    </div>

    {{-- Flash messages --}}
    @if(session('cron-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('cron-success') }}</div>
    @endif
    @if(session('cron-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ session('cron-error') }}</div>
    @endif

    {{-- Overdue warning --}}
    @if($this->overdueCount > 0)
        <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <span class="text-sm font-medium text-yellow-800">
                    {{ $this->overdueCount }} overdue cron job{{ $this->overdueCount > 1 ? 's' : '' }}
                </span>
            </div>
        </div>
    @endif

    <x-ui.card :padding="false">
        @if($this->cronJobs->count() > 0)
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>Hook</x-ui.th>
                    <x-ui.th>Schedule</x-ui.th>
                    <x-ui.th>Next Run</x-ui.th>
                    <x-ui.th>Last Run</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th class="text-right">Actions</x-ui.th>
                </x-slot:head>
                @foreach($this->cronJobs as $cron)
                    @php $resultKey = 'cron_' . $cron->id; @endphp
                    <tr class="hover:bg-gray-50 transition-colors"
                        wire:loading.class="!bg-blue-50" wire:target="runCron({{ $cron->id }}), disableCron({{ $cron->id }}), enableCron({{ $cron->id }})">
                        <x-ui.td>
                            <div class="text-sm font-medium text-gray-900">{{ $cron->friendly_name }}</div>
                            @if($cron->friendly_name !== $cron->hook)
                                <div class="text-xs text-gray-400 font-mono">{{ $cron->hook }}</div>
                            @endif

                            {{-- Inline result message --}}
                            @if(isset($actionResults[$resultKey]))
                                <div
                                    x-data="{ show: true }"
                                    x-init="autoDismiss('{{ $resultKey }}')"
                                    x-show="show"
                                    class="mt-1 text-xs font-medium {{ $actionResults[$resultKey]['success'] ? 'text-green-600' : 'text-red-600' }}"
                                >
                                    {{ $actionResults[$resultKey]['message'] }}
                                </div>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-sm text-gray-600">{{ $cron->friendly_schedule }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            @if($cron->next_run)
                                <span class="text-sm {{ $cron->is_overdue ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                                    {{ $cron->next_run->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-sm text-gray-400">&mdash;</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            @if($cron->last_run)
                                <span class="text-sm text-gray-600">{{ $cron->last_run->diffForHumans() }}</span>
                            @else
                                <span class="text-sm text-gray-400">&mdash;</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            @if($cron->is_disabled)
                                <x-ui.badge variant="gray">Disabled</x-ui.badge>
                            @elseif($cron->is_overdue)
                                <x-ui.badge variant="yellow">Overdue</x-ui.badge>
                            @else
                                <x-ui.badge variant="green">Active</x-ui.badge>
                            @endif
                        </x-ui.td>
                        <x-ui.td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    wire:click="runCron({{ $cron->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="runCron({{ $cron->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-purple-700 hover:bg-purple-50 transition"
                                >
                                    <span wire:loading.remove wire:target="runCron({{ $cron->id }})">Run Now</span>
                                    <span wire:loading wire:target="runCron({{ $cron->id }})">Running...</span>
                                </button>

                                @if($cron->is_disabled)
                                    <button
                                        wire:click="enableCron({{ $cron->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="enableCron({{ $cron->id }})"
                                        class="rounded px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-50 transition"
                                    >
                                        <span wire:loading.remove wire:target="enableCron({{ $cron->id }})">Enable</span>
                                        <span wire:loading wire:target="enableCron({{ $cron->id }})">...</span>
                                    </button>
                                @else
                                    <button
                                        wire:click="disableCron({{ $cron->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="disableCron({{ $cron->id }})"
                                        class="rounded px-2 py-1 text-xs font-medium text-yellow-700 hover:bg-yellow-50 transition"
                                    >
                                        <span wire:loading.remove wire:target="disableCron({{ $cron->id }})">Disable</span>
                                        <span wire:loading wire:target="disableCron({{ $cron->id }})">...</span>
                                    </button>
                                @endif
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @else
            <div class="p-8 text-center text-sm text-gray-500">
                No cron jobs found. Click "Sync Cron Jobs" to load them from the site.
            </div>
        @endif
    </x-ui.card>
</div>
