@props(['site'])

@php
    $health = $site->healthState;
    if (!$health) return;

    $state = $health->circuit_state;
    $disabled = $health->is_monitoring_disabled;

    // Only show banner for non-closed states or disabled monitoring
    if (!$disabled && $state === 'closed') return;
@endphp

@if($disabled)
    <div class="mb-4 rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 p-4">
        <div class="flex items-start gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700">
                <svg class="h-4 w-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Monitoring Disabled</h4>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Automatic monitoring is paused for this site. Uptime checks, backups, and sync operations will not run.
                </p>
                <div class="mt-3">
                    <x-ui.button variant="secondary" size="sm" wire:click="enableMonitoring">
                        Re-enable Monitoring
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
@elseif($state === 'open')
    <div class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4">
        <div class="flex items-start gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-200">
                <svg class="h-4 w-4 text-red-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-semibold text-red-900">Circuit Breaker Open</h4>
                <p class="mt-1 text-sm text-red-700">
                    {{ $health->consecutive_failures }} consecutive failures detected.
                    @if($health->last_failure_reason)
                        Last error: {{ Str::limit($health->last_failure_reason, 120) }}
                    @endif
                </p>
                @if($health->circuit_opened_at)
                    <p class="mt-1 text-xs text-red-600">
                        Opened {{ $health->circuit_opened_at->diffForHumans() }}
                        &middot; {{ $health->circuit_breaks_last_24h }} {{ Str::plural('break', $health->circuit_breaks_last_24h) }} in last 24h
                    </p>
                @endif
                <div class="mt-3">
                    <x-ui.button variant="secondary" size="sm" wire:click="resumeMonitoring">
                        Resume Now
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
@elseif($state === 'half_open')
    <div class="mb-4 rounded-lg border border-yellow-300 bg-yellow-50 p-4">
        <div class="flex items-start gap-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-yellow-200">
                <svg class="h-4 w-4 text-yellow-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-semibold text-yellow-900">Recovering</h4>
                <p class="mt-1 text-sm text-yellow-700">
                    The circuit breaker is half-open. A test request is being sent to verify the site is responding.
                </p>
            </div>
        </div>
    </div>
@endif
