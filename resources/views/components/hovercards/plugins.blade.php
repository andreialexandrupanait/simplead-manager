@props(['site'])

@php $updates = $site->pending_updates_count ?? 0; @endphp

@if($updates > 0)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Pending Updates</span>
        <x-ui.badge :variant="$updates <= 5 ? 'orange' : 'red'">{{ $updates }}</x-ui.badge>
    </div>
    <div class="mt-2 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Total plugins</span>
            <span class="font-medium text-gray-900">{{ $site->site_plugins_count ?? 0 }}</span>
        </div>
    </div>
    <div class="mt-2 space-y-2">
        @if($site->core_update_version)
            <div class="flex items-center justify-between text-xs">
                <span class="font-medium text-gray-900">WordPress Core</span>
                <span class="text-gray-500">{{ $site->wp_version ?? '?' }} &rarr; {{ $site->core_update_version }}</span>
            </div>
        @endif
        @foreach($site->sitePlugins->take(5) as $plugin)
            <div class="flex items-center justify-between text-xs">
                <span class="truncate font-medium text-gray-700" title="{{ $plugin->name }}">{{ \Illuminate\Support\Str::limit($plugin->name, 25) }}</span>
                <span class="ml-2 flex-shrink-0 text-gray-500">{{ $plugin->version ?? '?' }} &rarr; {{ $plugin->update_version ?? '?' }}</span>
            </div>
        @endforeach
        @if($site->sitePlugins->count() > 5)
            <p class="text-xs text-gray-400">+{{ $site->sitePlugins->count() - 5 }} more plugins</p>
        @endif
        @foreach($site->siteThemes as $theme)
            <div class="flex items-center justify-between text-xs">
                <span class="truncate font-medium text-gray-700" title="{{ $theme->name }}">{{ \Illuminate\Support\Str::limit($theme->name, 25) }} <span class="text-gray-400">(theme)</span></span>
                <span class="ml-2 flex-shrink-0 text-gray-500">{{ $theme->version ?? '?' }} &rarr; {{ $theme->update_version ?? '?' }}</span>
            </div>
        @endforeach
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.plugins', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View All Updates</a>
    </div>
@else
    <div class="flex items-center gap-2">
        <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <span class="text-sm text-gray-600">All up to date</span>
    </div>
    <div class="mt-2 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Total plugins</span>
            <span class="font-medium text-gray-900">{{ $site->site_plugins_count ?? 0 }}</span>
        </div>
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.plugins', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Plugins</a>
    </div>
@endif
