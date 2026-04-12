@php $dns = $this->dnsStatus; @endphp

<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                <svg aria-hidden="true" class="h-4 w-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">DNS</h3>
        </div>
        <a href="{{ route('dns.index') }}" class="text-xs text-accent-600 hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
            Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        @if($dns['available'])
            <div class="flex-1 space-y-2">
                {{-- SPF --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">SPF</span>
                    @if($dns['has_spf'])
                        <span class="text-sm font-medium text-green-600 dark:text-green-400">Configured</span>
                    @else
                        <span class="text-sm font-medium text-red-600 dark:text-red-400">Missing</span>
                    @endif
                </div>

                {{-- DMARC --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">DMARC</span>
                    @if($dns['has_dmarc'])
                        <span class="text-sm font-medium text-green-600 dark:text-green-400">Configured</span>
                    @else
                        <span class="text-sm font-medium text-red-600 dark:text-red-400">Missing</span>
                    @endif
                </div>

                {{-- Changes badge --}}
                @if($dns['has_changes'])
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Changes</span>
                        <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                            Detected
                        </span>
                    </div>
                @endif
            </div>

            @if($dns['last_checked'])
                <div class="mt-2 border-t border-gray-100 dark:border-gray-700 pt-2 text-xs text-gray-400 dark:text-gray-500">
                    Checked {{ $dns['last_checked']->diffForHumans() }}
                </div>
            @endif
        @else
            <div class="py-2 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">Not monitored</p>
                <a href="{{ route('dns.index') }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
                    Enable DNS Monitor →
                </a>
            </div>
        @endif
    </div>
</x-ui.card>
