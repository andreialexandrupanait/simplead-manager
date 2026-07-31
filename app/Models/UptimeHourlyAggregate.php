<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per monitor per hour, kept 13 months (SPEC §14.1).
 *
 * Raw pings live 14 days; everything longer than that is answered from here.
 *
 * @property int $id
 * @property int $monitor_id
 * @property int|null $site_id
 * @property \Illuminate\Support\Carbon $bucket_hour
 * @property int $checks_total
 * @property int $checks_up
 * @property float|null $uptime_pct
 * @property int|null $avg_response_time
 * @property int|null $p95_response_time
 * @property int|null $max_response_time
 */
class UptimeHourlyAggregate extends Model
{
    protected $fillable = [
        'monitor_id',
        'site_id',
        'bucket_hour',
        'checks_total',
        'checks_up',
        'uptime_pct',
        'avg_response_time',
        'p95_response_time',
        'max_response_time',
    ];

    protected $casts = [
        'bucket_hour' => 'datetime',
        'checks_total' => 'integer',
        'checks_up' => 'integer',
        'uptime_pct' => 'float',
        'avg_response_time' => 'integer',
        'p95_response_time' => 'integer',
        'max_response_time' => 'integer',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
