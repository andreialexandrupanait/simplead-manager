<x-ui.card>
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-medium text-gray-900">Response Time</h3>
        <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1">
            @foreach(['1h', '24h', '7d', '30d'] as $option)
                <button
                    wire:click="setRange('{{ $option }}')"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition {{ $range === $option ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
                >
                    {{ $option }}
                </button>
            @endforeach
        </div>
    </div>

    @if(count($this->chartData['labels']) > 0)
        <x-charts.line-chart
            wire:key="chart-{{ $range }}"
            :labels="$this->chartData['labels']"
            :datasets="[['label' => 'Response Time (ms)', 'data' => $this->chartData['data'], 'color' => '#7B68EE']]"
            height="250px"
        />

        <div class="mt-4 grid grid-cols-4 gap-4 border-t pt-4">
            <div>
                <p class="text-xs text-gray-500">Average</p>
                <p class="text-sm font-semibold text-gray-900">{{ $this->responseStats['avg'] }}ms</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Min</p>
                <p class="text-sm font-semibold text-gray-900">{{ $this->responseStats['min'] }}ms</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Max</p>
                <p class="text-sm font-semibold text-gray-900">{{ $this->responseStats['max'] }}ms</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">P95</p>
                <p class="text-sm font-semibold text-gray-900">{{ $this->responseStats['p95'] }}ms</p>
            </div>
        </div>
    @else
        <div class="flex items-center justify-center py-12 text-sm text-gray-400">
            No response time data available for this period.
        </div>
    @endif
</x-ui.card>
