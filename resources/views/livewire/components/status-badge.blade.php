@php
$dotColor = match($this->variant) {
    'green'  => 'bg-green-500',
    'red'    => 'bg-red-500',
    'yellow' => 'bg-yellow-500',
    'purple' => 'bg-purple-500',
    default  => 'bg-gray-400',
};

$badgeClasses = match($this->variant) {
    'green'  => 'bg-green-100 text-green-700',
    'red'    => 'bg-red-100 text-red-700',
    'yellow' => 'bg-yellow-100 text-yellow-700',
    'purple' => 'bg-purple-100 text-purple-700',
    default  => 'bg-gray-100 text-gray-700',
};
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badgeClasses }}">
    <span class="h-1.5 w-1.5 rounded-full {{ $dotColor }}"></span>
    {{ $this->label }}
</span>
