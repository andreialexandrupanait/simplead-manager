<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $performance_monitor_id
 * @property string $label
 * @property string $url
 * @property bool $is_primary
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PerformanceMonitor|null $monitor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PerformanceTest> $tests
 */
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
