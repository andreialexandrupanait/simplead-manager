@props([
    'jobKey' => null,
    'jobs' => [],
    'title' => null,
])

@php
$job = $jobs[$jobKey] ?? null;
if (!$job) return;
@endphp

@if($job)
    @if($job['status'] === 'running')
        <div
            wire:key="job-progress-{{ $jobKey }}"
            x-data="{ progress: {{ $job['progress'] }}, message: '{{ addslashes($job['message']) }}' }"
            x-init="$watch('progress', () => {})"
            x-effect="progress = {{ $job['progress'] }}; message = '{{ addslashes($job['message']) }}';"
            {{ $attributes->merge(['class' => 'mb-4 rounded-lg bg-purple-50 border border-purple-200 p-3']) }}
        >
            <div class="flex items-center justify-between gap-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                    <x-ui.spinner size="sm" class="text-purple-600 shrink-0" />
                    <span class="text-sm font-medium text-purple-700 truncate">{{ $title ?? $job['message'] }}</span>
                </div>
                <span class="text-xs font-semibold text-purple-600 shrink-0 tabular-nums" x-show="progress > 0" x-text="progress + '%'" x-cloak></span>
            </div>
            <div class="mt-2">
                <div class="w-full overflow-hidden rounded-full bg-purple-100 h-1.5">
                    <div
                        x-show="progress > 0"
                        class="bg-purple-500 h-1.5 rounded-full transition-all duration-700 ease-out"
                        :style="'width: ' + progress + '%'"
                    ></div>
                    <div
                        x-show="progress === 0"
                        class="bg-purple-500 h-1.5 w-1/3 animate-[progress-indeterminate_1.5s_infinite_ease-in-out]"
                    ></div>
                </div>
            </div>
            <p class="mt-1.5 text-xs text-purple-600 transition-opacity duration-300" x-show="progress > 0 && message" x-text="message" x-cloak></p>
        </div>
    @elseif($job['status'] === 'complete')
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 3000)"
            x-show="show"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            {{ $attributes->merge(['class' => 'mb-4 rounded-lg bg-green-50 border border-green-200 p-3']) }}
        >
            <div class="flex items-center gap-2.5">
                <x-icons.check-circle class="h-4 w-4 text-green-600 shrink-0" />
                <span class="text-sm font-medium text-green-700">{{ $job['message'] }}</span>
            </div>
        </div>
    @elseif($job['status'] === 'failed')
        <div
            x-data="{ show: true }"
            x-show="show"
            {{ $attributes->merge(['class' => 'mb-4 rounded-lg bg-red-50 border border-red-200 p-3']) }}
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <x-icons.x-circle class="h-4 w-4 text-red-600 shrink-0" />
                    <span class="text-sm font-medium text-red-700">{{ $job['message'] }}</span>
                </div>
                <button @click="show = false" class="text-red-400 hover:text-red-600">
                    <x-icons.x class="h-4 w-4" />
                </button>
            </div>
        </div>
    @endif
@endif
