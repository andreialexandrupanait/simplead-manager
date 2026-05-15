@props([
    'labels' => [],
    'data' => [],
    'color' => '#7B68EE',
    'height' => '300px',
    'horizontal' => true,
])

<div
    x-data="{
        chart: null,
        labels: @js($labels),
        data: @js($data),
        color: @js($color),
        horizontal: @js($horizontal),
        async init() {
            await this.renderChart();
            this.$watch('data', () => this.renderChart());
            this.$watch('labels', () => this.renderChart());
        },
        async renderChart() {
            const Chart = await window.loadChart();
            if (this.chart) {
                this.chart.destroy();
            }

            this.chart = new Chart(this.$refs.canvas, {
                type: 'bar',
                data: {
                    labels: this.labels,
                    datasets: [{
                        data: this.data,
                        backgroundColor: this.color + '33',
                        borderColor: this.color,
                        borderWidth: 1,
                        borderRadius: 4,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: this.horizontal ? 'y' : 'x',
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        x: {
                            grid: { color: document.documentElement.classList.contains('dark') ? '#374151' : '#f3f4f6' },
                            ticks: { color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280' },
                            beginAtZero: true,
                        },
                        y: {
                            grid: { display: false },
                            ticks: { color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280' },
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
