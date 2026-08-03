<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dispatchers\ReportDispatcher;
use App\Jobs\GenerateReport;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression tests for the 2026-08-01 triple-report incident: calculateNextRun()
 * returned UTC while datetimes persist naive in the app timezone, so schedules
 * fired 3h early and stayed due until the real hour — and GenerateReport's 1-hour
 * dedup window minted (and re-sent) a fresh duplicate every ~65 minutes.
 */
class ReportScheduleRefireTest extends TestCase
{
    use RefreshDatabase;

    private string $originalPhpTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPhpTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalPhpTz);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Run the app the way production runs it: APP_TIMEZONE=Europe/Bucharest, so
     * naive stored datetimes are interpreted as Bucharest wall-clock time.
     */
    private function useBucharestAppTimezone(): void
    {
        config(['app.timezone' => 'Europe/Bucharest']);
        date_default_timezone_set('Europe/Bucharest');
    }

    public function test_next_run_round_trips_to_the_scheduled_wall_clock_hour(): void
    {
        $this->useBucharestAppTimezone();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Bucharest'));

        $schedule = ReportSchedule::factory()->create([
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'time' => '05:00',
            'timezone' => 'Europe/Bucharest',
        ]);

        $schedule->update(['next_run_at' => $schedule->calculateNextRun()]);

        // Read back through the model cast — exactly what the dispatcher compares
        // against now(). The stored value must round-trip to 05:00 Bucharest, not
        // drift 3h early via a naive UTC write.
        $storedNext = $schedule->fresh()->next_run_at;
        $this->assertSame(
            '2026-09-01 05:00',
            $storedNext->timezone('Europe/Bucharest')->format('Y-m-d H:i'),
        );
    }

    public function test_a_claimed_schedule_is_no_longer_due_on_the_next_tick(): void
    {
        $this->useBucharestAppTimezone();
        // The incident window: the scheduled hour itself, just after it struck.
        Carbon::setTestNow(Carbon::parse('2026-08-01 05:00:30', 'Europe/Bucharest'));
        Queue::fake();

        $schedule = ReportSchedule::factory()->create([
            'is_active' => true,
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'time' => '05:00',
            'timezone' => 'Europe/Bucharest',
            'period' => 'last_month',
            'next_run_at' => now()->subSecond(),
        ]);

        (new ReportDispatcher)();
        Queue::assertPushed(GenerateReport::class);

        // The claim must push next_run_at into the future; the incident left it
        // <= now, so every subsequent 5-minute tick re-claimed and re-dispatched.
        $this->assertTrue($schedule->fresh()->next_run_at->gt(now()));

        Queue::fake();
        (new ReportDispatcher)();
        Queue::assertNotPushed(GenerateReport::class);
    }

    public function test_a_sent_report_for_the_period_is_never_regenerated_regardless_of_age(): void
    {
        $site = Site::factory()->create();
        $template = ReportTemplate::factory()->create();
        $schedule = ReportSchedule::factory()->create([
            'site_id' => $site->id,
            'report_template_id' => $template->id,
        ]);

        $periodStart = now()->subDays(29)->startOfDay();
        $periodEnd = now()->endOfDay();

        // Older than the former 1-hour dedup window — the incident's blind spot.
        Report::factory()->create([
            'site_id' => $site->id,
            'report_template_id' => $template->id,
            'report_schedule_id' => $schedule->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'was_sent' => true,
            'created_at' => now()->subHours(2),
        ]);

        (new GenerateReport($site, $template, $periodStart, $periodEnd, 'scheduled', $schedule))->handle();

        $this->assertSame(1, Report::count());
    }

    public function test_a_held_report_for_the_period_is_not_regenerated(): void
    {
        $site = Site::factory()->create();
        $template = ReportTemplate::factory()->create();
        $schedule = ReportSchedule::factory()->create([
            'site_id' => $site->id,
            'report_template_id' => $template->id,
        ]);

        $periodStart = now()->subDays(29)->startOfDay();
        $periodEnd = now()->endOfDay();

        $held = Report::factory()->create([
            'site_id' => $site->id,
            'report_template_id' => $template->id,
            'report_schedule_id' => $schedule->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'was_sent' => false,
            'send_held_reason' => 'the site is currently down',
            'send_held_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

        (new GenerateReport($site, $template, $periodStart, $periodEnd, 'scheduled', $schedule))->handle();

        $this->assertSame(1, Report::count());
        // Held means awaiting manual review — it must not be flipped back into
        // 'generating' and silently replaced.
        $this->assertSame('completed', $held->fresh()->status->value);
    }
}
