@props([
    'status' => null,
    'size' => 'sm',
])

@php
    $colorMap = [
        // Health / general
        'healthy' => 'bg-green-100 text-green-800',
        'warning' => 'bg-yellow-100 text-yellow-800',
        'critical' => 'bg-red-100 text-red-800',
        'unknown' => 'bg-gray-100 text-gray-800',

        // Operational
        'up' => 'bg-green-100 text-green-800',
        'down' => 'bg-red-100 text-red-800',
        'degraded' => 'bg-yellow-100 text-yellow-800',
        'active' => 'bg-green-100 text-green-800',
        'paused' => 'bg-gray-100 text-gray-800',

        // Task / process
        'pending' => 'bg-yellow-100 text-yellow-800',
        'in_progress' => 'bg-accent-100 text-accent-800',
        'generating' => 'bg-accent-100 text-accent-800',
        'completed' => 'bg-green-100 text-green-800',
        'failed' => 'bg-red-100 text-red-800',

        // SSL / domain
        'valid' => 'bg-green-100 text-green-800',
        'expiring_soon' => 'bg-yellow-100 text-yellow-800',
        'expired' => 'bg-red-100 text-red-800',
        'error' => 'bg-red-100 text-red-800',

        // Incident
        'investigating' => 'bg-red-100 text-red-800',
        'identified' => 'bg-yellow-100 text-yellow-800',
        'monitoring' => 'bg-blue-100 text-blue-800',
        'resolved' => 'bg-green-100 text-green-800',

        // Security
        'passed' => 'bg-green-100 text-green-800',
        'clean' => 'bg-green-100 text-green-800',
        'modified' => 'bg-yellow-100 text-yellow-800',

        // Severity
        'minor' => 'bg-yellow-100 text-yellow-800',
        'major' => 'bg-orange-100 text-orange-800',
    ];

    $colors = $colorMap[$status] ?? 'bg-gray-100 text-gray-800';
    $label = str_replace('_', ' ', ucfirst($status ?? 'unknown'));

    $sizeClasses = match($size) {
        'xs' => 'px-1.5 py-0.5 text-[10px]',
        'sm' => 'px-2 py-0.5 text-xs',
        'md' => 'px-2.5 py-1 text-sm',
        default => 'px-2 py-0.5 text-xs',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full font-medium {$colors} {$sizeClasses}"]) }}>
    {{ $slot->isEmpty() ? $label : $slot }}
</span>
