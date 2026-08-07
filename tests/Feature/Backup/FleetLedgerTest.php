<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Livewire\Backups\BackupsOverview;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\User;
use App\Services\Backup\FleetLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ledger behind /backups.
 *
 * The bug worth a test of its own is the first one: every backup screen this replaced queried
 * `backups` and drew a row per run, so a site that had never run one had no rows and appeared
 * nowhere. Seventeen of forty-one production sites were invisible for that reason. A screen whose
 * job is "show me who is not protected" cannot be driven by the table that only knows about the
 * ones that are.
 */
class FleetLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_a_site_that_has_never_been_backed_up_still_gets_a_row(): void
    {
        Site::factory()->create(['name' => 'never-touched']);

        $rows = app(FleetLedger::class)->rows(null);

        $this->assertCount(1, $rows);
        $this->assertSame('never-touched', $rows->first()['site']->name);
        $this->assertNull($rows->first()['last_valid_at']);
        $this->assertNull($rows->first()['age_days']);
        $this->assertNotNull($rows->first()['problem']);
    }

    public function test_the_screen_lists_a_site_with_no_runs(): void
    {
        Site::factory()->create(['name' => 'invisible-before']);

        Livewire::test(BackupsOverview::class)->assertSee('invisible-before');
    }

    public function test_scheduled_sites_outrank_unscheduled_ones_and_the_oldest_comes_first(): void
    {
        $broken = Site::factory()->create(['name' => 'broken', 'is_connected' => true]);
        BackupConfig::factory()->create(['site_id' => $broken->id, 'is_enabled' => true]);
        Backup::factory()->for($broken)->create([
            'status' => BackupStatus::Completed,
            'completed_at' => now()->subDays(9),
            'created_at' => now()->subDays(9),
        ]);

        $healthy = Site::factory()->create(['name' => 'healthy', 'is_connected' => true]);
        BackupConfig::factory()->create(['site_id' => $healthy->id, 'is_enabled' => true]);
        Backup::factory()->for($healthy)->create([
            'status' => BackupStatus::Completed,
            'completed_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

        // No schedule and no runs — the worst age there is, but not an incident.
        Site::factory()->create(['name' => 'unscheduled']);

        $names = app(FleetLedger::class)->rows(null)->map(fn (array $r) => $r['site']->name)->all();

        $this->assertSame(['broken', 'healthy', 'unscheduled'], $names);
    }

    public function test_each_night_carries_the_best_thing_that_happened_on_it(): void
    {
        $site = Site::factory()->create();
        BackupConfig::factory()->create(['site_id' => $site->id, 'is_enabled' => true]);

        // A failure and then a success on the same night: the night has a restore point.
        Backup::factory()->for($site)->create([
            'status' => BackupStatus::Failed,
            'created_at' => now()->subDays(2)->setTime(0, 5),
            'completed_at' => null,
            'verified_at' => null,
        ]);
        Backup::factory()->for($site)->create([
            'status' => BackupStatus::Completed,
            'created_at' => now()->subDays(2)->setTime(1, 30),
            'completed_at' => now()->subDays(2)->setTime(1, 40),
            'verified_at' => now()->subDays(2)->setTime(1, 45),
        ]);

        // Last night: attempted, failed.
        Backup::factory()->for($site)->create([
            'status' => BackupStatus::Failed,
            'created_at' => now()->subDay()->setTime(0, 5),
            'completed_at' => null,
            'verified_at' => null,
        ]);

        $nights = collect(app(FleetLedger::class)->rows(null)->first()['nights'])
            ->keyBy('date')
            ->map(fn (array $n) => $n['state']);

        $this->assertSame(FleetLedger::VERIFIED, $nights[now()->subDays(2)->toDateString()]);
        $this->assertSame(FleetLedger::FAILED, $nights[now()->subDay()->toDateString()]);
        $this->assertSame(FleetLedger::NOTHING, $nights[now()->subDays(5)->toDateString()]);
    }

    public function test_a_run_still_in_flight_does_not_claim_the_night(): void
    {
        $site = Site::factory()->create();
        Backup::factory()->for($site)->create([
            'status' => BackupStatus::InProgress,
            'created_at' => now()->setTime(0, 5),
            'completed_at' => null,
            'verified_at' => null,
        ]);

        $nights = collect(app(FleetLedger::class)->rows(null)->first()['nights'])->keyBy('date');

        $this->assertSame(FleetLedger::NOTHING, $nights[now()->toDateString()]['state']);
    }

    public function test_the_strip_is_always_thirty_nights_ending_last_night(): void
    {
        Site::factory()->create();

        $nights = app(FleetLedger::class)->rows(null)->first()['nights'];

        $this->assertCount(FleetLedger::NIGHTS, $nights);
        $this->assertSame(now()->toDateString(), end($nights)['date']);
    }

    public function test_a_healthy_site_says_nothing(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        BackupConfig::factory()->create(['site_id' => $site->id, 'is_enabled' => true]);
        Backup::factory()->for($site)->create([
            'status' => BackupStatus::Completed,
            'completed_at' => now()->subHours(3),
            'created_at' => now()->subHours(3),
        ]);

        $this->assertNull(app(FleetLedger::class)->rows(null)->first()['problem']);
    }

    public function test_an_old_failure_a_later_success_answered_is_not_the_reason(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        BackupConfig::factory()->create(['site_id' => $site->id, 'is_enabled' => true]);

        Backup::factory()->for($site)->create([
            'status' => BackupStatus::Failed,
            'error_message' => 'The database did not finish dumping in 90 seconds',
            'created_at' => now()->subDays(3),
        ]);
        Backup::factory()->for($site)->create([
            'status' => BackupStatus::Completed,
            'completed_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

        $this->assertNull(app(FleetLedger::class)->rows(null)->first()['problem']);
    }

    public function test_the_summary_counts_incidents_apart_from_sites_nobody_scheduled(): void
    {
        $failing = Site::factory()->create(['is_connected' => true]);
        BackupConfig::factory()->create(['site_id' => $failing->id, 'is_enabled' => true]);
        Backup::factory()->for($failing)->create([
            'status' => BackupStatus::Failed,
            'error_message' => 'boom',
            'created_at' => now()->subDay(),
        ]);

        Site::factory()->count(3)->create();

        $summary = Livewire::test(BackupsOverview::class)->instance()->fleetSummary();

        $this->assertSame(1, $summary['failing']);
        $this->assertSame(1, $summary['scheduled']);
        $this->assertSame(3, $summary['unscheduled']);
        $this->assertSame(4, $summary['total']);
    }

    public function test_a_bookmarked_stale_filter_lands_on_something_usable(): void
    {
        Site::factory()->create(['name' => 'still-here']);

        Livewire::test(BackupsOverview::class, ['filter' => 'stale'])
            ->assertSee('still-here')
            ->assertSet('filter', 'all');
    }
}
