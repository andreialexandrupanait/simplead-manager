<?php

namespace App\Services;

class ReportChartService
{
    /**
     * Generate SVG polyline points for a line chart.
     *
     * @param  array  $values  Array of numeric values
     * @param  int  $width  Chart width in SVG units
     * @param  int  $height  Chart height in SVG units
     * @param  int  $paddingLeft  Left padding for Y-axis labels
     * @param  int  $paddingBottom  Bottom padding for X-axis labels
     * @return array ['line_points' => string, 'area_points' => string, 'y_max' => float]
     */
    public function generateLineChartPoints(
        array $values,
        int $width = 500,
        int $height = 180,
        int $paddingLeft = 40,
        int $paddingBottom = 25,
        ?float $forceYMax = null
    ): array {
        if (empty($values)) {
            return ['line_points' => '', 'area_points' => '', 'smooth_line_path' => '', 'smooth_area_path' => '', 'y_max' => 0];
        }

        $chartWidth = $width - $paddingLeft - 10;
        $chartHeight = $height - $paddingBottom - 10;
        $yMax = $forceYMax ?? (max($values) ?: 1);
        $count = count($values);
        $xStep = $count > 1 ? $chartWidth / ($count - 1) : $chartWidth;

        $points = [];
        foreach ($values as $i => $value) {
            $x = $paddingLeft + ($i * $xStep);
            $y = 5 + $chartHeight - (($value / $yMax) * $chartHeight);
            $points[] = round($x, 1) . ',' . round($y, 1);
        }

        $linePoints = implode(' ', $points);

        $firstX = round($paddingLeft, 1);
        $lastX = round($paddingLeft + (($count - 1) * $xStep), 1);
        $bottomY = round(5 + $chartHeight, 1);
        $areaPoints = $linePoints . " {$lastX},{$bottomY} {$firstX},{$bottomY}";

        // Generate smooth Bézier path
        $smoothLinePath = $this->generateSmoothLinePath($values, $paddingLeft, $xStep, $chartHeight, $yMax);
        $smoothAreaPath = $smoothLinePath
            . " L {$lastX},{$bottomY} L {$firstX},{$bottomY} Z";

        return [
            'line_points' => $linePoints,
            'area_points' => $areaPoints,
            'smooth_line_path' => $smoothLinePath,
            'smooth_area_path' => $smoothAreaPath,
            'y_max' => $yMax,
        ];
    }

    /**
     * Generate a smooth SVG path using Catmull-Rom to cubic Bézier conversion.
     */
    protected function generateSmoothLinePath(
        array $values,
        int $paddingLeft,
        float $xStep,
        float $chartHeight,
        float $yMax
    ): string {
        $coords = [];
        foreach ($values as $i => $value) {
            $x = $paddingLeft + ($i * $xStep);
            $y = 5 + $chartHeight - (($value / $yMax) * $chartHeight);
            $coords[] = ['x' => round($x, 2), 'y' => round($y, 2)];
        }

        $count = count($coords);
        if ($count < 2) {
            return "M {$coords[0]['x']},{$coords[0]['y']}";
        }

        $path = "M {$coords[0]['x']},{$coords[0]['y']}";

        // Tension controls curve smoothness (0.3 = moderate)
        $tension = 0.3;

        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $coords[max(0, $i - 1)];
            $p1 = $coords[$i];
            $p2 = $coords[$i + 1];
            $p3 = $coords[min($count - 1, $i + 2)];

            // Catmull-Rom to Bézier control points
            $cp1x = round($p1['x'] + ($p2['x'] - $p0['x']) * $tension, 2);
            $cp1y = round($p1['y'] + ($p2['y'] - $p0['y']) * $tension, 2);
            $cp2x = round($p2['x'] - ($p3['x'] - $p1['x']) * $tension, 2);
            $cp2y = round($p2['y'] - ($p3['y'] - $p1['y']) * $tension, 2);

            $path .= " C {$cp1x},{$cp1y} {$cp2x},{$cp2y} {$p2['x']},{$p2['y']}";
        }

