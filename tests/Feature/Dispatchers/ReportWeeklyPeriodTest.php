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

    public function test_pdf_retention_category_is_registered(): void
    {
        $this->assertArrayHasKey('reports', RetentionPolicyService::CATEGORIES);
        $this->assertTrue(RetentionPolicyService::CATEGORIES['reports']['dry_run']);
        $this->assertSame('reports', RetentionPolicyService::CATEGORIES['reports']['tables'][0]['table']);
    }
}
