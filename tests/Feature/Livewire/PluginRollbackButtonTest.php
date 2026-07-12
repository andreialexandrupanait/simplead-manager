<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SitePlugins;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\UpdateLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PluginRollbackButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Site $site;

    private SitePlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->site = Site::factory()->create(['user_id' => $this->manager->id, 'is_connected' => true]);
        $this->plugin = SitePlugin::factory()->create([
            'site_id' => $this->site->id,
            'slug' => 'akismet',
            'name' => 'Akismet Anti-Spam',
            'file' => 'akismet/akismet.php',
            'version' => '5.1',
            'has_update' => false,
        ]);
    }

    private function seedSuccessfulUpdateLog(): void
    {
        // A real forward update stores the DISPLAY NAME in `name` and the slug in
        // `slug`. The rollback lookups must match on `slug`, not `name` (P1-43).
        UpdateLog::create([
            'site_id' => $this->site->id,
            'user_id' => $this->manager->id,
            'type' => 'plugin',
            'name' => 'Akismet Anti-Spam',
            'slug' => 'akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
            'success' => true,
            'performed_at' => now(),
        ]);
    }

    public function test_show_detail_exposes_rollback_when_an_update_log_exists(): void
    {
        $this->seedSuccessfulUpdateLog();

        $component = Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->call('showDetail', 'plugin', $this->plugin->id);

        $detail = $component->get('detailItem');
        $this->assertTrue($detail['can_rollback']);
        $this->assertSame('5.0', $detail['rollback_version']);
    }

    public function test_rollback_reverts_to_previous_version_via_slug_lookup(): void
    {
        $this->seedSuccessfulUpdateLog();

        $fake = $this->fakeApi();
        $fake->script('rollback', ['success' => true]);

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->call('rollbackPlugin', $this->plugin->id)
            ->assertHasNoErrors();

        $fake->assertCalled('rollback');
        $this->assertSame('5.0', $this->plugin->fresh()->version);
        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'to_version' => '5.0',
            'success' => true,
        ]);
    }

    public function test_rollback_is_a_noop_when_no_previous_version_exists(): void
    {
        // No prior successful update log → nothing to roll back to; the connector
        // must never be called and the installed version must not change.
        $fake = $this->fakeApi();

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->call('rollbackPlugin', $this->plugin->id);

        $fake->assertNotCalled('rollback');
        $this->assertSame('5.1', $this->plugin->fresh()->version);
    }
}
