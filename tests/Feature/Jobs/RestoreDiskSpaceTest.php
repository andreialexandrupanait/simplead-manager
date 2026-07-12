<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\DiskSpaceGuard;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/**
 * P1-39: a restore now pre-flights disk space and refuses up front rather than
 * filling the disk mid-flight (which pauses fleet-wide backups). On refusal the
 * restore is marked failed and the site operation lock is released.
 */
class RestoreDiskSpaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
    }

    public function test_restore_aborts_when_disk_space_insufficient(): void
    {
        $site = Site::factory()->create();
        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Completed,
            'restore_status' => BackupStatus::Pending,
            'file_size' => 1_000_000_000,
        ]);

        $guard = Mockery::mock(DiskSpaceGuard::class);
        $guard->shouldReceive('hasSpaceFor')->andReturnFalse();
        $guard->shouldReceive('freeBytes')->andReturn(100);
        $this->app->instance(DiskSpaceGuard::class, $guard);

        $job = new RestoreBackup($backup);

        try {
            $job->handle();
            $this->fail('Expected the restore to throw on insufficient disk space.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient disk space', $e->getMessage());
        }

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
        $this->assertFalse(SiteOperationLock::isHeld($site->id), 'The site lock must be released after the abort.');
    }
}
