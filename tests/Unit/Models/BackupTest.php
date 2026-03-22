<?php

namespace Tests\Unit\Models;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_casts_status_to_enum(): void
    {
        $backup = Backup::factory()
            ->for(Site::factory()->for(User::factory()))
            ->create();

        $this->assertInstanceOf(BackupStatus::class, $backup->status);
    }

    #[Test]
    public function it_belongs_to_a_site(): void
    {
        $site = Site::factory()->for(User::factory())->create();
        $backup = Backup::factory()->for($site)->create();

        $this->assertTrue($backup->site->is($site));
    }

    #[Test]
    public function completed_factory_state_works(): void
    {
        $backup = Backup::factory()
            ->completed()
            ->for(Site::factory()->for(User::factory()))
            ->create();

        $this->assertSame(BackupStatus::Completed, $backup->status);
        $this->assertSame(100, $backup->progress_percent);
    }

    #[Test]
    public function failed_factory_state_works(): void
    {
        $backup = Backup::factory()
            ->failed()
            ->for(Site::factory()->for(User::factory()))
            ->create();

        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertNotNull($backup->error_message);
        $this->assertNull($backup->file_path);
    }

    #[Test]
    public function pending_factory_state_works(): void
    {
        $backup = Backup::factory()
            ->pending()
            ->for(Site::factory()->for(User::factory()))
            ->create();

        $this->assertSame(BackupStatus::Pending, $backup->status);
        $this->assertSame(0, $backup->progress_percent);
    }

    #[Test]
    public function in_progress_factory_state_works(): void
    {
        $backup = Backup::factory()
            ->inProgress()
            ->for(Site::factory()->for(User::factory()))
            ->create();

        $this->assertSame(BackupStatus::InProgress, $backup->status);
        $this->assertNotNull($backup->stage);
    }

    #[Test]
    public function locked_backups_have_reason(): void
    {
        $backup = Backup::factory()
            ->locked('Critical backup')
            ->for(Site::factory()->for(User::factory()))
            ->create();

        $this->assertTrue($backup->is_locked);
        $this->assertSame('Critical backup', $backup->lock_reason);
    }
}
