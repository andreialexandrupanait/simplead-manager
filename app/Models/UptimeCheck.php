<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $monitor_id
 * @property bool $is_up
 * @property int|null $response_time
 * @property int|null $status_code
 * @property string|null $failure_reason
 * @property bool|null $keyword_found
 * @property \Illuminate\Support\Carbon|null $ssl_expires_at
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property-read UptimeMonitor|null $monitor
 */
class UptimeCheck extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'is_up',
        'response_time',
        'status_code',
        'failure_reason',
        'keyword_found',
        'ssl_expires_at',
        'checked_at',
    ];

    protected $casts = [
        'is_up' => 'boolean',
        'keyword_found' => 'boolean',
        'checked_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
        'response_time' => 'integer',
        'status_code' => 'integer',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForMonitorSince(Builder $query, int $monitorId, \DateTimeInterface $since): Builder
    {
        return $query->where('monitor_id', $monitorId)->where('checked_at', '>=', $since);
    }
}
