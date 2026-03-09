@props(['currentPeriod' => '30d', 'periods' => ['7d' => '7 Days', '30d' => '30 Days', '90d' => '90 Days']])

<div class="inline-flex rounded-lg border border-gray-200 bg-white p-1">
    @foreach($periods as $value => $label)
        <button
            wire:click="setPeriod('{{ $value }}')"
            class="rounded-md px-3 py-1.5 text-xs font-medium transition {{ $currentPeriod === $value ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}"
        >
            {{ $label }}
        </button>
    @endforeach
</div>
