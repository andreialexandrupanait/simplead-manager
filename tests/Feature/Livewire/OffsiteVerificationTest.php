<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * C-12: the site backups page must warn when a site's backups are not safely
 * reaching a healthy offsite destination — none configured, credentials
 * failing, or replication not happening.
 */
class OffsiteVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Redis::spy();
        Queue::fake();
        Http::fake();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function site(?StorageDestination $secondary): Site
    {
        $site = Site::factory()->create(['user_id' => $this->admin->id]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => StorageDestination::factory()->create(['type' => 'local'])->id,
            'secondary_storage_destination_id' => $secondary?->id,
        ]);

        return $site->fresh();
    }

    private function pageFor(Site $site)
    {
        return Livewire::actingAs($this->admin)->test(SiteBackups::class, ['site' => $site]);
    }

    public function test_missing_banner_when_no_offsite_and_backups_exist(): void
    {
        $site = $this->site(null);
        Backup::factory()->completed()->create(['site_id' => $site->id]);

        $this->pageFor($site)->assertSee('No active offsite backup destination');
    }

    public function test_no_banner_when_no_offsite_but_no_backups_yet(): void
    {
        $site = $this->site(null);

        $this->pageFor($site)->assertDontSee('No active offsite backup destination');
    }

    public function test_failing_banner_when_credential_check_failed(): void
    {
        $offsite = StorageDestination::factory()->create([
            'type' => 's3', 'is_active' => true,
            'last_test_passed' => false, 'last_test_error' => 'Access denied',
        ]);
        $site = $this->site($offsite);
        Backup::factory()->completed()->create(['site_id' => $site->id]);

        $this->pageFor($site)
            ->assertSee('failed its last credential check')
            ->assertSee('Access denied');
    }

    public function test_stale_banner_when_an_old_backup_has_no_offsite_replica(): void
    {
        $offsite = StorageDestination::factory()->create([
            'type' => 's3', 'is_active' => true, 'last_test_passed' => true,
        ]);
        $site = $this->site($offsite);
        // Backup old enough to have replicated, but with no replica recorded.
        Backup::factory()->completed()->create([
            'site_id' => $site->id,
            'created_at' => now()->subDay(),
            'replicas' => [],
        ]);

        $this->pageFor($site)->assertSee('not reaching the offsite destination');
    }

    public function test_no_banner_when_offsite_is_healthy_and_replicating(): void
    {
        $offsite = StorageDestination::factory()->create([
            'type' => 's3', 'is_active' => true, 'last_test_passed' => true,
        ]);
        $site = $this->site($offsite);
        Backup::factory()->completed()->create([
            'site_id' => $site->id,
            'created_at' => now()->subDay(),
            'replicas' => [[
                'destination_id' => $offsite->id,
                'remote_path' => 's3://bucket/backup.zip',
                'uploaded_at' => now()->subDay()->toIso8601String(),
                'status' => 'completed',
            ]],
        ]);

        $this->pageFor($site)
            ->assertDontSee('No active offsite backup destination')
            ->assertDontSee('not reaching the offsite destination')
            ->assertDontSee('failed its last credential check');
    }
}
