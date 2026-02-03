<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceTest extends Model
{
    protected $fillable = [
        'site_id',
        'performance_monitor_id',
        'performance_page_id',
        'device',
        'url',
        'performance_score',
        'accessibility_score',
        'best_practices_score',
        'seo_score',
        'fcp',
        'lcp',
        'cls',
        'tbt',
        'si',
        'tti',
        'field_fcp',
        'field_lcp',
        'field_cls',
        'field_inp',
        'field_ttfb',
        'total_requests',
        'total_size_bytes',
        'html_size',
        'css_size',
        'js_size',
        'image_size',
        'font_size',
        'opportunities',
        'diagnostics',
        'third_party_scripts',
        'dom_elements',
        'dom_max_depth',
        'dom_max_children',
        'unused_js_bytes',
        'unused_css_bytes',
        'unused_js_details',
        'unused_css_details',
        'image_audit',
        'wp_health_checks',
        'screenshot_final',
        'filmstrip',
        'status',
        'error_message',
        'lighthouse_version',
        'tested_at',
    ];

    protected $casts = [
        'opportunities' => 'array',
        'diagnostics' => 'array',
        'third_party_scripts' => 'array',
        'unused_js_details' => 'array',
        'unused_css_details' => 'array',
        'image_audit' => 'array',
        'wp_health_checks' => 'array',
        'filmstrip' => 'array',
        'tested_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(PerformanceMonitor::class, 'performance_monitor_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PerformancePage::class, 'performance_page_id');
    }

    public function getDomColorAttribute(): string
    {
        $count = $this->dom_elements;
        if ($count === null) {
            return 'gray';
        }
        if ($count < 800) {
            return 'green';
        }
        if ($count <= 1500) {
            return 'orange';
        }
        return 'red';
    }

    public function getScoreColorAttribute(): string
    {
        $score = $this->performance_score;
        if ($score === null) {
            return 'gray';
        }
        if ($score >= 90) {
            return 'green';
        }
        if ($score >= 50) {
            return 'orange';
        }
        return 'red';
    }

    public function getScoreLabelAttribute(): string
    {
        $score = $this->performance_score;
        if ($score === null) {
            return 'N/A';
        }
        if ($score >= 90) {
            return 'Good';
        }
        if ($score >= 50) {
            return 'Needs Improvement';
        }
        return 'Poor';
    }

    public function formatMetric(string $metric): string
    {
        $value = $this->$metric;
        if ($value === null) {
            return '—';
        }

        return match ($metric) {
            'fcp', 'lcp', 'si', 'tti', 'field_fcp', 'field_lcp' => round($value, 1) . ' s',
            'tbt', 'field_inp', 'field_ttfb' => round($value) . ' ms',
            'cls', 'field_cls' => number_format($value, 3),
            'total_size_bytes', 'html_size', 'css_size', 'js_size', 'image_size', 'font_size' => $this->formatBytes((int) $value),
            default => (string) $value,
        };
    }

    public function metricColor(string $metric): string
    {
        $value = $this->$metric;
        if ($value === null) {
            return 'gray';
        }

        return match ($metric) {
            'fcp', 'field_fcp' => $value <= 1.8 ? 'green' : ($value <= 3.0 ? 'orange' : 'red'),
            'lcp', 'field_lcp' => $value <= 2.5 ? 'green' : ($value <= 4.0 ? 'orange' : 'red'),
            'cls', 'field_cls' => $value <= 0.1 ? 'green' : ($value <= 0.25 ? 'orange' : 'red'),
            'tbt' => $value <= 200 ? 'green' : ($value <= 600 ? 'orange' : 'red'),
            'si' => $value <= 3.4 ? 'green' : ($value <= 5.8 ? 'orange' : 'red'),
            'tti' => $value <= 3.8 ? 'green' : ($value <= 7.3 ? 'orange' : 'red'),
            'field_inp' => $value <= 200 ? 'green' : ($value <= 500 ? 'orange' : 'red'),
            'field_ttfb' => $value <= 800 ? 'green' : ($value <= 1800 ? 'orange' : 'red'),
            default => 'gray',
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
