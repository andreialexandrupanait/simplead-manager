<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Dispatchers\BackupDispatcher;
use App\Models\BackupConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The hour a site is configured for is the hour it backs up.
 *
 * `next_backup_at` is a wall clock, not an instant: Eloquent writes a Carbon in whatever timezone
 * that Carbon carries, and the dispatcher selects with `next_backup_at <= now()` on
 * config('app.timezone'). Storing the instant as UTC therefore read three hours early on a
 * Europe/Bucharest clock — twenty sites configured for 03:00 were backing up at 00:00, and the only
 * ones that looked right were the three still on a UTC config, where the two clocks coincide. That
 * is the shape of the bug: the configuration and the behaviour disagree, and the exceptions are the
 * rows that appear correct.
 */
class BackupWindowTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Bucharest']);
        date_default_timezone_set('Europe/Bucharest');
    }

    public function test_a_site_in_the_application_timezone_is_scheduled_for_the_hour_it_configured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 04:00:00', 'Europe/Bucharest'));

        $config = BackupConfig::factory()->create([
            'frequency' => 'daily',
            'time' => '03:00',
            'timezone' => 'Europe/Bucharest',
        ]);

        $this->scheduleNextRun($config);

        $stored = $config->fresh()->getRawOriginal('next_backup_at');
        $this->assertStringStartsWith('2026-08-09 03:00', (string) $stored);
    }

    public function test_a_site_in_another_timezone_fires_on_its_own_clock(): void
    {
        // 03:00 UTC is 06:00 in Bucharest, and 06:00 is what the dispatcher's clock has to see for
        // the site to be backed up at the hour it asked for.
        Carbon::setTestNow(Carbon::parse('2026-08-08 04:00:00', 'Europe/Bucharest'));

        $config = BackupConfig::factory()->create([
            'frequency' => 'daily',
            'time' => '03:00',
            'timezone' => 'UTC',
        ]);

        $this->scheduleNextRun($config);

        $stored = $config->fresh()->getRawOriginal('next_backup_at');
        $this->assertStringStartsWith('2026-08-09 06:00', (string) $stored);
    }

    public function test_the_stored_value_is_what_the_dispatchers_own_clock_compares_against(): void
    {
        // The regression in one assertion: whatever is stored must become due at the configured
        // moment on the clock the selection query uses, and not before.
        Carbon::setTestNow(Carbon::parse('2026-08-08 04:00:00', 'Europe/Bucharest'));

        $config = BackupConfig::factory()->create([
            'is_enabled' => true,
            'frequency' => 'daily',
            'time' => '03:00',
            'timezone' => 'Europe/Bucharest',
        ]);

        $this->scheduleNextRun($config);

        Carbon::setTestNow(Carbon::parse('2026-08-09 02:59:00', 'Europe/Bucharest'));
        $this->assertFalse($this->isDue($config), 'a minute early is still early');

        Carbon::setTestNow(Carbon::parse('2026-08-09 03:00:00', 'Europe/Bucharest'));
        $this->assertTrue($this->isDue($config), 'due at the configured hour, not three hours before');
    }

    public function test_the_plan_default_does_not_declare_one_zone_and_schedule_in_another(): void
    {
        // The preset wrote timezone=UTC while computing the first run on the application's clock,
        // so the row contradicted itself before anything had read it — and three fleet sites are in
        // exactly that state because of this line.
        Carbon::setTestNow(Carbon::parse('2026-08-08 04:00:00', 'Europe/Bucharest'));

        $site = \App\Models\Site::factory()->create();

        app(\App\Services\ModuleConfigService::class)->configureModule($site, 'backup', true);

        $config = BackupConfig::where('site_id', $site->id)->firstOrFail();

        $this->assertSame('Europe/Bucharest', $config->timezone);

        // 03:00 in the declared zone, plus up to 144 minutes of spreading jitter (10% of the daily
        // interval) — so the window, not the minute. What matters is that it is measured from the
        // zone the row declares, which is what scheduleNextRun will use from the second run on.
        $stored = Carbon::parse((string) $config->getRawOriginal('next_backup_at'));
        $window = Carbon::parse('2026-08-09 03:00:00', 'Europe/Bucharest');

        $this->assertTrue(
            $stored->betweenIncluded($window, $window->copy()->addMinutes(144)),
            "first run {$stored} is outside the 03:00–05:24 window the row declares",
        );
    }

    private function scheduleNextRun(BackupConfig $config): void
    {
        $dispatcher = new BackupDispatcher;
        $method = new \ReflectionMethod($dispatcher, 'scheduleNextRun');
        $method->invoke($dispatcher, $config);
    }

    private function isDue(BackupConfig $config): bool
    {
        return BackupConfig::query()
            ->whereKey($config->id)
            ->where('next_backup_at', '<=', now())
            ->exists();
    }
}
