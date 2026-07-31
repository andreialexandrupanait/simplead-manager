<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\ReportDispatcher;
use App\Jobs\GenerateReport;
use App\Models\ReportSchedule;
use App\Services\RetentionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P3-23: the last_7_days window spanned 8 calendar days (subDays(7) through
 * today). A true 7-day window is today-6 through today inclusive. Also asserts
 * the report PDF retention category is registered.
 */
class ReportWeeklyPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekly_period_covers_exactly_seven_days(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));
        Queue::fake();

        ReportSchedule::factory()->create([
            'is_active' => true,
            'period' => 'last_7_days',
            'next_run_at' => now()->subMinute(),
        ]);

        (new ReportDispatcher)();

        Queue::assertPushed(GenerateReport::class, function (GenerateReport $job) {
            // Jul 9 00:00:00 .. Jul 15 23:59:59 = 7 inclusive days.
            return $job->periodStart->toDateString() === '2026-07-09'
                && $job->periodEnd->toDateString() === '2026-07-15';
        });
    }

    /**
     * SPEC §14.1 keeps reports permanently: a report is the record of what was
     * delivered to a client, and the client may ask about one years later. There
     * was a 365-day retention category for them (dry-run gated, so it never
     * actually deleted); it is gone, and no category may quietly reintroduce it.
     */
    public function test_reports_are_never_pruned_by_retention(): void
    {
        $this->assertArrayNotHasKey('reports', RetentionPolicyService::CATEGORIES);

        $prunedTables = collect(RetentionPolicyService::CATEGORIES)
            ->flatMap(fn (array $category) => array_column($category['tables'], 'table'));

        $this->assertNotContains('reports', $prunedTables);
    }
}
