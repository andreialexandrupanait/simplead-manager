<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\UpdateLog;
use App\Services\PluginManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginManagerServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    public function test_perform_update_creates_update_log(): void
    {
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willReturn([
            'results' => [
                'my-plugin/my-plugin.php' => [
                    'success' => true,
                    'from_version' => '1.0',
                    'to_version' => '2.0',
                ],
            ],
        ]);

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->performUpdate($this->site, 'plugin', 'my-plugin/my-plugin.php', 'My Plugin', 'my-plugin', '1.0', '2.0');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Updated to v2.0', $result['message']);
        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'my-plugin',
            'success' => true,
        ]);
    }

    public function test_perform_update_handles_api_error(): void
    {
        $api = $this->createMockApi();
        $api->method('updatePlugins')->willThrowException(new \RuntimeException('Connection timeout'));

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->performUpdate($this->site, 'plugin', 'my-plugin/my-plugin.php', 'My Plugin', 'my-plugin', '1.0', '2.0');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection timeout', $result['message']);
    }

    public function test_activate_plugin_updates_model(): void
    {
        $plugin = SitePlugin::factory()->inactive()->create([
            'site_id' => $this->site->id,
        ]);

        $api = $this->createMockApi();
        $api->expects($this->once())->method('activatePlugin')->with($plugin->file);

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->activatePlugin($this->site, $plugin->id);

        $this->assertTrue($result['success']);
        $plugin->refresh();
        $this->assertTrue($plugin->is_active);
    }

    public function test_deactivate_plugin_updates_model(): void
    {
        $plugin = SitePlugin::factory()->active()->create([
            'site_id' => $this->site->id,
        ]);

        $api = $this->createMockApi();
        $api->expects($this->once())->method('deactivatePlugin')->with($plugin->file);

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->deactivatePlugin($this->site, $plugin->id);

        $this->assertTrue($result['success']);
        $plugin->refresh();
        $this->assertFalse($plugin->is_active);
    }

    public function test_delete_plugin_removes_model(): void
    {
        $plugin = SitePlugin::factory()->create([
            'site_id' => $this->site->id,
        ]);

        $api = $this->createMockApi();
        $api->expects($this->once())->method('deletePlugin')->with($plugin->file);

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->deletePlugin($this->site, $plugin->id);

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('site_plugins', ['id' => $plugin->id]);
    }

    public function test_bulk_update_plugins_counts_results(): void
    {
        $p1 = SitePlugin::factory()->withUpdate('2.0.0')->create(['site_id' => $this->site->id, 'file' => 'a/a.php']);
        $p2 = SitePlugin::factory()->withUpdate('3.0.0')->create(['site_id' => $this->site->id, 'file' => 'b/b.php']);

        $api = $this->createMockApi();
        $api->method('updatePlugins')->willReturn([
            'results' => [
                'a/a.php' => ['success' => true, 'to_version' => '2.0.0'],
                'b/b.php' => ['success' => false, 'error' => 'Failed'],
            ],
        ]);

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->bulkUpdatePlugins($this->site, [$p1->id, $p2->id]);

        $this->assertSame(1, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(2, UpdateLog::all());
    }

    public function test_bulk_update_empty_returns_zero(): void
    {
        $service = new PluginManagerService($this->createMockApiFactory());

        $result = $service->bulkUpdatePlugins($this->site, [999]);

        $this->assertSame(0, $result['success']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_update_core_reports_success_only_when_connector_confirms(): void
    {
        $api = $this->createMockApi();
        $api->method('updateCore')->willReturn(['success' => true]);

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->updateCore($this->site);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'type' => 'core',
            'success' => true,
        ]);
    }

    public function test_update_core_reports_failure_when_connector_reports_failure(): void
    {
        // P1-19: the old code returned success => true regardless of the
        // connector result, hiding real core-update failures.
        $api = $this->createMockApi();
        $api->method('updateCore')->willReturn(['success' => false, 'error' => 'Could not create directory.']);

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->updateCore($this->site);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not create directory', $result['message']);
        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'type' => 'core',
            'success' => false,
        ]);
    }

    public function test_delete_plugin_handles_api_failure(): void
    {
        $plugin = SitePlugin::factory()->create(['site_id' => $this->site->id]);

        $api = $this->createMockApi();
        $api->method('deletePlugin')->willThrowException(new \RuntimeException('Forbidden'));

        $service = new PluginManagerService($this->createMockApiFactory($api));
        $result = $service->deletePlugin($this->site, $plugin->id);

        $this->assertFalse($result['success']);
        // Plugin should still exist in DB since API failed
        $this->assertDatabaseHas('site_plugins', ['id' => $plugin->id]);
    }
}
