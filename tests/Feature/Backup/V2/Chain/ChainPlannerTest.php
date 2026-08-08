<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Chain;

use App\Backup\V2\Chain\ChainPlanner;
use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\SessionActions;
use App\Dispatchers\BackupDispatcher;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * Someone has to decide what tonight's backup is.
 *
 * Nothing did. full_base_id and chain_position existed on the session, the
 * resolver knew how to walk a chain and the plugin knew how to diff against a
 * base manifest — and no code ever chose. Every session started with a null
 * base, so an "incremental" either swept every file exactly like a full or, once
 * the resolver was asked for a base that was not there, threw.
 *
 * The schedule stays the user's: incremental_frequency and
 * full_backup_day_of_week are the same two fields the old dispatcher reads. What
 * changes is that the chain is resolved from what actually completed.
 */
class ChainPlannerTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
    }

    private function site(array $configAttrs = []): Site
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $destination = StorageDestination::factory()->create([
            'type' => 's3', 'is_active' => true, 'is_default' => true,
        ]);
        BackupConfig::factory()->create($configAttrs + [
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'is_enabled' => true,
            'type' => 'full',
            'incremental_frequency' => 'daily',
            'full_backup_day_of_week' => null,
        ]);

        return $site->fresh();
    }

    private function completedFull(Site $site): BackupSession
    {
        return $this->makeBackupSession($site, [
            'type' => 'full',
            'state' => S::Completed,
            'completed_at' => now()->subDay(),
        ]);
    }

    // ── choosing ─────────────────────────────────────────────────────────

    public function test_the_first_backup_of_a_site_is_always_a_full(): void
    {
        $plan = (new ChainPlanner)->planFor($this->site());

        $this->assertSame('full', $plan['type']);
        $this->assertNull($plan['full_base_id']);
    }

    public function test_a_site_with_a_completed_full_gets_an_incremental_on_it(): void
    {
        $site = $this->site();
        $base = $this->completedFull($site);

        $plan = (new ChainPlanner)->planFor($site);

        $this->assertSame('incremental', $plan['type']);
        $this->assertSame($base->id, $plan['full_base_id']);
        $this->assertSame(1, $plan['chain_position']);
    }

    public function test_positions_advance_along_the_chain(): void
    {
        $site = $this->site();
        $base = $this->completedFull($site);
        $this->makeBackupSession($site, [
            'type' => 'incremental', 'state' => S::Completed,
            'full_base_id' => $base->id, 'chain_position' => 1,
        ]);
        $this->makeBackupSession($site, [
            'type' => 'incremental', 'state' => S::Completed,
            'full_base_id' => $base->id, 'chain_position' => 2,
        ]);

        $this->assertSame(3, (new ChainPlanner)->planFor($site)['chain_position']);
    }

    /**
     * Derived from the highest position recorded, not from a count. A deleted
     * middle link must surface as a broken chain at restore time rather than be
     * papered over by a renumbered successor that silently skips it.
     */
    public function test_a_deleted_middle_link_does_not_renumber_the_chain(): void
    {
        $site = $this->site();
        $base = $this->completedFull($site);
        $this->makeBackupSession($site, [
            'type' => 'incremental', 'state' => S::Completed,
            'full_base_id' => $base->id, 'chain_position' => 1,
        ]);
        $third = $this->makeBackupSession($site, [
            'type' => 'incremental', 'state' => S::Completed,
            'full_base_id' => $base->id, 'chain_position' => 3,
        ]);

        $this->assertSame(4, (new ChainPlanner)->planFor($site)['chain_position']);
        $this->assertSame(3, $third->chain_position);
    }

    public function test_the_configured_full_day_forces_a_full(): void
    {
        $site = $this->site(['full_backup_day_of_week' => now()->dayOfWeek]);
        $this->completedFull($site);

        $this->assertSame('full', (new ChainPlanner)->planFor($site)['type']);
    }

    public function test_a_site_without_incrementals_configured_always_gets_a_full(): void
    {
        $site = $this->site(['incremental_frequency' => null]);
        $this->completedFull($site);

        $this->assertSame('full', (new ChainPlanner)->planFor($site)['type']);
    }

    /**
     * A chain running for a month is one long dependency: every restore replays
     * every link, and one unreadable manifest anywhere costs all of them.
     */
    public function test_an_old_chain_is_started_again(): void
    {
        $site = $this->site();
        $this->makeBackupSession($site, [
            'type' => 'full', 'state' => S::Completed,
            'completed_at' => now()->subDays(45),
        ]);

        $this->assertSame('full', (new ChainPlanner)->planFor($site)['type']);
    }

    public function test_an_unfinished_full_is_not_a_chain_base(): void
    {
        $site = $this->site();
        $this->makeBackupSession($site, ['type' => 'full', 'state' => S::Failed]);

        $plan = (new ChainPlanner)->planFor($site);

        $this->assertSame('full', $plan['type']);
        $this->assertNull($plan['full_base_id']);
    }

    // ── scope ────────────────────────────────────────────────────────────

    /**
     * The bug this exists to stop. `incremental` matched neither of the two
     * lists, so the scope came out {database:false, files:false} and the backup
     * contained nothing at all — then failed verification for listing no
     * objects. Files may be a diff; the database never is.
     */
    public function test_an_incremental_still_captures_the_whole_database(): void
    {
        $site = $this->site();
        $this->completedFull($site);

        $session = app(SessionActions::class)->startBackup($site, 'incremental');

        $this->assertTrue($session->scope['database']);
        $this->assertTrue($session->scope['files']);
    }

    public function test_a_full_captures_both_too(): void
    {
        $session = app(SessionActions::class)->startBackup($this->site(), 'full');

        $this->assertTrue($session->scope['database']);
        $this->assertTrue($session->scope['files']);
    }

    // ── the dispatcher ───────────────────────────────────────────────────

    public function test_a_site_on_the_new_engine_gets_a_session_not_the_old_job(): void
    {
        $site = $this->site();
        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', [(string) $site->id]);
        $site->backupConfig->update([
            'next_backup_at' => now()->subMinute(),
        ]);

        (new BackupDispatcher)();

        Queue::assertNotPushed(CreateBackup::class);
        Queue::assertNotPushed(CreateIncrementalBackup::class);
        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id, 'type' => 'full']);
        // The schedule is still the user's: the next run must be booked either way.
        $this->assertTrue($site->backupConfig->fresh()->next_backup_at->isFuture());
    }
}
