<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\Components\BackupScheduleForm;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every control on the schedule form does something.
 *
 * Two of them did not, and both were found by auditing rather than by anybody
 * reporting them — which is the point of writing this down. "Use streaming
 * pipeline (recommended for large sites)" selected the retired engine's upload
 * path and had zero live references. `day_of_week` and `day_of_month` saved,
 * displayed, and were read by nothing: scheduleNextRun() added a week or a month
 * to now, so a backup set for Monday drifted to whatever day the last one landed
 * on.
 *
 * A setting that saves, shows itself back, and changes nothing is worse than a
 * missing one. A gap gets noticed; a lie gets believed. So this test fills the
 * form in, saves once, and asserts every field arrived — and the behavioural
 * half (that the stored value then changes what happens) is covered by
 * ScheduledDayIsHonouredTest, ExclusionsReachTheEngineTest, ManualBackupRoutingTest
 * and the retention suite.
 */
class BackupScheduleFormSavesEverythingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_field_on_the_form_is_persisted(): void
    {
        Redis::spy();
        Carbon::setTestNow(Carbon::parse('2026-08-08 12:00:00', 'Europe/Bucharest'));

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $site = Site::factory()->create(['user_id' => $admin->id]);
        $primary = StorageDestination::factory()->create(['is_active' => true]);

        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $primary->id,
            'type' => 'full',
            'frequency' => 'daily',
            'time' => '01:00',
            'timezone' => 'UTC',
            'retention_type' => 'count',
            'retention_value' => 7,
        ]);

        Livewire::actingAs($admin)
            ->test(BackupScheduleForm::class, ['site' => $site])
            ->call('openModal')
            ->set('is_enabled', true)
            ->set('type', 'full')
            ->set('frequency', 'weekly')
            ->set('day_of_week', Carbon::WEDNESDAY)
            ->set('day_of_month', 12)
            ->set('time', '04:30')
            ->set('timezone', 'Europe/Bucharest')
            ->set('storage_destination_id', $primary->id)
            ->set('retention_type', 'days')
            ->set('retention_value', 45)
            ->set('enable_incremental', true)
            ->set('full_backup_day_of_week', Carbon::SUNDAY)
            ->set('backup_before_updates', true)
            ->call('togglePath', 'wp-content/cache')
            ->call('toggleTable', 'wp_actionscheduler_logs')
            ->call('save')
            ->assertHasNoErrors();

        $config = $site->backupConfig()->first();

        $this->assertTrue((bool) $config->is_enabled, 'enabled');
        $this->assertSame('full', $config->type, 'backup type');
        $this->assertSame('weekly', $config->frequency, 'frequency');
        $this->assertSame(Carbon::WEDNESDAY, (int) $config->day_of_week, 'day of week');
        $this->assertSame('04:30', substr((string) $config->time, 0, 5), 'time');
        $this->assertSame('Europe/Bucharest', $config->timezone, 'timezone');
        $this->assertSame($primary->id, $config->storage_destination_id, 'primary storage');
        $this->assertSame('days', $config->retention_type, 'retention type');
        $this->assertSame(45, (int) $config->retention_value, 'retention value');
        $this->assertSame('daily', $config->incremental_frequency, 'incremental enabled');
        $this->assertSame(Carbon::SUNDAY, (int) $config->full_backup_day_of_week, 'full backup day');
        $this->assertTrue((bool) $config->backup_before_updates, 'backup before updates');
        $this->assertSame(['wp-content/cache'], $config->exclude_paths, 'excluded paths');
        $this->assertSame(['wp_actionscheduler_logs'], $config->exclude_tables, 'excluded tables');

        // And the one field that is computed rather than typed: the next run has
        // to land on the weekday that was just chosen.
        $this->assertSame(
            Carbon::WEDNESDAY,
            $config->next_backup_at->setTimezone($config->timezone)->dayOfWeek,
            'the schedule the form just saved is the schedule that will run',
        );
    }

    /**
     * A monthly schedule keeps the day of the month, which lives behind a
     * different branch of the form and so needs its own pass.
     */
    public function test_a_monthly_schedule_keeps_its_day(): void
    {
        Redis::spy();
        Carbon::setTestNow(Carbon::parse('2026-08-08 12:00:00', 'Europe/Bucharest'));

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $site = Site::factory()->create(['user_id' => $admin->id]);
        StorageDestination::factory()->create(['is_active' => true, 'is_default' => true]);

        Livewire::actingAs($admin)
            ->test(BackupScheduleForm::class, ['site' => $site])
            ->call('openModal')
            ->set('is_enabled', true)
            ->set('type', 'database')
            ->set('frequency', 'monthly')
            ->set('day_of_month', 12)
            ->set('time', '02:00')
            ->set('timezone', 'Europe/Bucharest')
            ->set('retention_type', 'count')
            ->set('retention_value', 30)
            ->call('save')
            ->assertHasNoErrors();

        $config = $site->backupConfig()->first();

        $this->assertSame('database', $config->type);
        $this->assertSame(12, (int) $config->day_of_month);
        $this->assertSame(12, $config->next_backup_at->setTimezone($config->timezone)->day);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
