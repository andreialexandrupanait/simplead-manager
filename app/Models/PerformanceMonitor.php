<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $site_id
 * @property bool $is_active
 * @property string $frequency
 * @property string $test_time
 * @property int|null $day_of_week
 * @property int $interval_minutes
 * @property bool $alert_on_score_drop
 * @property int $score_drop_threshold
 * @property bool $alert_on_poor_vitals
 * @property array|null $budgets
 * @property int|null $latest_mobile_score
 * @property int|null $latest_desktop_score
 * @property int|null $previous_mobile_score
 * @property int|null $previous_desktop_score
 * @property \Illuminate\Support\Carbon|null $last_tested_at
 * @property \Illuminate\Support\Carbon|null $next_test_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PerformanceTest> $tests
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PerformancePage> $pages
 * @property-read \App\Models\PerformancePage|null $primaryPage
 * @property-read \App\Models\PerformanceTest|null $latestMobileTest
 * @property-read \App\Models\PerformanceTest|null $latestDesktopTest
 */
class PerformanceMonitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'is_active',
        'frequency',
        'test_time',
        'day_of_week',
        'interval_minutes',
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
        'competitor_urls',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'alert_on_score_drop' => 'boolean',
        'alert_on_poor_vitals' => 'boolean',
        'interval_minutes' => 'integer',
        'budgets' => 'array',
        'competitor_urls' => 'array',
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
            ->ofMany(
                ['tested_at' => 'max'],
                fn ($q) => $q->where('device', 'mobile')->where('status', 'completed')
            );
    }

    public function latestDesktopTest(): HasOne
    {
        return $this->hasOne(PerformanceTest::class)
            ->ofMany(
                ['tested_at' => 'max'],
                fn ($q) => $q->where('device', 'desktop')->where('status', 'completed')
            );
    }
}
