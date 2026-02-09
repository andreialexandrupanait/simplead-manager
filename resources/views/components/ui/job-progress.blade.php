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
        <div {{ $attributes->merge(['class' => 'mb-4 rounded-lg bg-purple-50 border border-purple-200 p-3']) }}>
            <div class="flex items-center gap-2.5">
                <x-ui.spinner size="sm" class="text-purple-600 shrink-0" />
                <span class="text-sm font-medium text-purple-700">{{ $title ?? $job['message'] }}</span>
            </div>
            <div class="mt-2">
                @if($job['progress'] > 0)
                    <x-ui.progress-bar :percent="$job['progress']" color="purple" size="sm" />
                @else
                    <x-ui.progress-bar :indeterminate="true" color="purple" size="sm" />
                @endif
            </div>
            @if($job['progress'] > 0 && $title)
                <p class="mt-1.5 text-xs text-purple-600">{{ $job['message'] }}</p>
            @endif
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
