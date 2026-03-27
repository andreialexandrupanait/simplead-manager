<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $maintenance_plan_id
 * @property string $module_key
 * @property bool $is_enabled
 * @property int|null $interval_minutes
 * @property array|null $config
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MaintenancePlan|null $plan
 */
class MaintenancePlanModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_plan_id',
        'module_key',
        'is_enabled',
        'interval_minutes',
        'config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'interval_minutes' => 'integer',
        'config' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }
}
