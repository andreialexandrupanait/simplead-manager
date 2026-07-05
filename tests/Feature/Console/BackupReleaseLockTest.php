<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class BackupReleaseLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
    }

    public function test_releases_site_operation_lock_and_reports_holder(): void
    {
        $site = Site::factory()->create();
        SiteOperationLock::acquire($site->id, SiteOperationLock::OPERATION_RESTORE, 'backup:12');

        $this->artisan('backup:release-lock', ['siteId' => $site->id])
            ->expectsOutputToContain('was held by: restore backup:12')
            ->assertSuccessful();

        $this->assertFalse(SiteOperationLock::isHeld($site->id));
    }

    public function test_fail_in_progress_covers_restores_too(): void
    {
        $site = Site::factory()->create();
        $restoring = Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Completed,
            'restore_status' => BackupStatus::InProgress,
        ]);
        $pendingRestore = Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Completed,
            'restore_status' => BackupStatus::Pending,
        ]);

        $this->artisan('backup:release-lock', ['siteId' => $site->id, '--fail-in-progress' => true])
            ->expectsOutputToContain('2 restore(s) as failed')
            ->assertSuccessful();

        $this->assertSame(BackupStatus::Failed, $restoring->fresh()->restore_status);
        $this->assertSame(BackupStatus::Failed, $pendingRestore->fresh()->restore_status);
    }

    public function test_unknown_site_fails(): void
    {
        $this->artisan('backup:release-lock', ['siteId' => 999999])->assertFailed();
    }
}
