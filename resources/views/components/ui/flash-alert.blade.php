@props([
    'type' => 'success',
    'key' => null,
    'message' => null,
    'dismissible' => false,
])

@php
$msg = $message ?? ($key ? session($key) : null);
if (!$msg) return;

$classes = match($type) {
    'success' => 'bg-green-50 text-green-700',
    'error' => 'bg-red-50 text-red-700',
    'warning' => 'bg-yellow-50 text-yellow-700',
    'info' => 'bg-blue-50 text-blue-700',
    default => 'bg-gray-50 text-gray-700',
};
@endphp

@if($msg)
    <div {{ $attributes->merge(['class' => "mb-4 rounded-lg p-3 text-sm {$classes}"]) }}>
        {{ $msg }}
    </div>
@endif
