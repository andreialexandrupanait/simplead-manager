@props(['site'])

@if($site->linkMonitor)
    @php
        $broken = $site->linkMonitor->broken_links ?? 0;
        $linksBadge = $broken === 0 ? 'bg-green-100 text-green-700' : ($broken <= 5 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700');
    @endphp
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Link Monitor</span>
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $linksBadge }}">{{ $broken }} broken</span>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        @if($site->linkMonitor->total_links !== null)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Total links</span>
                <span class="font-medium text-gray-900">{{ $site->linkMonitor->total_links }}</span>
            </div>
        @endif
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Broken links</span>
            <span class="font-medium text-gray-900">{{ $broken }}</span>
        </div>
        @if($site->linkMonitor->redirects !== null)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Redirects</span>
                <span class="font-medium text-gray-900">{{ $site->linkMonitor->redirects }}</span>
            </div>
        @endif
        @if($site->linkMonitor->pages_scanned !== null)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Pages scanned</span>
                <span class="font-medium text-gray-900">{{ $site->linkMonitor->pages_scanned }}</span>
            </div>
        @endif
        @if($site->linkMonitor->scan_frequency)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Scan frequency</span>
                <span class="font-medium text-gray-900">{{ ucfirst($site->linkMonitor->scan_frequency) }}</span>
            </div>
        @endif
        @if($site->linkMonitor->last_scan_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Last scan</span>
                <span class="font-medium text-gray-900">{{ $site->linkMonitor->last_scan_at->diffForHumans() }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.links', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Links</a>
    </div>
@else
    <p class="text-sm text-gray-500">No scan</p>
    <a href="{{ route('sites.links', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">View Links</a>
@endif
