<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformancePage extends Model
{
    use HasFactory;

    protected $fillable = [
        'performance_monitor_id',
        'label',
        'url',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(PerformanceMonitor::class, 'performance_monitor_id');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(PerformanceTest::class);
    }
}
