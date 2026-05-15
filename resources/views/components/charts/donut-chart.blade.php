@props([
    'labels' => [],
    'data' => [],
    'colors' => ['#22c55e', '#eab308', '#ef4444', '#9ca3af', '#7B68EE'],
    'height' => '300px',
    'centerText' => null,
])

<div
    x-data="{
        chart: null,
        labels: @js($labels),
        data: @js($data),
        colors: @js($colors),
        centerText: @js($centerText),
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

            const centerText = this.centerText;
            const centerPlugin = centerText ? {
                id: 'centerText',
                afterDraw(chart) {
                    const { ctx, chartArea: { top, bottom, left, right } } = chart;
                    const centerX = (left + right) / 2;
                    const centerY = (top + bottom) / 2;
                    ctx.save();
                    ctx.font = 'bold 20px sans-serif';
                    ctx.fillStyle = document.documentElement.classList.contains('dark') ? '#f3f4f6' : '#1f2937';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(centerText, centerX, centerY);
                    ctx.restore();
                },
            } : null;

            this.chart = new Chart(this.$refs.canvas, {
                type: 'doughnut',
                data: {
                    labels: this.labels,
                    datasets: [{
                        data: this.data,
                        backgroundColor: this.colors,
                        borderWidth: 2,
                        borderColor: document.documentElement.classList.contains('dark') ? '#1f2937' : '#ffffff',
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 16 },
                        },
                    },
                },
                plugins: centerPlugin ? [centerPlugin] : [],
            });
        },
    }"
    style="height: {{ $height }}"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    <canvas x-ref="canvas"></canvas>
</div>
