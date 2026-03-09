@props(['check'])

<x-ui.card>
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">Core File Integrity</h3>
        @if($check)
            <x-ui.badge :variant="$check->status_color">
                {{ $check->status_label }}
            </x-ui.badge>
        @else
            <x-ui.badge variant="gray">Not Checked</x-ui.badge>
        @endif
    </div>

    <x-ui.flash-alert type="info" key="core-check-dispatched" />

    @if($check)
        <div class="mb-4 space-y-2">
            @if($check->wp_version)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">WordPress Version</span>
                    <span class="font-medium text-gray-900">{{ $check->wp_version }}</span>
                </div>
            @endif
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-500">Total Files Checked</span>
                <span class="font-medium text-gray-900">{{ number_format($check->total_files) }}</span>
            </div>
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-500">Modified</span>
                <span class="font-medium {{ $check->modified_count > 0 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ $check->modified_count }}
                </span>
            </div>
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-500">Missing</span>
                <span class="font-medium {{ $check->missing_count > 0 ? 'text-yellow-600' : 'text-gray-900' }}">
                    {{ $check->missing_count }}
                </span>
            </div>
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-500">Unknown</span>
                <span class="font-medium {{ $check->unknown_count > 0 ? 'text-yellow-600' : 'text-gray-900' }}">
                    {{ $check->unknown_count }}
                </span>
            </div>
        </div>

        @if($check->modified_files && count($check->modified_files) > 0)
            <details class="mb-3">
                <summary class="cursor-pointer text-sm font-medium text-red-600 hover:text-red-700">
                    {{ count($check->modified_files) }} Modified File(s)
                </summary>
                <div class="mt-2 max-h-60 overflow-y-auto rounded-lg bg-red-50 p-3">
                    @foreach($check->modified_files as $file)
                        <div class="mb-2 last:mb-0 text-xs">
                            <div class="font-medium text-red-800">{{ $file['path'] }}</div>
                            <div class="text-red-600">
                                Expected: <code class="bg-red-100 px-1 rounded">{{ Str::limit($file['expected_hash'], 16) }}</code>
                                Actual: <code class="bg-red-100 px-1 rounded">{{ Str::limit($file['actual_hash'], 16) }}</code>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if($check->missing_files && count($check->missing_files) > 0)
            <details class="mb-3">
                <summary class="cursor-pointer text-sm font-medium text-yellow-600 hover:text-yellow-700">
                    {{ count($check->missing_files) }} Missing File(s)
                </summary>
                <div class="mt-2 max-h-60 overflow-y-auto rounded-lg bg-yellow-50 p-3">
                    @foreach($check->missing_files as $path)
                        <div class="text-xs text-yellow-800">{{ $path }}</div>
                    @endforeach
                </div>
            </details>
        @endif

        @if($check->unknown_files && count($check->unknown_files) > 0)
            <details class="mb-3">
                <summary class="cursor-pointer text-sm font-medium text-yellow-600 hover:text-yellow-700">
                    {{ count($check->unknown_files) }} Unknown File(s)
                </summary>
                <div class="mt-2 max-h-60 overflow-y-auto rounded-lg bg-yellow-50 p-3">
                    @foreach($check->unknown_files as $path)
                        <div class="text-xs text-yellow-800">{{ $path }}</div>
                    @endforeach
                </div>
            </details>
        @endif

        @if($check->error_message)
            <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                {{ $check->error_message }}
            </div>
        @endif

        <div class="flex items-center justify-between border-t pt-4">
            <span class="text-xs text-gray-500">
                Last checked: {{ $check->checked_at?->diffForHumans() ?? 'Never' }}
            </span>
            <x-ui.button variant="secondary" size="sm" wire:click="checkCoreIntegrityNow" wire:loading.attr="disabled">
                <x-ui.spinner size="xs" class="hidden" wire:loading.class.remove="hidden" wire:target="checkCoreIntegrityNow" />
                Check Now
            </x-ui.button>
        </div>
    @else
        <p class="text-sm text-gray-500 mb-4">No core file integrity check has been performed yet.</p>
        <x-ui.button variant="secondary" size="sm" wire:click="checkCoreIntegrityNow" wire:loading.attr="disabled">
            <x-ui.spinner size="xs" class="hidden" wire:loading.class.remove="hidden" wire:target="checkCoreIntegrityNow" />
            Check Now
        </x-ui.button>
    @endif
</x-ui.card>
