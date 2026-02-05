@props(['site'])

@if($site->sslCertificate)
    @php
        $cert = $site->sslCertificate;
        $sslBadge = match($cert->status) {
            'valid' => 'bg-green-100 text-green-700',
            'expiring_soon' => 'bg-yellow-100 text-yellow-700',
            default => 'bg-red-100 text-red-700',
        };
        $sslLabel = match($cert->status) {
            'valid' => 'Valid',
            'expiring_soon' => 'Expiring',
            default => 'Expired',
        };
    @endphp
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">SSL Certificate</span>
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $sslBadge }}">{{ $sslLabel }}</span>
    </div>
    <div class="mt-3 space-y-1.5 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Domain</span>
            <span class="font-medium text-gray-900">{{ $site->domain }}</span>
        </div>
        @if($cert->issuer)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Issuer</span>
                <span class="font-medium text-gray-900">{{ $cert->issuer }}</span>
            </div>
        @endif
        @if($cert->issued_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Issued</span>
                <span class="font-medium text-gray-900">{{ $cert->issued_at->format('M j, Y') }}</span>
            </div>
        @endif
        @if($cert->expires_at)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Expires</span>
                <span class="font-medium text-gray-900">{{ $cert->expires_at->format('M j, Y') }}</span>
            </div>
        @endif
        @if($cert->days_remaining !== null)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Days remaining</span>
                <span class="font-medium text-gray-900">{{ $cert->days_remaining }}</span>
            </div>
        @endif
        @if($cert->key_size)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Key size</span>
                <span class="font-medium text-gray-900">{{ $cert->key_size }} bit</span>
            </div>
        @endif
        @if($cert->protocol)
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Protocol</span>
                <span class="font-medium text-gray-900">{{ $cert->protocol }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('sites.security', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Security</a>
    </div>
@else
    <p class="text-sm text-gray-500">No certificate</p>
    <a href="{{ route('sites.security', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">View Security</a>
@endif
