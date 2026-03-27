<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CreateBackup;
use App\Livewire\Backups\BackupsOverview;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StorageDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->destination = StorageDestination::factory()->default()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_backups_overview(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        Backup::factory()
            ->count(3)
            ->for($site)
            ->for($this->destination)
            ->completed()
            ->create();

        Livewire::actingAs($this->admin)
            ->test(BackupsOverview::class)
            ->assertOk();
    }

    // ─── Search ───────────────────────────────────────────────────────

    #[Test]
    public function user_can_search_backups_by_site_url(): void
    {
        // CQ-1 fix: search uses `url` column, not `domain`
        $targetSite = Site::factory()->for($this->admin)->create([
            'url' => 'https://target-site.example.com',
            'name' => 'Unique Target Site',
        ]);
        $otherSite = Site::factory()->for($this->admin)->create([
            'url' => 'https://other-site.example.com',
            'name' => 'Other Site',
        ]);

        Backup::factory()->for($targetSite)->for($this->destination)->completed()->create();
        Backup::factory()->for($otherSite)->for($this->destination)->completed()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(BackupsOverview::class)
            ->set('search', 'target-site.example.com');

        // The paginated result should only contain the backup for the target site
        $backups = $component->viewData('backups');
        $this->assertCount(1, $backups);
        $this->assertEquals($targetSite->id, $backups->first()->site_id);
    }

    // ─── Filter by status ─────────────────────────────────────────────

    #[Test]
    public function user_can_filter_by_completed_status(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        Backup::factory()->for($site)->for($this->destination)->completed()->create();
        Backup::factory()->for($site)->for($this->destination)->failed()->create();
        Backup::factory()->for($site)->for($this->destination)->pending()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(BackupsOverview::class)
            ->set('filter', 'completed');

        $backups = $component->viewData('backups');

        $this->assertTrue($backups->every(fn ($b) => $b->status->value === 'completed'));
        $this->assertCount(1, $backups);
    }

    #[Test]
    public function user_can_filter_by_failed_status(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        Backup::factory()->for($site)->for($this->destination)->completed()->create();
        Backup::factory()->for($site)->for($this->destination)->failed()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(BackupsOverview::class)
            ->set('filter', 'failed');

        $backups = $component->viewData('backups');

        $this->assertCount(1, $backups);
        $this->assertEquals('failed', $backups->first()->status->value);
    }

    #[Test]
    public function user_can_filter_by_in_progress_status(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        Backup::factory()->for($site)->for($this->destination)->completed()->create();
        Backup::factory()->for($site)->for($this->destination)->inProgress()->create();
        Backup::factory()->for($site)->for($this->destination)->pending()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(BackupsOverview::class)
            ->set('filter', 'in_progress');

        $backups = $component->viewData('backups');

        // in_progress filter matches both 'pending' and 'in_progress' statuses
        $this->assertCount(2, $backups);
        foreach ($backups as $backup) {
            $this->assertContains($backup->status->value, ['pending', 'in_progress']);
        }
    }

    // ─── backupAllSites() ─────────────────────────────────────────────

    #[Test]
    public function admin_can_trigger_backup_all_sites(): void
    {
        Queue::fake();

        $siteA = Site::factory()->for($this->admin)->create(['is_connected' => true]);
        $siteB = Site::factory()->for($this->admin)->create(['is_connected' => true]);

        BackupConfig::factory()->for($siteA)->for($this->destination)->create([
            'is_enabled' => true,
            'type' => 'full',
        ]);
        BackupConfig::factory()->for($siteB)->for($this->destination)->create([
            'is_enabled' => true,
            'type' => 'full',
        ]);

        Livewire::actingAs($this->admin)
            ->test(BackupsOverview::class)
            ->call('backupAllSites')
            ->assertDispatched('notify');

        Queue::assertPushed(CreateBackup::class, 2);
    }

    #[Test]
    public function backup_all_skips_disconnected_sites(): void
    {
        Queue::fake();

        $connectedSite = Site::factory()->for($this->admin)->create(['is_connected' => true]);
        $disconnectedSite = Site::factory()->for($this->admin)->disconnected()->create();

        BackupConfig::factory()->for($connectedSite)->for($this->destination)->create([
            'is_enabled' => true,
            'type' => 'full',
        ]);
        BackupConfig::factory()->for($disconnectedSite)->for($this->destination)->create([
            'is_enabled' => true,
            'type' => 'full',
        ]);

        Livewire::actingAs($this->admin)
            ->test(BackupsOverview::class)
            ->call('backupAllSites');

        // Only the connected site should have been queued
        Queue::assertPushed(CreateBackup::class, 1);
    }
}
