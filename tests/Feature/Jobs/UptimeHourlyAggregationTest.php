<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AggregateUptimeHourly;
use App\Jobs\AggregateUptimeWindows;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeHourlyAggregate;
use App\Models\UptimeMonitor;
use App\Services\RetentionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SPEC §14.1: raw pings 14 days, hourly aggregates 13 months.
 *
 * Before this existed, no hourly table did, so any window longer than the raw
 * retention silently covered only the unpruned tail — the stored "365d" figure
 * could never have meant a year.
 */
class UptimeHourlyAggregationTest extends TestCase
{
    use RefreshDatabase;

    private UptimeMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $site = Site::factory()->create();
        $this->monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
    }

    private function ping(string $at, bool $isUp, ?int $responseTime = 200): void
    {
        UptimeCheck::create([
            'monitor_id' => $this->monitor->id,
            'is_up' => $isUp,
            'response_time' => $responseTime,
            'status_code' => $isUp ? 200 : 500,
            'checked_at' => $at,
        ]);
    }

    public function test_folds_pings_into_one_row_per_hour(): void
    {
        $hour = now()->subHours(3)->startOfHour();

        $this->ping($hour->copy()->addMinutes(1)->toDateTimeString(), true, 100);
        $this->ping($hour->copy()->addMinutes(2)->toDateTimeString(), true, 200);
        $this->ping($hour->copy()->addMinutes(3)->toDateTimeString(), false, 900);
        // A different hour must land in its own bucket.
        $this->ping($hour->copy()->addHour()->addMinute()->toDateTimeString(), true, 150);

        (new AggregateUptimeHourly)->handle();

        $this->assertSame(2, UptimeHourlyAggregate::count());

        $bucket = UptimeHourlyAggregate::where('bucket_hour', $hour)->firstOrFail();
        $this->assertSame(3, $bucket->checks_total);
        $this->assertSame(2, $bucket->checks_up);
        $this->assertEqualsWithDelta(66.667, $bucket->uptime_pct, 0.01);
        $this->assertSame(400, $bucket->avg_response_time);
        $this->assertSame(900, $bucket->max_response_time);
        $this->assertSame($this->monitor->site_id, $bucket->site_id);
    }

    public function test_rerunning_corrects_an_hour_instead_of_doubling_it(): void
    {
        $hour = now()->subHours(2)->startOfHour();

        $this->ping($hour->copy()->addMinute()->toDateTimeString(), true);
        (new AggregateUptimeHourly)->handle();

        $this->ping($hour->copy()->addMinutes(2)->toDateTimeString(), false);
        (new AggregateUptimeHourly)->handle();

        $this->assertSame(1, UptimeHourlyAggregate::count());

        $bucket = UptimeHourlyAggregate::where('bucket_hour', $hour)->firstOrFail();
        $this->assertSame(2, $bucket->checks_total);
        $this->assertSame(1, $bucket->checks_up);
    }

    public function test_the_still_filling_current_hour_is_left_alone(): void
    {
        $this->ping(now()->toDateTimeString(), true);

        (new AggregateUptimeHourly)->handle();

        // Folding a partial hour would store a count the next run has to correct.
        $this->assertSame(0, UptimeHourlyAggregate::count());
    }

    /**
     * The long window is the reason the aggregates exist: it must be computed from
     * them, not from raw pings that only reach back 14 days.
     */
    public function test_the_long_window_is_computed_from_aggregates_beyond_raw_retention(): void
    {
        // 200 days back — far outside any raw retention window, so this can only
        // be answered from the aggregates.
        UptimeHourlyAggregate::create([
            'monitor_id' => $this->monitor->id,
            'site_id' => $this->monitor->site_id,
            'bucket_hour' => now()->subDays(200)->startOfHour(),
            'checks_total' => 100,
            'checks_up' => 90,
            'uptime_pct' => 90.0,
        ]);

        (new AggregateUptimeWindows)->handle(app(RetentionPolicyService::class));

        $this->assertEqualsWithDelta(90.0, $this->monitor->fresh()->uptime_365d, 0.01);
    }

    public function test_raw_retention_is_fourteen_days_per_spec(): void
    {
        $this->assertSame(14, RetentionPolicyService::CATEGORIES['uptime']['default']);
        // 13 months, so "July this year against July last year" is answerable.
        $this->assertSame(396, RetentionPolicyService::CATEGORIES['uptime_hourly']['default']);
    }
}
