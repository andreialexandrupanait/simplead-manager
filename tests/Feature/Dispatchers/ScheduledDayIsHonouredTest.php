<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\BackupDispatcher;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A weekly backup set for Monday runs on a Monday.
 *
 * It did not. scheduleNextRun() did `addWeek()` from now, so the next run landed
 * seven days after whenever the last one happened — which is a different weekday
 * as soon as anything shifts the schedule once: a missed night, a manual run, a
 * site that was busy at its window. `day_of_week` and `day_of_month` were on the
 * form, saved to the config, shown back on the schedule card, and read by
 * nothing.
 *
 * That is the worst shape a setting can have. A missing option is a gap someone
 * notices; an option that saves, displays, and changes nothing is a lie the
 * screen keeps repeating.
 */
class ScheduledDayIsHonouredTest extends TestCase
{
    use RefreshDatabase;

    private function configFor(string $frequency, array $overrides = []): BackupConfig
    {
        $site = Site::factory()->create(['is_connected' => true]);

        return BackupConfig::factory()->create(array_merge([
            'site_id' => $site->id,
            'storage_destination_id' => StorageDestination::factory()->create()->id,
            'is_enabled' => true,
            'frequency' => $frequency,
            'time' => '03:00',
            'timezone' => 'Europe/Bucharest',
        ], $overrides));
    }

    /**
     * The next run as the SITE reads it.
     *
     * `next_backup_at` is a wall clock stored in the application's timezone
     * (BackupConfig::asStoredRunTime) — so on a UTC-configured app a 03:00
     * Europe/Bucharest schedule is stored as 00:00, correctly. Asserting on the
     * raw column would be asserting the storage convention; what matters is the
     * hour the site was promised.
     */
    private function scheduleNext(BackupConfig $config): Carbon
    {
        $dispatcher = new class extends BackupDispatcher
        {
            public function next(BackupConfig $config): void
            {
                $this->scheduleNextRun($config);
            }
        };

        $dispatcher->next($config);

        return $config->fresh()->next_backup_at->setTimezone($config->timezone);
    }

    public function test_a_weekly_schedule_lands_on_the_chosen_weekday(): void
    {
        // A Thursday. The old behaviour would have answered "next Thursday".
        Carbon::setTestNow(Carbon::parse('2026-08-06 04:00:00', 'Europe/Bucharest'));

        $next = $this->scheduleNext($this->configFor('weekly', ['day_of_week' => Carbon::MONDAY]));

        $this->assertSame(Carbon::MONDAY, $next->dayOfWeek, 'the weekday the person picked');
        $this->assertSame('03:00', $next->format('H:i'));
        $this->assertTrue($next->isFuture());
    }

    /**
     * A run finishing at its own window must not schedule itself for later the
     * same day — the dispatcher would pick it straight back up.
     */
    public function test_the_chosen_weekday_today_still_means_next_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 03:20:00', 'Europe/Bucharest')); // a Monday, just after the window

        $next = $this->scheduleNext($this->configFor('weekly', ['day_of_week' => Carbon::MONDAY]));

        $this->assertSame(Carbon::MONDAY, $next->dayOfWeek);
        $this->assertSame('2026-08-17', $next->format('Y-m-d'), 'the following Monday, not this one again');
    }

    public function test_a_monthly_schedule_lands_on_the_chosen_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 04:00:00', 'Europe/Bucharest'));

        $next = $this->scheduleNext($this->configFor('monthly', ['day_of_month' => 5]));

        $this->assertSame('2026-09-05', $next->format('Y-m-d'));
        $this->assertSame('03:00', $next->format('H:i'));
    }

    /**
     * The 31st in a 30-day month is the 30th, not the 1st of the month after.
     * Rolling over would mean a monthly backup that runs in January, March and
     * May and quietly skips the rest.
     */
    public function test_a_day_that_does_not_exist_clamps_to_the_end_of_the_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 04:00:00', 'Europe/Bucharest'));

        $next = $this->scheduleNext($this->configFor('monthly', ['day_of_month' => 31]));

        $this->assertSame('2026-09-30', $next->format('Y-m-d'), 'September has thirty days');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
