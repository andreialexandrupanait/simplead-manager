@props(['site'])

@php $usersCount = $site->site_users_count ?? 0; @endphp

@if($usersCount > 0)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">WordPress Users</span>
        <x-ui.badge variant="blue">{{ $usersCount }}</x-ui.badge>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Total users</span>
            <span class="font-medium text-gray-900">{{ $usersCount }}</span>
        </div>
        @if($site->users_last_synced_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Last synced</span>
                <span class="font-medium text-gray-900">{{ $site->users_last_synced_at->diffForHumans() }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.settings', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Settings</a>
    </div>
@else
    <p class="text-sm text-gray-500">No users synced</p>
    <a href="{{ route('sites.settings', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">View Settings</a>
@endif
