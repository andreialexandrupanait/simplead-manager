<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $score
 * @property int $uptime_score
 * @property int $security_score
 * @property int $updates_score
 * @property int $performance_score
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\Site $site
 */
class HealthScoreHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'score',
        'uptime_score',
        'security_score',
        'updates_score',
        'performance_score',
        'recorded_at',
        'created_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'uptime_score' => 'integer',
        'security_score' => 'integer',
        'updates_score' => 'integer',
        'performance_score' => 'integer',
        'recorded_at' => 'date',
        'created_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
