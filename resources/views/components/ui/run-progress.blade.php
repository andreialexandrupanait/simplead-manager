@props([
    // What is running, in the person's words: "Full Backup", "Restore".
    'title',
    // Optional right-hand details in the header row.
    'elapsed' => null,
    // A wire action for the cancel button, plus its confirmation. Omitted means
    // this run cannot be cancelled and no button is offered — which is the honest
    // shape for a restore, where stopping half-way is worse than finishing.
    'cancel' => null,
    'cancelConfirm' => null,
    // A wire action for dismissing a finished panel.
    'dismiss' => null,
    // The terminal-style activity log, and the x-ref name it scrolls by. The ref
    // must be unique per panel: two panels sharing one name means the second
    // scrolls the first.
    'log' => [],
    'logRef' => 'runLogPanel',
    // A closing note shown once the run has finished, e.g. "Completed in 42 s".
    'note' => null,
])

{{--
    One live progress panel, for a backup or a restore.

    This markup existed twice, ninety-odd lines each, differing in four places: the
    title, whether cancelling is offered, the closing note, and which log it reads.
    Everything else — the state icons, the bar, the percentage, the collapsible
    terminal — was duplicated, and had already started to drift: the backup panel
    hid its percentage on failure and showed the error in a red box, the restore
    panel showed the percentage always and the error inline. Same event, two
    accounts of it.

    Reads `status`, `pct`, `message` and `stage` from the Alpine scope of whatever
    renders it. That is a contract rather than an accident: the values are polled
    and updated by the parent's x-effect, so the panel must not own them.
--}}
<x-ui.card>
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <template x-if="status === 'pending' || status === 'in_progress'">
                    <x-ui.spinner size="md" class="text-accent-600" />
                </template>
                <template x-if="status === 'completed'">
                    <svg aria-hidden="true" class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </template>
                <template x-if="status === 'failed'">
                    <svg aria-hidden="true" class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </template>
                <h3 class="text-sm font-semibold text-gray-900">
                    {{ $title }}
                    <span x-show="status === 'pending'">{{ __('Queued') }}</span>
                    <span x-show="status === 'in_progress'">{{ __('in Progress') }}</span>
                    <span x-show="status === 'completed'">{{ __('Complete') }}</span>
                    <span x-show="status === 'failed'">{{ __('Failed') }}</span>
                </h3>
            </div>

            <div class="flex items-center gap-3 text-xs text-gray-500">
                <span x-show="stage && (status === 'pending' || status === 'in_progress')"
                      x-text="stage.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())"></span>

                @if($elapsed)
                    <span>{{ $elapsed }}</span>
                @endif

                @if($cancel)
                    <template x-if="status === 'pending' || status === 'in_progress'">
                        <button wire:click="{{ $cancel }}"
                                @if($cancelConfirm) wire:confirm="{{ $cancelConfirm }}" @endif
                                class="font-medium text-red-400 hover:text-red-600"
                                title="{{ __('Cancel') }}">
                            {{ __('Cancel') }}
                        </button>
                    </template>
                @endif

                @if($dismiss)
                    <template x-if="status === 'completed' || status === 'failed'">
                        <button @click="dismissed = true; $wire.{{ $dismiss }}()"
                                class="text-gray-400 hover:text-gray-600"
                                title="{{ __('Dismiss') }}">
                            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </template>
                @endif
            </div>
        </div>

        {{-- The bar is bound rather than server-rendered, so x-ui.progress-bar (which
             writes its width once, at render) cannot stand in for it. A pending run
             shows a sliver so the panel does not read as an empty box while a job
             waits for a worker. --}}
        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
            <div class="h-2 rounded-full"
                 :class="{
                    'bg-green-500': status === 'completed',
                    'bg-red-500': status === 'failed',
                    'bg-accent-500': status === 'pending' || status === 'in_progress',
                 }"
                 :style="'width: ' + Math.max(pct, status === 'pending' ? 15 : 0) + '%; transition: width 0.7s ease-out'"></div>
        </div>

        <div class="flex items-center justify-between text-xs text-gray-500">
            <span x-show="status !== 'failed'" x-text="pct + '%'"></span>
            <span x-show="status !== 'failed' && message" x-text="message"></span>
            @if($note)
                <span>{{ $note }}</span>
            @endif
        </div>

        {{-- A failure gets the message in full, in its own box. Squeezing it into the
             footer line truncated the one sentence that says what to do next. --}}
        <div x-show="status === 'failed'" x-cloak
             class="rounded-md border border-red-200 bg-red-50 p-2.5 text-xs text-red-700"
             x-text="message"></div>

        @if(count($log) > 0)
            <div x-data="{ logOpen: status === 'pending' || status === 'in_progress' }">
                <button @click="logOpen = !logOpen" class="flex items-center gap-1.5 text-xs text-gray-500 transition hover:text-gray-700">
                    <svg aria-hidden="true" class="h-3 w-3 transition-transform" :class="logOpen && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    <span x-text="logOpen ? @js(__('Activity Log')) : @js(__('Show log (:count entries)', ['count' => count($log)]))"></span>
                </button>
                <div x-show="logOpen" x-collapse>
                    <div class="mt-2 max-h-40 overflow-y-auto rounded-lg bg-gray-900 p-3 font-mono text-xs leading-relaxed"
                         x-ref="{{ $logRef }}"
                         x-effect="if (logOpen) { $nextTick(() => { $refs.{{ $logRef }}.scrollTop = $refs.{{ $logRef }}.scrollHeight }) }">
                        @foreach($log as $entry)
                            <div class="{{ str_starts_with($entry['message'] ?? '', 'FAILED:') ? 'text-red-400' : 'text-green-400' }}">
                                <span class="text-gray-500">[{{ $entry['time'] }}]</span> {{ $entry['message'] }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-ui.card>
