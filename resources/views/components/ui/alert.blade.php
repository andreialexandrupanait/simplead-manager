@props(['type' => 'info'])

@php
$classes = match($type) {
    'info'    => 'bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:border-blue-500/30',
    'success' => 'bg-green-50 text-green-800 border-green-200 dark:bg-green-500/10 dark:text-green-300 dark:border-green-500/30',
    'warning' => 'bg-yellow-50 text-yellow-800 border-yellow-200 dark:bg-yellow-500/10 dark:text-yellow-300 dark:border-yellow-500/30',
    'error'   => 'bg-red-50 text-red-800 border-red-200 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/30',
};
@endphp

<div {{ $attributes->merge([
    'class' => "rounded-lg border px-4 py-3 text-sm {$classes}",
    'role' => 'alert',
]) }}>
    {{ $slot }}
</div>
