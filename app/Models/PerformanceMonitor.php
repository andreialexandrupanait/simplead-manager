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

    /**
     * Floor for the recurring test cadence (mirrors ModuleConfigService's
     * performance minimum) so a misconfigured plan can't schedule a PSI test
     * loop that burns the API quota.
     */
    public const MIN_INTERVAL_MINUTES = 360;

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
        'day_of_week' => 'integer',
        'budgets' => 'array',
        'competitor_urls' => 'array',
        'last_tested_at' => 'datetime',
        'next_test_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Compute the next due time for this monitor's recurring test (P2-16).
     *
     * The plan writes a per-site `interval_minutes`, but the scheduler used to
     * recompute `next_test_at` purely from the coarse daily/weekly bucket — so a
     * custom interval was a dead knob and was silently ignored. `interval_minutes`
     * is now the source of truth for the cadence; `frequency` only distinguishes a
     * recurring monitor from a manual one (which never auto-runs → null).
     */
    public function calculateNextTestAt(): ?\Illuminate\Support\Carbon
    {
        // 'manual' (and any non-recurring value) leaves next_test_at null so the
        // dispatcher never auto-runs it — tests only fire when triggered by hand.
        if (! in_array($this->frequency, ['daily', 'weekly'], true)) {
            return null;
        }

        // P3-19: a weekly monitor pins to its configured day-of-week (0=Sunday..
        // 6=Saturday, Carbon's convention — the same value the settings UI writes)
        // instead of drifting by the raw interval. `next()` always lands on the
        // upcoming occurrence of that weekday (1-7 days out), anchored to test_time.
        if ($this->frequency === 'weekly' && $this->day_of_week !== null) {
            $next = now()->next((int) $this->day_of_week);
            if ($this->test_time) {
                [$hour, $minute] = array_pad(explode(':', $this->test_time), 2, '0');
                $next->setTime((int) $hour, (int) $minute);
            }

            return $next;
        }

        $interval = max((int) $this->interval_minutes, self::MIN_INTERVAL_MINUTES);
        $next = now()->addMinutes($interval);

        // Keep whole-day cadences anchored to the configured test-time, without
        // ever pulling the next run earlier than a full interval.
        if ($this->test_time && $interval % 1440 === 0) {
            [$hour, $minute] = array_pad(explode(':', $this->test_time), 2, '0');
            $next->setTime((int) $hour, (int) $minute);
            if ($next->lessThan(now())) {
                $next->addDay();
            }
        }

        return $next;
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
