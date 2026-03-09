@props(['site'])

@if($site->wp_version)
    @php
        $wpVerVariant = $site->core_update_version ? 'yellow' : 'green';
        $wpVerLabel = $site->core_update_version ? 'Update available' : 'Up to date';
    @endphp
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">WordPress Version</span>
        <x-ui.badge :variant="$wpVerVariant">{{ $wpVerLabel }}</x-ui.badge>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Current version</span>
            <span class="font-medium text-gray-900">{{ $site->wp_version }}</span>
        </div>
        @if($site->core_update_version)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Update available</span>
                <span class="font-medium text-gray-900">{{ $site->core_update_version }}</span>
            </div>
        @endif
        @if($site->php_version)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">PHP version</span>
                <span class="font-medium text-gray-900">{{ $site->php_version }}</span>
            </div>
        @endif
        @if($site->server_software)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Server software</span>
                <span class="font-medium text-gray-900">{{ $site->server_software }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.plugins', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Updates</a>
    </div>
@else
    <p class="text-sm text-gray-500">Unknown</p>
    <a href="{{ route('sites.overview', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">View Overview</a>
@endif
