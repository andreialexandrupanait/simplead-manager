<x-ui.card>
    <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ $label }}</h4>
    <div class="mt-2 text-2xl font-bold text-gray-900">{{ $this->stats['uptime'] }}</div>
    <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
        <span>{{ $this->stats['incidents'] }} {{ Str::plural('incident', $this->stats['incidents']) }}</span>
        <span>{{ $this->stats['downtime'] }} downtime</span>
    </div>
</x-ui.card>
