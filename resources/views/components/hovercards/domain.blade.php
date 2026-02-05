@props(['site'])

@if($site->domainMonitor && $site->domainMonitor->expires_at)
    @php
        $daysLeft = (int) now()->diffInDays($site->domainMonitor->expires_at, false);
        $domainBadge = $daysLeft < 0 ? 'bg-red-100 text-red-700' : ($daysLeft <= 30 ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700');
        $domainLabel = $daysLeft < 0 ? 'Expired' : ($daysLeft <= 30 ? 'Expiring' : 'OK');
    @endphp
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Domain</span>
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $domainBadge }}">{{ $domainLabel }}</span>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        @if($site->domainMonitor->registrar)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Registrar</span>
                <span class="font-medium text-gray-900">{{ $site->domainMonitor->registrar }}</span>
            </div>
        @endif
        @if($site->domainMonitor->registered_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Registered</span>
                <span class="font-medium text-gray-900">{{ $site->domainMonitor->registered_at->format('M j, Y') }}</span>
            </div>
        @endif
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Expires</span>
            <span class="font-medium text-gray-900">{{ $site->domainMonitor->expires_at->format('M j, Y') }}</span>
        </div>
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Days remaining</span>
            <span class="font-medium text-gray-900">{{ $daysLeft }}</span>
        </div>
        @if($site->domainMonitor->dns_provider)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">DNS provider</span>
                <span class="font-medium text-gray-900">{{ $site->domainMonitor->dns_provider }}</span>
            </div>
        @endif
        @if($site->domainMonitor->nameservers)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Nameservers</span>
                <span class="truncate font-medium text-gray-900 ml-2">{{ is_array($site->domainMonitor->nameservers) ? implode(', ', $site->domainMonitor->nameservers) : $site->domainMonitor->nameservers }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.security', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Security</a>
    </div>
@else
    <p class="text-sm text-gray-500">No monitor</p>
    <a href="{{ route('sites.security', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">View Security</a>
@endif
