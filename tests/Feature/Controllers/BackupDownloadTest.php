<?php

namespace Tests\Feature\Controllers;

use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $otherManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->manager()->create();
        $this->otherManager = User::factory()->manager()->create();
    }

    #[Test]
    public function unauthenticated_user_cannot_download_backup(): void
    {
        $site = Site::factory()->for($this->manager)->create();
        $destination = StorageDestination::factory()->local()->create();
        $backup = Backup::factory()->for($site)->for($destination)->completed()->create();

        $signedUrl = URL::signedRoute('backups.download', ['backup' => $backup]);

        $this->get($signedUrl)->assertRedirect(route('login'));
    }

    #[Test]
    public function owner_can_download_own_backup_with_signed_url(): void
    {
        $site = Site::factory()->for($this->manager)->create();
        $destination = StorageDestination::factory()->local()->create();
        $backup = Backup::factory()->for($site)->for($destination)->completed()->create([
            'file_path' => 'test-backup.zip',
        ]);

        // Create the backup file
        $basePath = $destination->config['path'] ?? storage_path('backups');
        @mkdir($basePath, 0755, true);
        file_put_contents($basePath.'/test-backup.zip', 'fake-backup-content');

        $signedUrl = URL::signedRoute('backups.download', ['backup' => $backup]);

        $response = $this->actingAs($this->manager)->get($signedUrl);
        $response->assertOk();

        @unlink($basePath.'/test-backup.zip');
    }

    #[Test]
    public function non_owner_cannot_download_backup(): void
    {
        $site = Site::factory()->for($this->manager)->create();
        $destination = StorageDestination::factory()->local()->create();
        $backup = Backup::factory()->for($site)->for($destination)->completed()->create();

        $signedUrl = URL::signedRoute('backups.download', ['backup' => $backup]);

        $response = $this->actingAs($this->otherManager)->get($signedUrl);
        $response->assertForbidden();
    }

    #[Test]
    public function admin_can_download_any_backup(): void
    {
        $site = Site::factory()->for($this->manager)->create();
        $destination = StorageDestination::factory()->local()->create();
        $backup = Backup::factory()->for($site)->for($destination)->completed()->create([
            'file_path' => 'admin-test-backup.zip',
        ]);

        $basePath = $destination->config['path'] ?? storage_path('backups');
        @mkdir($basePath, 0755, true);
        file_put_contents($basePath.'/admin-test-backup.zip', 'fake-backup-content');

        $signedUrl = URL::signedRoute('backups.download', ['backup' => $backup]);

        $response = $this->actingAs($this->admin)->get($signedUrl);
        $response->assertOk();

        @unlink($basePath.'/admin-test-backup.zip');
    }

    #[Test]
    public function unsigned_url_is_rejected(): void
    {
        $site = Site::factory()->for($this->manager)->create();
        $destination = StorageDestination::factory()->local()->create();
        $backup = Backup::factory()->for($site)->for($destination)->completed()->create();

        $response = $this->actingAs($this->manager)->get(route('backups.download', ['backup' => $backup]));
        $response->assertForbidden();
    }

    #[Test]
    public function non_local_storage_returns_404(): void
    {
        $site = Site::factory()->for($this->manager)->create();
        $destination = StorageDestination::factory()->s3()->create();
        $backup = Backup::factory()->for($site)->for($destination)->completed()->create();

        $signedUrl = URL::signedRoute('backups.download', ['backup' => $backup]);

        $response = $this->actingAs($this->manager)->get($signedUrl);
        $response->assertNotFound();
    }

    #[Test]
    public function missing_file_returns_404(): void
    {
        $site = Site::factory()->for($this->manager)->create();
        $destination = StorageDestination::factory()->local()->create();
        $backup = Backup::factory()->for($site)->for($destination)->completed()->create([
            'file_path' => 'nonexistent-backup.zip',
        ]);

        $signedUrl = URL::signedRoute('backups.download', ['backup' => $backup]);

        $response = $this->actingAs($this->manager)->get($signedUrl);
        $response->assertNotFound();
    }
}
