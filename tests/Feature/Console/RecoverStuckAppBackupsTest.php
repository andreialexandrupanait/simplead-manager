<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\RecoverStuckAppBackups;
use App\Models\AppBackup;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P1-38: a killed worker (timeout/OOM/deploy) leaves an AppBackup stuck
 * in_progress forever, and AppBackupCreator::create() refuses to start while
 * any row is in_progress — so platform self-backup silently wedges. This
 * sweep (and the self-heal inside create()) marks a dead row failed once its
 * heartbeat is older than the recovery threshold.
 */
class RecoverStuckAppBackupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        NotificationChannel::factory()->default()->create();
    }

    private function makeInProgress(int $heartbeatMinutesAgo): AppBackup
    {
        $backup = AppBackup::create([
            'type' => 'full',
            'trigger' => 'manual',
            'status' => 'in_progress',
            'progress' => 40,
            'started_at' => now()->subMinutes($heartbeatMinutesAgo + 5),
        ]);

        AppBackup::whereKey($backup->id)->update(['updated_at' => now()->subMinutes($heartbeatMinutesAgo)]);

        return $backup->fresh();
    }

    public function test_stuck_app_backup_is_recovered(): void
    {
        $stuck = $this->makeInProgress(RecoverStuckAppBackups::STALE_AFTER_MINUTES + 15);

        $this->artisan('app-backups:recover-stuck')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertNotNull($stuck->fresh()->completed_at);
    }

    public function test_fresh_in_progress_app_backup_is_left_alone(): void
    {
        $fresh = $this->makeInProgress(1);

        $this->artisan('app-backups:recover-stuck')->assertSuccessful();

        $this->assertSame('in_progress', $fresh->fresh()->status);
    }

    public function test_dry_run_reports_without_changing(): void
    {
        $stuck = $this->makeInProgress(RecoverStuckAppBackups::STALE_AFTER_MINUTES + 15);

        $this->artisan('app-backups:recover-stuck --dry-run')->assertSuccessful();

        $this->assertSame('in_progress', $stuck->fresh()->status);
    }
}
