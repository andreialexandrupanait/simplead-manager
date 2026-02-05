<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PerformanceMonitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'is_active',
        'frequency',
        'test_time',
        'day_of_week',
        'alert_on_score_drop',
        'score_drop_threshold',
        'alert_on_poor_vitals',
        'budgets',
        'latest_mobile_score',
        'latest_desktop_score',
        'previous_mobile_score',
        'previous_desktop_score',
        'last_tested_at',
        'next_test_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'alert_on_score_drop' => 'boolean',
        'alert_on_poor_vitals' => 'boolean',
        'budgets' => 'array',
        'last_tested_at' => 'datetime',
        'next_test_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function tests(): HasMany
    {
        return $this->hasMany(PerformanceTest::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(PerformancePage::class);
    }

    public function primaryPage(): HasOne
    {
        return $this->hasOne(PerformancePage::class)->where('is_primary', true);
    }

    public function latestMobileTest(): HasOne
    {
        return $this->hasOne(PerformanceTest::class)
            ->where('device', 'mobile')
            ->where('status', 'completed')
            ->latestOfMany('tested_at');
    }

    public function latestDesktopTest(): HasOne
    {
        return $this->hasOne(PerformanceTest::class)
            ->where('device', 'desktop')
            ->where('status', 'completed')
            ->latestOfMany('tested_at');
    }
}
