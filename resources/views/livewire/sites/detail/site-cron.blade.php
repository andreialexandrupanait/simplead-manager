<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Cron Jobs') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('View and manage WordPress scheduled tasks.') }}</p>
        </div>
        <x-ui.button variant="secondary" wire:click="loadCrons" wire:loading.attr="disabled">
            <x-ui.spinner size="sm" class="mr-1 hidden" wire:loading.class.remove="hidden" wire:target="loadCrons" />
            <span wire:loading.remove wire:target="loadCrons">
                @if($cronData)
                    {{ __('Refresh') }}
                @else
                    {{ __('Load Cron Jobs') }}
                @endif
            </span>
            <span wire:loading wire:target="loadCrons">{{ __('Loading...') }}</span>
        </x-ui.button>
    </div>

    @if($cronData)
        {{-- Search --}}
        <div class="mb-4">
            <input type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('Search hooks...') }}"
                   class="w-full max-w-sm rounded-lg border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" />
        </div>

        {{-- Stats --}}
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat-card
                label="{{ __('Total Hooks') }}"
                :value="count($cronData['crons'] ?? [])"
                icon="clock"
                color="purple"
            />
            <x-ui.stat-card
                label="{{ __('Disabled') }}"
                :value="count(array_filter($cronData['crons'] ?? [], fn($c) => $c['disabled']))"
                icon="pause-circle"
                color="yellow"
            />
            <x-ui.stat-card
                label="{{ __('One-time') }}"
                :value="count(array_filter($cronData['crons'] ?? [], fn($c) => ($c['schedule'] ?? 'once') === 'once'))"
                icon="zap"
                color="blue"
            />
        </div>

        {{-- Cron table --}}
        @if(count($this->filteredCrons) > 0)
            {{-- Mobile cards --}}
            <div class="md:hidden space-y-2">
                @foreach($this->filteredCrons as $cron)
                    <div class="rounded-lg border border-gray-200 p-3 {{ $cron['disabled'] ? 'bg-yellow-50/50' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <span class="block truncate font-mono text-sm text-gray-900">{{ $cron['hook'] }}</span>
                                @if(!empty($cron['args']))
                                    <span class="text-xs text-gray-400">({{ count($cron['args']) }} arg{{ count($cron['args']) !== 1 ? 's' : '' }})</span>
                                @endif
                            </div>
                            @if($cron['disabled'])
                                <x-ui.badge variant="yellow">{{ __('Disabled') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                            @endif
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <div class="flex items-center gap-1">
                                <x-ui.badge :variant="($cron['schedule'] ?? 'once') === 'once' ? 'gray' : 'blue'">
                                    {{ $cron['schedule_label'] ?? $cron['schedule'] ?? 'once' }}
                                </x-ui.badge>
                                @if($cron['interval'])
                                    <span class="text-xs text-gray-400">({{ round($cron['interval'] / 3600, 1) }}h)</span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-500">
                                {{ __('Next:') }}
                                @if($cron['next_run'] <= time())
                                    <span class="font-medium text-orange-600">{{ __('Overdue') }}</span>
                                @else
                                    <span class="text-gray-700">{{ $cron['next_run_human'] }}</span>
                                @endif
                                <span class="text-gray-400">({{ date('M d, H:i', $cron['next_run']) }})</span>
                            </span>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            @if(!$cron['disabled'])
                                <x-ui.button
                                    size="xs"
                                    variant="secondary"
                                    wire:click="runCron('{{ $cron['hook'] }}', {{ json_encode($cron['args']) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="runCron('{{ $cron['hook'] }}')"
                                    title="{{ __('Run now') }}"
                                >
                                    <x-icons.play class="h-3.5 w-3.5" />
                                </x-ui.button>
                                <x-ui.button
                                    size="xs"
                                    variant="secondary"
                                    wire:click="disableCron('{{ $cron['hook'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="disableCron('{{ $cron['hook'] }}')"
                                    title="{{ __('Disable') }}"
                                >
                                    <x-icons.pause class="h-3.5 w-3.5" />
                                </x-ui.button>
                            @else
                                <x-ui.button
                                    size="xs"
                                    variant="secondary"
                                    wire:click="confirmEnableCron('{{ $cron['hook'] }}')"
                                    title="{{ __('Enable') }}"
                                >
                                    <x-icons.play class="h-3.5 w-3.5" />
                                    <span class="ml-1">{{ __('Enable') }}</span>
                                </x-ui.button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <x-ui.card :padding="false" class="hidden md:block">
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-slot:head>
                            <x-ui.th>{{ __('Hook') }}</x-ui.th>
                            <x-ui.th>{{ __('Schedule') }}</x-ui.th>
                            <x-ui.th>{{ __('Next Run') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th class="text-right">{{ __('Actions') }}</x-ui.th>
                        </x-slot:head>
                        @foreach($this->filteredCrons as $cron)
                            <tr class="{{ $cron['disabled'] ? 'bg-yellow-50/50' : '' }}">
                                <x-ui.td>
                                    <span class="text-sm font-mono text-gray-900">{{ $cron['hook'] }}</span>
                                    @if(!empty($cron['args']))
                                        <span class="ml-1 text-xs text-gray-400" title="{{ json_encode($cron['args']) }}">
                                            ({{ count($cron['args']) }} arg{{ count($cron['args']) !== 1 ? 's' : '' }})
                                        </span>
                                    @endif
                                </x-ui.td>
                                <x-ui.td>
                                    <x-ui.badge :variant="($cron['schedule'] ?? 'once') === 'once' ? 'gray' : 'blue'">
                                        {{ $cron['schedule_label'] ?? $cron['schedule'] ?? 'once' }}
                                    </x-ui.badge>
                                    @if($cron['interval'])
                                        <span class="ml-1 text-xs text-gray-400">
                                            ({{ round($cron['interval'] / 3600, 1) }}h)
                                        </span>
                                    @endif
                                </x-ui.td>
                                <x-ui.td>
                                    @if($cron['next_run'] <= time())
                                        <span class="text-sm text-orange-600 font-medium">{{ __('Overdue') }}</span>
                                    @else
                                        <span class="text-sm text-gray-600">
                                            {{ $cron['next_run_human'] }}
                                        </span>
                                    @endif
                                    <span class="block text-xs text-gray-400">
                                        {{ date('M d, H:i', $cron['next_run']) }}
                                    </span>
                                </x-ui.td>
                                <x-ui.td>
                                    @if($cron['disabled'])
                                        <x-ui.badge variant="yellow">{{ __('Disabled') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                                    @endif
                                </x-ui.td>
                                <x-ui.td class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if(!$cron['disabled'])
                                            <x-ui.button
                                                size="xs"
                                                variant="secondary"
                                                wire:click="runCron('{{ $cron['hook'] }}', {{ json_encode($cron['args']) }})"
                                                wire:loading.attr="disabled"
                                                wire:target="runCron('{{ $cron['hook'] }}')"
                                                title="{{ __('Run now') }}"
                                            >
                                                <x-icons.play class="h-3.5 w-3.5" />
                                            </x-ui.button>
                                            <x-ui.button
                                                size="xs"
                                                variant="secondary"
                                                wire:click="disableCron('{{ $cron['hook'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="disableCron('{{ $cron['hook'] }}')"
                                                title="{{ __('Disable') }}"
                                            >
                                                <x-icons.pause class="h-3.5 w-3.5" />
                                            </x-ui.button>
                                        @else
                                            <x-ui.button
                                                size="xs"
                                                variant="secondary"
                                                wire:click="confirmEnableCron('{{ $cron['hook'] }}')"
                                                title="{{ __('Enable') }}"
                                            >
                                                <x-icons.play class="h-3.5 w-3.5" />
                                                <span class="ml-1">{{ __('Enable') }}</span>
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </div>
            </x-ui.card>
        @else
            <x-ui.card>
                <div class="py-8 text-center">
                    <x-icons.clock class="mx-auto h-12 w-12 text-gray-300" />
                    <h3 class="mt-3 text-sm font-semibold text-gray-900">{{ __('No cron jobs found') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        @if($search)
                            {{ __('No hooks match your search.') }}
                        @else
                            {{ __('No scheduled tasks are registered on this site.') }}
                        @endif
                    </p>
                </div>
            </x-ui.card>
        @endif
    @else
        {{-- Empty state --}}
        <x-ui.card>
            <div class="py-12 text-center">
                <x-icons.clock class="mx-auto h-12 w-12 text-gray-300" />
                <h3 class="mt-3 text-sm font-semibold text-gray-900">{{ __('Cron Jobs') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Click "Load Cron Jobs" to view all scheduled tasks on this WordPress site.') }}</p>
            </div>
        </x-ui.card>
    @endif

    {{-- Enable Cron Modal --}}
    <x-ui.modal name="enable-cron" maxWidth="sm">
        <div class="p-2">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Enable Cron Hook') }}</h3>
            <p class="mt-2 text-sm text-gray-600">
                {{ __('Re-enable') }} <span class="font-mono font-medium">{{ $enablingHook }}</span> {{ __('with a schedule.') }}
            </p>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Schedule') }}</label>
                <select wire:model="enableSchedule"
                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                    @if($cronData && isset($cronData['schedules']))
                        @foreach($cronData['schedules'] as $key => $schedule)
                            <option value="{{ $key }}">{{ $schedule['display'] }} ({{ round($schedule['interval'] / 3600, 1) }}h)</option>
                        @endforeach
                    @else
                        <option value="hourly">{{ __('Hourly') }}</option>
                        <option value="twicedaily">{{ __('Twice Daily') }}</option>
                        <option value="daily">{{ __('Daily') }}</option>
                        <option value="weekly">{{ __('Weekly') }}</option>
                    @endif
                </select>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-enable-cron')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="enableCron" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="enableCron">{{ __('Enable Hook') }}</span>
                    <span wire:loading wire:target="enableCron">{{ __('Enabling...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
