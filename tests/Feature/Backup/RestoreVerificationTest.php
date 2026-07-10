<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Jobs\RunBackupVerification;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteTodoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RestoreVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_restore_verification_surfaces_as_a_critical_todo(): void
    {
        $site = Site::factory()->create(['is_up' => true, 'last_backup_at' => now()]);
        BackupConfig::factory()->create(['site_id' => $site->id, 'is_enabled' => true]);
        Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Completed,
            'verification_status' => 'failed',
        ]);

        $todos = SiteTodoService::forSite($site);

        $verify = collect($todos)->firstWhere('title', 'Backup failed restore verification');
        $this->assertNotNull($verify);
        $this->assertSame('critical', $verify['priority']);
    }

    public function test_verify_backup_now_queues_a_restore_test(): void
    {
        Queue::fake();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id]);
        $backup = Backup::factory()->create(['site_id' => $site->id, 'status' => BackupStatus::Completed]);

        Livewire::actingAs($manager)
            ->test(SiteBackups::class, ['site' => $site])
            ->call('verifyBackupNow', $backup->id);

        Queue::assertPushed(RunBackupVerification::class, fn ($job) => $job->backup->id === $backup->id);
        $this->assertSame('testing', $backup->fresh()->verification_status);
    }

    public function test_viewer_cannot_trigger_a_restore_test(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);
        $backup = Backup::factory()->create(['site_id' => $site->id, 'status' => BackupStatus::Completed]);

        Livewire::actingAs($viewer)
            ->test(SiteBackups::class, ['site' => $site])
            ->call('verifyBackupNow', $backup->id)
            ->assertForbidden();
    }
}
