<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Jobs\QueueSiteSafeUpdatesJob;
use App\Models\BackupConfig;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Operations\OperationContext;
use App\Operations\OperationRegistry;
use App\Operations\Operations\ApplySitePresetOperation;
use App\Operations\Operations\CreateBackupOperation;
use App\Operations\Operations\ManagePluginOperation;
use App\Operations\Operations\ManageThemeOperation;
use App\Operations\Operations\QueueSafeUpdatesOperation;
use App\Services\PluginManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SPEC §6.2 — the operations that now run through the shared engine.
 *
 * The point of each adapter is the GATE: a site that cannot take the operation
 * is skipped with a reason instead of failing the batch or silently doing
 * nothing. These tests drive prepare/execute/verify directly, which is what
 * RunOperationJob does per site.
 */
class FleetOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function context(Site $site, array $params = []): OperationContext
    {
        return new OperationContext($site, 1, $params, 'run-id', 'tracker-key');
    }

    public function test_every_registered_key_resolves_to_an_operation(): void
    {
        $registry = app(OperationRegistry::class);

        $this->assertGreaterThan(1, count($registry->all()), 'The engine should carry more than the pilot operation.');

        foreach (array_keys($registry->all()) as $key) {
            $op = $registry->resolve($key);
            $this->assertSame($key, $op->key(), "Registry key {$key} disagrees with the operation's own key().");
        }
    }

    public function test_safe_updates_skips_a_site_with_safe_updates_off(): void
    {
        $site = Site::factory()->create(['is_connected' => true, 'safe_updates_enabled' => false]);
        SitePlugin::factory()->for($site)->create(['has_update' => true]);

        $result = app(QueueSafeUpdatesOperation::class)->prepare($this->context($site));

        $this->assertTrue($result->isSkipped());
    }

    public function test_safe_updates_skips_a_site_with_nothing_pending(): void
    {
        $site = Site::factory()->create(['is_connected' => true, 'safe_updates_enabled' => true]);
        SitePlugin::factory()->for($site)->create(['has_update' => false]);

        $this->assertTrue(app(QueueSafeUpdatesOperation::class)->prepare($this->context($site))->isSkipped());
    }

    public function test_safe_updates_queues_the_site_pipeline(): void
    {
        Queue::fake();
        $site = Site::factory()->create(['is_connected' => true, 'safe_updates_enabled' => true]);
        SitePlugin::factory()->for($site)->create(['has_update' => true]);

        $op = app(QueueSafeUpdatesOperation::class);
        $this->assertTrue($op->prepare($this->context($site))->isSuccess());
        $this->assertTrue($op->execute($this->context($site))->isSuccess());

        Queue::assertPushed(QueueSiteSafeUpdatesJob::class, 1);
    }

    public function test_backup_skips_a_site_with_nowhere_to_store_it(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $this->assertTrue(app(CreateBackupOperation::class)->prepare($this->context($site))->isSkipped());
    }

    public function test_backup_queues_when_a_destination_exists(): void
    {
        Queue::fake();
        $site = Site::factory()->create(['is_connected' => true]);
        BackupConfig::factory()->create(['site_id' => $site->id]);

        $op = app(CreateBackupOperation::class);
        $this->assertTrue($op->prepare($this->context($site))->isSuccess());
        $this->assertTrue($op->execute($this->context($site))->isSuccess());

        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id]);
    }

    public function test_preset_verify_fails_when_the_essential_setting_is_absent(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $result = app(ApplySitePresetOperation::class)->verify($this->context($site));

        $this->assertTrue($result->isFailure());
    }

    public function test_preset_verify_passes_once_automatic_updates_are_disabled(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        SecuritySetting::create([
            'site_id' => $site->id,
            'category' => 'site_control', // where SiteTweaksSettingsService files it
            'setting_key' => 'disable_all_updates',
            'setting_value' => true,
            'is_enabled' => true,
        ]);

        $this->assertFalse(app(ApplySitePresetOperation::class)->verify($this->context($site))->isFailure());
    }

    public function test_plugin_operation_skips_a_site_that_does_not_have_the_plugin(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $result = app(ManagePluginOperation::class)->prepare(
            $this->context($site, ['slug' => 'not-installed', 'action' => 'deactivate'])
        );

        $this->assertTrue($result->isSkipped());
    }

    public function test_plugin_operation_skips_when_already_in_the_target_state(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        SitePlugin::factory()->for($site)->create(['slug' => 'akismet', 'is_active' => false]);

        $result = app(ManagePluginOperation::class)->prepare(
            $this->context($site, ['slug' => 'akismet', 'action' => 'deactivate'])
        );

        $this->assertTrue($result->isSkipped());
    }

    public function test_plugin_operation_deactivates_and_verifies(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $plugin = SitePlugin::factory()->for($site)->create(['slug' => 'akismet', 'is_active' => true]);

        $manager = $this->createMock(PluginManagerService::class);
        $manager->expects($this->once())
            ->method('deactivatePlugin')
            ->with($this->anything(), $plugin->id)
            ->willReturnCallback(function () use ($plugin): array {
                $plugin->forceFill(['is_active' => false])->saveQuietly();

                return ['success' => true, 'message' => 'Akismet deactivated.'];
            });

        $op = new ManagePluginOperation($manager);
        $context = $this->context($site, ['slug' => 'akismet', 'action' => 'deactivate']);

        $this->assertTrue($op->prepare($context)->isSuccess());
        $this->assertTrue($op->execute($context)->isSuccess());
        $this->assertFalse($op->verify($context)->isFailure());
    }

    public function test_plugin_and_theme_operations_require_approval(): void
    {
        // A mis-click must not be able to deactivate a plugin or switch a theme
        // across a fleet — the engine holds the batch until it is approved.
        $this->assertTrue(app(ManagePluginOperation::class)->requiresApproval());
        $this->assertTrue(app(ManageThemeOperation::class)->requiresApproval());
    }

    public function test_theme_operation_refuses_to_delete_the_active_theme(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        SiteTheme::factory()->for($site)->create(['slug' => 'twentytwentyfour', 'is_active' => true]);

        $result = app(ManageThemeOperation::class)->prepare(
            $this->context($site, ['slug' => 'twentytwentyfour', 'action' => 'delete'])
        );

        $this->assertTrue($result->isSkipped());
    }

    public function test_a_disconnected_site_is_skipped_by_every_operation(): void
    {
        $site = Site::factory()->create(['is_connected' => false, 'safe_updates_enabled' => true]);
        BackupConfig::factory()->create(['site_id' => $site->id]);
        SitePlugin::factory()->for($site)->create(['slug' => 'akismet', 'is_active' => true, 'has_update' => true]);

        $ops = [
            [app(QueueSafeUpdatesOperation::class), []],
            [app(CreateBackupOperation::class), []],
            [app(ApplySitePresetOperation::class), []],
            [app(ManagePluginOperation::class), ['slug' => 'akismet', 'action' => 'deactivate']],
        ];

        foreach ($ops as [$op, $params]) {
            $this->assertTrue(
                $op->prepare($this->context($site, $params))->isSkipped(),
                $op->key().' should skip a disconnected site.'
            );
        }
    }
}
