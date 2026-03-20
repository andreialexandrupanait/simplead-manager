<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
