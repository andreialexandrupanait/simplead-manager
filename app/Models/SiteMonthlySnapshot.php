<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMonthlySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'year',
        'month',
        'uptime_avg_response_ms',
        'uptime_percentage',
        'uptime_down_checks',
        'uptime_incidents_count',
        'backups_total',
        'backups_successful',
        'backups_failed',
        'updates_applied',
        'security_avg_score',
        'performance_avg_desktop',
        'performance_avg_mobile',
        'analytics_users',
        'analytics_sessions',
        'analytics_pageviews',
        'search_console_clicks',
        'search_console_impressions',
        'search_console_avg_position',
        'cloudflare_requests',
        'cloudflare_bandwidth_bytes',
        'cloudflare_cache_hit_ratio',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'uptime_avg_response_ms' => 'decimal:2',
        'uptime_percentage' => 'decimal:3',
        'uptime_down_checks' => 'integer',
        'uptime_incidents_count' => 'integer',
        'backups_total' => 'integer',
        'backups_successful' => 'integer',
        'backups_failed' => 'integer',
        'updates_applied' => 'integer',
        'security_avg_score' => 'decimal:2',
        'performance_avg_desktop' => 'decimal:2',
        'performance_avg_mobile' => 'decimal:2',
        'analytics_users' => 'integer',
        'analytics_sessions' => 'integer',
        'analytics_pageviews' => 'integer',
        'search_console_clicks' => 'integer',
        'search_console_impressions' => 'integer',
        'search_console_avg_position' => 'decimal:2',
        'cloudflare_requests' => 'integer',
        'cloudflare_bandwidth_bytes' => 'integer',
        'cloudflare_cache_hit_ratio' => 'decimal:2',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
