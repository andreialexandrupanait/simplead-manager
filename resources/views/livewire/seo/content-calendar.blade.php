<div>
    <x-ui.page-header title="{{ __('Editorial Calendar') }}" subtitle="{{ __('Scheduled and published articles') }}">
        <a href="{{ route('seo.content.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition">
            <x-dynamic-component component="icons.plus" class="h-4 w-4" />
            {{ __('New Article') }}
        </a>
    </x-ui.page-header>

    {{-- Navigation --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button wire:click="previousMonth" class="rounded-lg border border-gray-300 p-2 hover:bg-gray-50 transition">
                <x-dynamic-component component="icons.chevron-left" class="h-4 w-4" />
            </button>
            <h2 class="text-lg font-semibold text-gray-900">
                {{ Carbon\Carbon::create($year, $month, 1)->translatedFormat('F Y') }}
            </h2>
            <button wire:click="nextMonth" class="rounded-lg border border-gray-300 p-2 hover:bg-gray-50 transition">
                <x-dynamic-component component="icons.chevron-right" class="h-4 w-4" />
            </button>
        </div>
        <select wire:model.live="siteFilter" class="rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
            <option value="">{{ __('All Sites') }}</option>
            @foreach($this->sites as $site)
                <option value="{{ $site->id }}">{{ $site->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- Calendar Grid --}}
    <x-ui.card class="!p-0 overflow-hidden">
        {{-- Header --}}
        <div class="grid grid-cols-7 border-b border-gray-200 bg-gray-50">
            @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                <div class="px-2 py-2 text-center text-xs font-medium text-gray-500">{{ __($day) }}</div>
            @endforeach
        </div>

        {{-- Days --}}
        <div
            class="grid grid-cols-7"
            x-data="{
                dragging: null,
                handleDrop(e, date) {
                    e.preventDefault();
                    if (this.dragging) {
                        $wire.reschedule(this.dragging, date);
                        this.dragging = null;
                    }
                }
            }"
        >
            @foreach($this->calendarDays as $day)
                @php
                    $dateStr = $day->format('Y-m-d');
                    $isCurrentMonth = $day->month === $month;
                    $isToday = $day->isToday();
                    $dayEvents = $this->events[$dateStr] ?? [];
                @endphp
                <div
                    @class([
                        'min-h-[100px] border-b border-r border-gray-200 p-1.5',
                        'bg-white' => $isCurrentMonth,
                        'bg-gray-50' => !$isCurrentMonth,
                    ])
                    @dragover.prevent
                    @drop="handleDrop($event, '{{ $dateStr }}')"
                >
                    <div @class([
                        'mb-1 text-xs font-medium',
                        'text-gray-900' => $isCurrentMonth && !$isToday,
                        'text-gray-400' => !$isCurrentMonth,
                        'text-purple-600 font-bold' => $isToday,
                    ])>
                        {{ $day->day }}
                    </div>

                    @foreach($dayEvents as $event)
                        <a
                            href="{{ route('seo.content.edit', $event) }}"
                            wire:navigate
                            draggable="true"
                            @dragstart="dragging = {{ $event->id }}"
                            @class([
                                'mb-1 block truncate rounded px-1.5 py-0.5 text-xs cursor-move transition',
                                'bg-green-100 text-green-800' => $event->status->value === 'published',
                                'bg-purple-100 text-purple-800' => $event->status->value === 'scheduled',
                                'bg-gray-100 text-gray-700' => $event->status->value === 'draft',
                                'bg-yellow-100 text-yellow-800' => $event->status->value === 'review',
                                'bg-red-100 text-red-800' => $event->status->value === 'failed',
                                'bg-blue-100 text-blue-800' => $event->status->value === 'generating',
                            ])
                            title="{{ $event->title }}"
                        >
                            {{ \Illuminate\Support\Str::limit($event->title, 25) }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
