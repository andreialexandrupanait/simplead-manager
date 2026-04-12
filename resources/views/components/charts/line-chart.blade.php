@props([
    'labels' => [],
    'datasets' => [],
    'annotations' => [],
    'height' => '300px',
])

<div
    x-data="{
        chart: null,
        labels: @js($labels),
        datasets: @js($datasets),
        annotations: @js($annotations),
        async init() {
            await this.renderChart();
            this.$watch('labels', () => this.renderChart());
            this.$watch('datasets', () => this.renderChart());
            this.$watch('annotations', () => this.renderChart());
        },
        async renderChart() {
            const Chart = await window.loadChart();
            if (this.chart) {
                this.chart.destroy();
            }

            let chartDatasets = this.datasets.map(ds => ({
                label: ds.label,
                data: ds.data,
                borderColor: ds.color || '#8D5CF5',
                backgroundColor: (ds.color || '#8D5CF5') + '1A',
                borderWidth: 2,
                fill: ds.pointRadius !== undefined ? false : true,
                tension: 0.3,
                pointRadius: ds.pointRadius !== undefined ? ds.pointRadius : 3,
                pointHoverRadius: ds.pointRadius !== undefined ? ds.pointRadius : 5,
                borderDash: ds.borderDash || [],
            }));

            // Add annotation markers as a scatter dataset
            if (this.annotations && this.annotations.length > 0) {
                let annotationData = [];
                this.annotations.forEach(ann => {
                    let idx = this.labels.indexOf(ann.date);
                    if (idx !== -1) {
                        annotationData.push({
                            x: idx,
                            y: 100,
                            label: ann.label,
                            type: ann.type,
                        });
                    }
                });

                if (annotationData.length > 0) {
                    chartDatasets.push({
                        label: 'Events',
                        data: annotationData.map(d => ({ x: d.x, y: d.y })),
                        type: 'scatter',
                        pointStyle: 'triangle',
                        pointRadius: 8,
                        pointHoverRadius: 10,
                        backgroundColor: annotationData.map(d => d.type === 'success' ? '#10B981' : '#EF4444'),
                        borderColor: annotationData.map(d => d.type === 'success' ? '#10B981' : '#EF4444'),
                        showLine: false,
                        _annotationLabels: annotationData.map(d => d.label),
                    });
                }
            }

            this.chart = new Chart(this.$refs.canvas, {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: chartDatasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: this.datasets.length > 1,
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 16,
                                filter: function(item) {
                                    return item.text !== 'Events';
                                }
                            },
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    if (context.dataset._annotationLabels) {
                                        return context.dataset._annotationLabels[context.dataIndex] || '';
                                    }
                                    return context.dataset.label + ': ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: document.documentElement.classList.contains('dark') ? '#374151' : '#f3f4f6' },
                            ticks: { color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280' },
                        },
                        y: {
                            grid: { color: document.documentElement.classList.contains('dark') ? '#374151' : '#f3f4f6' },
                            ticks: { color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280' },
                            beginAtZero: true,
                        },
                    },
                },
            });
        },
    }"
    style="height: {{ $height }}"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    <canvas x-ref="canvas"></canvas>
</div>
