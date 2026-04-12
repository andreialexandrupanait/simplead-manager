@props(['site'])

@if($site->analyticsConnection && $site->analyticsConnection->is_active)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">Google Analytics</span>
        <x-ui.badge variant="green">Connected</x-ui.badge>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        @if($site->analyticsConnection->property_name)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Property</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->analyticsConnection->property_name }}</span>
            </div>
        @endif
        @if($site->analyticsConnection->measurement_id)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Measurement ID</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->analyticsConnection->measurement_id }}</span>
            </div>
        @endif
        @if($site->analyticsConnection->last_sync_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Last synced</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $site->analyticsConnection->last_sync_at->diffForHumans() }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 dark:border-gray-700 pt-3">
        <a href="{{ route('sites.analytics', $site) }}" class="text-xs font-medium text-accent-600 hover:text-accent-800">View Analytics</a>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">Analytics not connected</p>
    <a href="{{ route('sites.analytics', $site) }}" class="mt-2 inline-block text-xs font-medium text-accent-600 hover:text-accent-800">Connect Analytics</a>
@endif
