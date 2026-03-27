<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SitePlugin;
use App\Services\SecurityRecommendationService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncWordPressSiteTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create([
            'wp_version' => '6.4.0',
            'php_version' => '8.1.0',
        ]);

        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'closed',
        ]);
    }

    /**
     * Bind a mock WordPressApiServiceFactory with fully stubbed API responses.
     */
    private function bindMockFactory(WordPressApiServiceInterface $mockApi): void
    {
        $mockFactory = Mockery::mock(WordPressApiServiceFactory::class);
        $mockFactory->shouldReceive('make')->andReturn($mockApi);
        $this->app->instance(WordPressApiServiceFactory::class, $mockFactory);
    }

    /**
     * Build an API mock returning sensible defaults for all sync methods.
     */
    private function buildApiMock(array $overrides = []): WordPressApiServiceInterface
    {
        $mockApi = Mockery::mock(WordPressApiServiceInterface::class);

        $mockApi->shouldReceive('getInfo')->andReturn(
            $overrides['info'] ?? [
                'wp_version' => '6.5.2',
                'php_version' => '8.2.0',
                'server_software' => 'nginx/1.24',
                'is_multisite' => false,
                'db_size_mb' => 42.5,
                'uploads_size_mb' => 120.0,
                'core_new_version' => null,
                'plugin_version' => '1.2.3',
            ]
        );

        $mockApi->shouldReceive('getPlugins')->andReturn(
            $overrides['plugins'] ?? ['plugins' => []]
        );

        $mockApi->shouldReceive('getThemes')->andReturn(
            $overrides['themes'] ?? ['themes' => []]
        );

        $mockApi->shouldReceive('getUsers')->andReturn(
            $overrides['users'] ?? ['users' => []]
        );

        $mockApi->shouldReceive('getDbCleanupStats')->andReturn(
            $overrides['dbStats'] ?? []
        );

        return $mockApi;
    }

    /**
     * Stub out PluginConflictService and SecurityRecommendationService so they
     * don't require real plugin/theme data in tests that don't exercise them.
     */
    private function stubSideEffectServices(): void
    {
        // PluginConflictService::checkSite is a static call — let it run since
        // it gracefully handles an empty plugin list (no-op in tests).

        $mockSecurity = Mockery::mock(SecurityRecommendationService::class);
        $mockSecurity->shouldReceive('check')->andReturn([]);
        $this->app->instance(SecurityRecommendationService::class, $mockSecurity);
    }

    #[Test]
    public function sync_updates_site_info_from_api(): void
    {
        $mockApi = $this->buildApiMock([
            'info' => [
                'wp_version' => '6.5.2',
                'php_version' => '8.2.0',
                'server_software' => 'nginx/1.24',
                'is_multisite' => false,
                'db_size_mb' => 42.5,
                'uploads_size_mb' => 120.0,
                'core_new_version' => null,
                'plugin_version' => '1.2.3',
            ],
        ]);

        $this->bindMockFactory($mockApi);
        $this->stubSideEffectServices();

        (new SyncWordPressSite($this->site))->handle();

        $site = $this->site->fresh();
        $this->assertEquals('6.5.2', $site->wp_version);
        $this->assertEquals('8.2.0', $site->php_version);
        $this->assertEquals('nginx/1.24', $site->server_software);
        $this->assertEquals('1.2.3', $site->connector_version);
        $this->assertTrue($site->is_connected);
        $this->assertNotNull($site->last_synced_at);
    }

    #[Test]
    public function sync_creates_and_updates_plugins(): void
    {
        $mockApi = $this->buildApiMock([
            'plugins' => [
                'plugins' => [
                    [
                        'file' => 'woocommerce/woocommerce.php',
                        'slug' => 'woocommerce',
                        'name' => 'WooCommerce',
                        'version' => '8.0.0',
                        'author' => 'Automattic',
                        'status' => 'active',
                        'update_available' => false,
                        'new_version' => null,
                    ],
                ],
            ],
        ]);

        $this->bindMockFactory($mockApi);
        $this->stubSideEffectServices();

        (new SyncWordPressSite($this->site))->handle();

        $this->assertDatabaseHas('site_plugins', [
            'site_id' => $this->site->id,
            'file' => 'woocommerce/woocommerce.php',
            'name' => 'WooCommerce',
            'version' => '8.0.0',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function sync_removes_deleted_plugins(): void
    {
        // Pre-existing plugin that the remote site no longer reports
        SitePlugin::factory()->for($this->site)->create([
            'file' => 'old-plugin/old-plugin.php',
            'slug' => 'old-plugin',
        ]);

        $mockApi = $this->buildApiMock([
            'plugins' => ['plugins' => []], // Remote reports zero plugins
        ]);

        $this->bindMockFactory($mockApi);
        $this->stubSideEffectServices();

        (new SyncWordPressSite($this->site))->handle();

        $this->assertDatabaseMissing('site_plugins', [
            'site_id' => $this->site->id,
            'file' => 'old-plugin/old-plugin.php',
        ]);
    }

    #[Test]
    public function sync_creates_and_updates_themes(): void
    {
        $mockApi = $this->buildApiMock([
            'themes' => [
                'themes' => [
                    [
                        'slug' => 'astra',
                        'name' => 'Astra',
                        'version' => '4.0.0',
                        'author' => 'Brainstorm Force',
                        'status' => 'active',
                        'update_available' => false,
                        'new_version' => null,
                        'parent_theme' => null,
                        'is_child_theme' => false,
                    ],
                ],
            ],
        ]);

        $this->bindMockFactory($mockApi);
        $this->stubSideEffectServices();

        (new SyncWordPressSite($this->site))->handle();

        $this->assertDatabaseHas('site_themes', [
            'site_id' => $this->site->id,
            'slug' => 'astra',
            'name' => 'Astra',
            'version' => '4.0.0',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function sync_marks_site_disconnected_on_failure(): void
    {
        $mockApi = Mockery::mock(WordPressApiServiceInterface::class);
        $mockApi->shouldReceive('getInfo')->andThrow(new \RuntimeException('Connection refused'));

        $this->bindMockFactory($mockApi);

        try {
            (new SyncWordPressSite($this->site))->handle();
        } catch (\Throwable) {
            // Expected — job rethrows after marking the site disconnected
        }

        $this->assertFalse($this->site->fresh()->is_connected);
    }

    #[Test]
    public function sync_updates_pending_count(): void
    {
        $mockApi = $this->buildApiMock([
            'plugins' => [
                'plugins' => [
                    [
                        'file' => 'woocommerce/woocommerce.php',
                        'slug' => 'woocommerce',
                        'name' => 'WooCommerce',
                        'version' => '7.9.0',
                        'author' => 'Automattic',
                        'status' => 'active',
                        'update_available' => true,
                        'new_version' => '8.0.0',
                    ],
                    [
                        'file' => 'elementor/elementor.php',
                        'slug' => 'elementor',
                        'name' => 'Elementor',
                        'version' => '3.20.0',
                        'author' => 'Elementor',
                        'status' => 'active',
                        'update_available' => false,
                        'new_version' => null,
                    ],
                ],
            ],
            'themes' => [
                'themes' => [
                    [
                        'slug' => 'astra',
                        'name' => 'Astra',
                        'version' => '3.9.0',
                        'author' => 'Brainstorm Force',
                        'status' => 'active',
                        'update_available' => true,
                        'new_version' => '4.0.0',
                        'parent_theme' => null,
                        'is_child_theme' => false,
                    ],
                ],
            ],
            // No core update
            'info' => [
                'wp_version' => '6.5.2',
                'php_version' => '8.2.0',
                'server_software' => 'nginx/1.24',
                'is_multisite' => false,
                'db_size_mb' => 42.5,
                'uploads_size_mb' => 120.0,
                'core_new_version' => null,
                'plugin_version' => '1.2.3',
            ],
        ]);

        $this->bindMockFactory($mockApi);
        $this->stubSideEffectServices();

        (new SyncWordPressSite($this->site))->handle();

        // 1 plugin update + 1 theme update + 0 core updates = 2
        $this->assertEquals(2, $this->site->fresh()->pending_updates_count);
    }
}