        return $path;
    }

    /**
     * Generate Y-axis labels for a chart.
     */
    public function generateYLabels(float $yMax, int $steps = 3, string $suffix = ''): array
    {
        $labels = [];
        for ($i = 0; $i < $steps; $i++) {
            $value = $yMax - ($i * ($yMax / ($steps - 1)));
            $labels[] = $this->formatCompact($value) . $suffix;
        }

        return $labels;
    }

    /**
     * Pick evenly-spaced date labels for the X-axis.
     *
     * @param  array  $dates  Array of date strings (e.g. ['2026-01-01', ...])
     * @param  int  $maxLabels  Maximum number of labels to show
     * @return array  Array of ['index' => int, 'label' => string]
     */
    public function generateXLabels(array $dates, int $maxLabels = 5): array
    {
        $count = count($dates);
        if ($count === 0) {
            return [];
        }

        if ($count <= $maxLabels) {
            return array_map(fn ($i) => [
                'index' => $i,
                'label' => date('d M', strtotime($dates[$i])),
            ], range(0, $count - 1));
        }

        $labels = [];
        for ($i = 0; $i < $maxLabels; $i++) {
            $index = (int) round($i * ($count - 1) / ($maxLabels - 1));
            $labels[] = [
                'index' => $index,
                'label' => date('d M', strtotime($dates[$index])),
            ];
        }

        return $labels;
    }

    /**
     * Generate data for a vertical bar chart.
     *
     * @param  array  $bars  [['value' => float, 'label' => string, 'color' => string], ...]
     * @param  int  $width  Chart width
     * @param  int  $height  Chart height
     * @param  int  $paddingLeft  Left padding for Y-axis labels
     * @param  int  $paddingBottom  Bottom padding for X-axis labels
     * @return array
     */
    public function generateBarChartData(
        array $bars,
        int $width = 500,
        int $height = 180,
        int $paddingLeft = 40,
        int $paddingBottom = 30
    ): array {
        if (empty($bars)) {
            return ['bars' => [], 'y_labels' => [], 'y_max' => 0, 'chart_area' => []];
        }

        $count = count($bars);
        $chartW = $width - $paddingLeft - 10;
        $chartH = $height - $paddingBottom - 10;
        $gap = 12;
        $barWidth = ($chartW - $gap * ($count + 1)) / $count;
        $barWidth = min($barWidth, 60); // cap width

        $values = array_column($bars, 'value');
        $yMax = max($values) ?: 1;

        $enriched = [];
        foreach ($bars as $i => $bar) {
            $barH = ($bar['value'] / $yMax) * $chartH;
            $x = $paddingLeft + $gap + $i * ($barWidth + $gap);
            $y = 5 + $chartH - $barH;

            $enriched[] = array_merge($bar, [
                'x' => round($x, 1),
                'y' => round($y, 1),
                'bar_width' => round($barWidth, 1),
                'bar_height' => round($barH, 1),
            ]);
        }

        return [
            'bars' => $enriched,
            'y_labels' => $this->generateYLabels($yMax, 3),
            'y_max' => $yMax,
            'chart_area' => [
                'x' => $paddingLeft,
                'y' => 5,
                'width' => $chartW,
                'height' => $chartH,
                'bottom' => 5 + $chartH,
            ],
            'svg_width' => $width,
            'svg_height' => $height,
        ];
    }

    /**
     * Generate data for a horizontal bar chart.
     *
     * @param  array  $bars  [['value' => float, 'label' => string, 'color' => string], ...]
     * @param  int  $width  Chart width
     * @param  int  $labelWidth  Width reserved for labels on the left
     * @return array
     */
    public function generateHorizontalBarData(
        array $bars,
        int $width = 500,
        int $labelWidth = 120
    ): array {
        if (empty($bars)) {
            return ['bars' => [], 'svg_width' => $width, 'svg_height' => 0];
        }

        $barHeight = 24;
        $gap = 10;
        $count = count($bars);
        $svgHeight = $count * ($barHeight + $gap) + $gap;
        $maxBarW = $width - $labelWidth - 50; // 50px for value text

        $values = array_column($bars, 'value');
        $vMax = max($values) ?: 1;

        $enriched = [];
        foreach ($bars as $i => $bar) {
            $barW = ($bar['value'] / $vMax) * $maxBarW;
            $y = $gap + $i * ($barHeight + $gap);

            $enriched[] = array_merge($bar, [
                'x' => $labelWidth,
                'y' => round($y, 1),
                'bar_width' => round(max($barW, 2), 1),
                'bar_height' => $barHeight,
                'text_y' => round($y + $barHeight / 2 + 4, 1),
            ]);
        }

        return [
            'bars' => $enriched,
            'svg_width' => $width,
            'svg_height' => $svgHeight,
            'label_width' => $labelWidth,
        ];
    }

    /**
     * Generate data for a donut/ring chart using stroke-dasharray technique.
     *
     * @param  array  $segments  [['value' => float, 'label' => string, 'color' => string], ...]
     * @param  int  $size  SVG size (width & height)
     * @param  int  $strokeWidth  Ring thickness
     * @return array
     */
    public function generateDonutData(
        array $segments,
        int $size = 150,
        int $strokeWidth = 20
    ): array {
        $total = array_sum(array_column($segments, 'value'));
        if ($total <= 0) {
            return ['segments' => [], 'total' => 0, 'circumference' => 0, 'radius' => 0, 'cx' => 0, 'cy' => 0, 'size' => $size, 'stroke_width' => $strokeWidth];
        }

        $radius = ($size / 2) - ($strokeWidth / 2) - 2;
        $cx = $size / 2;
        $cy = $size / 2;
        $circumference = 2 * M_PI * $radius;

        $cumulativeOffset = 0;
        $enriched = [];

        foreach ($segments as $seg) {
            $pct = $seg['value'] / $total;
            $segLen = $pct * $circumference;

            $enriched[] = array_merge($seg, [
                'percentage' => round($pct * 100, 1),
                'dash_array' => round($segLen, 2) . ', ' . round($circumference - $segLen, 2),
                'dash_offset' => round(-$cumulativeOffset, 2),
            ]);

            $cumulativeOffset += $segLen;
        }

        return [
            'segments' => $enriched,
            'total' => $total,
            'circumference' => round($circumference, 2),
            'radius' => round($radius, 2),
            'cx' => $cx,
            'cy' => $cy,
            'size' => $size,
            'stroke_width' => $strokeWidth,
        ];
    }

    /**
     * Generate data for a dual-line overlay chart.
     *
     * @param  array  $values1  First data series
     * @param  array  $values2  Second data series
     * @return array ['line1' => [...], 'line2' => [...], 'y_max' => float]
     */
    public function generateDualLineChartPoints(
        array $values1,
        array $values2,
        int $width = 500,
        int $height = 180,
        int $paddingLeft = 40,
        int $paddingBottom = 25
    ): array {
        if (empty($values1) && empty($values2)) {
            return ['line1' => ['line_points' => '', 'area_points' => '', 'y_max' => 0], 'line2' => ['line_points' => '', 'area_points' => '', 'y_max' => 0], 'y_max' => 0];
        }

        $max1 = ! empty($values1) ? max($values1) : 0;
        $max2 = ! empty($values2) ? max($values2) : 0;
        $sharedYMax = max($max1, $max2) ?: 1;

        $line1 = $this->generateLineChartPoints($values1, $width, $height, $paddingLeft, $paddingBottom, $sharedYMax);
        $line2 = $this->generateLineChartPoints($values2, $width, $height, $paddingLeft, $paddingBottom, $sharedYMax);

        return [
            'line1' => $line1,
            'line2' => $line2,
            'y_max' => $sharedYMax,
        ];
    }

    protected function formatCompact(float $value): string
    {
        if ($value >= 1000000) {
            return round($value / 1000000, 1) . 'M';
        }
        if ($value >= 1000) {
            return round($value / 1000, 1) . 'K';
        }

        return (string) round($value);
    }
}
