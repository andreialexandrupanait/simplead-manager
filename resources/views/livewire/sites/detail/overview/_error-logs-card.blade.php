@php $errorLog = $this->errorLogStatus; @endphp

<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg {{ $errorLog['fatal'] > 0 ? 'bg-red-100 dark:bg-red-900/30' : 'bg-gray-100 dark:bg-gray-800' }}">
                <svg aria-hidden="true" class="h-4 w-4 {{ $errorLog['fatal'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Error Logs</h3>
        </div>
        <a href="{{ route('error-logs.index') }}" class="text-xs text-accent-600 hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
            Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        <div class="flex-1 space-y-2">
            {{-- Fatal count --}}
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">Fatal Errors</span>
                @if($errorLog['fatal'] > 0)
                    <span class="text-sm font-bold text-red-600 dark:text-red-400">{{ $errorLog['fatal'] }}</span>
                @else
                    <span class="text-sm font-medium text-green-600 dark:text-green-400">None</span>
                @endif
            </div>

            {{-- Total unresolved --}}
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">Unresolved</span>
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $errorLog['total'] }}</span>
            </div>
        </div>

        @if($errorLog['total'] > 0)
            <div class="mt-3 border-t border-gray-100 dark:border-gray-700 pt-2">
                <a href="{{ route('error-logs.index') }}" class="block text-center text-xs font-medium text-accent-600 hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
                    View All Errors →
                </a>
            </div>
        @endif
    </div>
</x-ui.card>
