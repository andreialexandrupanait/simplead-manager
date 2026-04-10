<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $year
 * @property int $month
 * @property float|null $uptime_avg_response_ms
 * @property float|null $uptime_percentage
 * @property int|null $uptime_down_checks
 * @property int|null $uptime_incidents_count
 * @property int|null $backups_total
 * @property int|null $backups_successful
 * @property int|null $backups_failed
 * @property int|null $updates_applied
 * @property float|null $security_avg_score
 * @property float|null $performance_avg_desktop
 * @property float|null $performance_avg_mobile
 * @property int|null $analytics_users
 * @property int|null $analytics_sessions
 * @property int|null $analytics_pageviews
 * @property int|null $search_console_clicks
 * @property int|null $search_console_impressions
 * @property float|null $search_console_avg_position
 * @property int|null $cloudflare_requests
 * @property int|null $cloudflare_bandwidth_bytes
 * @property float|null $cloudflare_cache_hit_ratio
 * @property int|null $seo_score
 * @property int|null $seo_issues_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
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
        'seo_score',
        'seo_issues_count',
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
        'seo_score' => 'integer',
        'seo_issues_count' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
