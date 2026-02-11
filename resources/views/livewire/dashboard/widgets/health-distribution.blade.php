<x-dashboard.widget-container
    :title="$this->getTitle()"
    :widget-id="$widget->id"
    :loading="!$isLoaded"
    skeleton-type="chart"
    wire:init="loadWidget"
>
    @if($isLoaded && $this->data)
        <x-dashboard.chart-container chart-id="health-distribution-{{ $widget->id }}" height="250px">
            <x-slot name="script">
                <script>
                    document.addEventListener('livewire:navigated', function() {
                        initHealthDistributionChart{{ $widget->id }}();
                    });

                    document.addEventListener('DOMContentLoaded', function() {
                        initHealthDistributionChart{{ $widget->id }}();
                    });

                    function initHealthDistributionChart{{ $widget->id }}() {
                        const ctx = document.getElementById('health-distribution-{{ $widget->id }}');
                        if (!ctx) return;

                        // Destroy existing chart if it exists
                        if (window.healthChart{{ $widget->id }}) {
                            window.healthChart{{ $widget->id }}.destroy();
                        }

                        const totalSites = @js(array_sum($this->data['values']));

                        const data = {
                            labels: @js($this->data['labels']),
                            datasets: [{
                                data: @js($this->data['values']),
                                backgroundColor: @js($this->data['colors']),
                                borderWidth: 2,
                                borderColor: '#ffffff',
                            }]
                        };

                        window.healthChart{{ $widget->id }} = new Chart(ctx, {
                            type: 'doughnut',
                            data: data,
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        labels: {
                                            padding: 15,
                                            font: {
                                                size: 12
                                            },
                                            generateLabels: function(chart) {
                                                const data = chart.data;
                                                return data.labels.map((label, i) => ({
                                                    text: `${label}: ${data.datasets[0].data[i]}`,
                                                    fillStyle: data.datasets[0].backgroundColor[i],
                                                    hidden: false,
                                                    index: i
                                                }));
                                            }
                                        }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                const value = context.parsed;
                                                const percentage = totalSites > 0 ? ((value / totalSites) * 100).toFixed(1) : 0;
                                                return context.label + ': ' + value + ' (' + percentage + '%)';
                                            }
                                        }
                                    }
                                },
                                cutout: '60%'
                            },
                            plugins: [{
                                id: 'centerText',
                                beforeDraw: function(chart) {
                                    const width = chart.width;
                                    const height = chart.height;
                                    const ctx = chart.ctx;

                                    ctx.restore();
                                    const fontSize = (height / 114).toFixed(2);
                                    ctx.font = fontSize + 'em sans-serif';
                                    ctx.textBaseline = 'middle';

                                    const text = totalSites.toString();
                                    const textX = Math.round((width - ctx.measureText(text).width) / 2);
                                    const textY = height / 2;

                                    ctx.fillStyle = '#111827';
                                    ctx.fillText(text, textX, textY);

                                    ctx.font = (fontSize * 0.5) + 'em sans-serif';
                                    const subText = 'Total Sites';
                                    const subTextX = Math.round((width - ctx.measureText(subText).width) / 2);
                                    const subTextY = textY + (fontSize * 20);

                                    ctx.fillStyle = '#6b7280';
                                    ctx.fillText(subText, subTextX, subTextY);

                                    ctx.save();
                                }
                            }]
                        });
                    }
                </script>
            </x-slot>
        </x-dashboard.chart-container>

        {{-- Status Summary --}}
        <div class="mt-4 grid grid-cols-2 gap-2">
            @foreach($this->data['labels'] as $index => $label)
                <div class="flex items-center gap-2 rounded-lg bg-gray-50 p-2">
                    <span class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $this->data['colors'][$index] }}"></span>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $this->data['values'][$index] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-dashboard.widget-container>
