@props(['site'])

@if($site->wp_version)
    @php
        $eol = \App\Services\WordPressEolService::classify($site->wp_version);
        if ($eol['status'] === 'eol') {
            $wpVerVariant = 'red';
            $wpVerLabel = $eol['label'];
        } elseif ($eol['severity'] === 'high') {
            $wpVerVariant = 'red';
            $wpVerLabel = $eol['label'];
        } elseif ($eol['severity'] === 'warning') {
            $wpVerVariant = 'orange';
            $wpVerLabel = $eol['label'];
        } elseif ($site->core_update_version) {
            $wpVerVariant = 'yellow';
            $wpVerLabel = 'Update available';
        } else {
            $wpVerVariant = 'green';
            $wpVerLabel = 'Up to date';
        }
    @endphp
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">WordPress Version</span>
        <x-ui.badge :variant="$wpVerVariant">{{ $wpVerLabel }}</x-ui.badge>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500 dark:text-gray-400">Current version</span>
            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->wp_version }}</span>
        </div>
        @if($eol['behind'] > 0)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Versions behind</span>
                <span class="font-medium {{ $eol['severity'] === 'critical' ? 'text-red-600' : ($eol['severity'] === 'high' ? 'text-red-600' : ($eol['severity'] === 'warning' ? 'text-orange-600' : 'text-gray-900 dark:text-gray-100')) }}">{{ $eol['behind'] }}</span>
            </div>
        @endif
        @if($site->core_update_version)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Update available</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->core_update_version }}</span>
            </div>
        @endif
        @if($site->php_version)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">PHP version</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->php_version }}</span>
            </div>
        @endif
        @if($site->server_software)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Server software</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->server_software }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 dark:border-gray-700 pt-3">
        <a href="{{ route('sites.plugins', $site) }}" class="text-xs font-medium text-accent-600 hover:text-accent-800">View Updates</a>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">Unknown</p>
    <a href="{{ route('sites.overview', $site) }}" class="mt-2 inline-block text-xs font-medium text-accent-600 hover:text-accent-800">View Overview</a>
@endif
