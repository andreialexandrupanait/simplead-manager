@props([
    'data' => [],
    'color' => '#8D5CF5',
    'height' => 36,
])

<div
    x-data="{
        chart: null,
        data: @js($data),
        color: @js($color),
        init() { this.$nextTick(() => this.render()); },
        render() {
            if (this.chart) this.chart.destroy();
            this.chart = new Chart(this.$refs.spark, {
                type: 'line',
                data: {
                    labels: this.data.map((_, i) => i),
                    datasets: [{
                        data: this.data,
                        borderColor: this.color,
                        borderWidth: 1.5,
                        fill: false,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: { x: { display: false }, y: { display: false } },
                    elements: { line: { borderWidth: 1.5 } },
                },
            });
        },
    }"
    style="height: {{ $height }}px"
    class="mt-2"
>
    <canvas x-ref="spark"></canvas>
</div>
