<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $type
 * @property string $slug
 * @property string|null $target
 * @property string $name
 * @property string $from_version
 * @property string $to_version
 * @property string $status
 * @property array|null $health_check_results
 * @property string|null $error_message
 * @property bool $auto_rollback
 * @property int|null $ai_risk_score
 * @property array|null $ai_risk_assessment
 * @property string|null $screenshot_before_path
 * @property string|null $screenshot_after_path
 * @property array|null $visual_regression_results
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class SafeUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'type',
        'slug',
        'target',
        'name',
        'from_version',
        'to_version',
        'status',
        'health_check_results',
        'error_message',
        'auto_rollback',
        'ai_risk_score',
        'ai_risk_assessment',
        'screenshot_before_path',
        'screenshot_after_path',
        'visual_regression_results',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'health_check_results' => 'array',
        'ai_risk_assessment' => 'array',
        'visual_regression_results' => 'array',
        'auto_rollback' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
