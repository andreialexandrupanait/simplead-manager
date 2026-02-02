@props([
    'labels' => [],
    'datasets' => [],
    'height' => '300px',
])

<div
    x-data="{
        chart: null,
        labels: @js($labels),
        datasets: @js($datasets),
        init() {
            this.renderChart();
            this.$watch('labels', () => this.renderChart());
            this.$watch('datasets', () => this.renderChart());
        },
        renderChart() {
            if (this.chart) {
                this.chart.destroy();
            }
            this.chart = new Chart(this.$refs.canvas, {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: this.datasets.map(ds => ({
                        label: ds.label,
                        data: ds.data,
                        borderColor: ds.color || '#8D5CF5',
                        backgroundColor: (ds.color || '#8D5CF5') + '1A',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                    })),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: this.datasets.length > 1,
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 16 },
                        },
                    },
                    scales: {
                        x: {
                            grid: { color: '#f3f4f6' },
                            ticks: { color: '#6b7280' },
                        },
                        y: {
                            grid: { color: '#f3f4f6' },
                            ticks: { color: '#6b7280' },
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
