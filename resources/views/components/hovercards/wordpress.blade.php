@props(['site'])

@if($site->is_connected)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">WordPress</span>
        <x-ui.badge variant="green">Connected</x-ui.badge>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        @if($site->wp_version)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">WP version</span>
                <span class="font-medium text-gray-900">{{ $site->wp_version }}</span>
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
                <span class="text-gray-500">Server</span>
                <span class="font-medium text-gray-900">{{ $site->server_software }}</span>
            </div>
        @endif
        @if($site->db_size)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">DB size</span>
                <span class="font-medium text-gray-900">{{ $site->db_size }}</span>
            </div>
        @endif
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Multisite</span>
            <span class="font-medium text-gray-900">{{ $site->is_multisite ? 'Yes' : 'No' }}</span>
        </div>
        @if($site->last_synced_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Last synced</span>
                <span class="font-medium text-gray-900">{{ $site->last_synced_at->diffForHumans() }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.overview', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Overview</a>
    </div>
@else
    <p class="text-sm text-gray-500">Not connected</p>
    <a href="{{ route('sites.overview', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">View Overview</a>
@endif
