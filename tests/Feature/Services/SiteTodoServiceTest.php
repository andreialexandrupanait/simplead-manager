<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Services\SiteTodoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTodoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flags_down_site_missing_backup_and_pending_updates(): void
    {
        $site = Site::factory()->create(['is_up' => false, 'last_backup_at' => null]);
        SitePlugin::factory()->create(['site_id' => $site->id, 'has_update' => true]);

        $todos = SiteTodoService::forSite($site);
        $categories = array_column($todos, 'category');

        $this->assertContains('uptime', $categories);
        $this->assertContains('backups', $categories);
        $this->assertContains('updates', $categories);

        // Critical items sort first.
        $this->assertSame('critical', $todos[0]['priority']);
        $this->assertSame('uptime', $todos[0]['category']);
    }

    public function test_healthy_site_has_an_empty_feed(): void
    {
        $site = Site::factory()->create(['is_up' => true, 'last_backup_at' => now()]);
        BackupConfig::factory()->create(['site_id' => $site->id, 'is_enabled' => true]);

        $this->assertSame([], SiteTodoService::forSite($site));
    }
}
