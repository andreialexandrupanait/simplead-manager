<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * C-10: every sync refreshes the connector's advertised capabilities so
 * operation gating (staged restore) reads current data. A connector that doesn't
 * expose them (returns null) leaves the stored value untouched.
 */
class SyncWordPressSiteCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
    }

    private function bindApi(?array $capabilities): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getInfo')->willReturn(['wp_version' => '6.5', 'plugin_version' => '2.17.1']);
        $api->method('getPlugins')->willReturn(['plugins' => []]);
        $api->method('getThemes')->willReturn(['themes' => []]);
        $api->method('getUsers')->willReturn(['users' => []]);
        $api->method('getDbCleanupStats')->willReturn([]);
        $api->method('getBackupCapabilities')->willReturn($capabilities);
        $api->method('request')->willReturn(new Response(new Psr7Response(200, [], json_encode([]))));

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    public function test_sync_stores_advertised_capabilities(): void
    {
        $this->bindApi(['success' => true, 'staged_restore' => true, 'direct_upload' => true]);
        $site = Site::factory()->create(['backup_capabilities' => null]);

        (new SyncWordPressSite($site))->handle();

        $fresh = $site->fresh();
        $this->assertTrue($fresh->connectorSupports('staged_restore'));
        $this->assertNotNull($fresh->backup_capabilities_checked_at);
    }

    public function test_sync_leaves_capabilities_untouched_when_the_connector_exposes_none(): void
    {
        $this->bindApi(null); // old connector without /backup/capabilities
        $site = Site::factory()->create(['backup_capabilities' => ['staged_restore' => true]]);

        (new SyncWordPressSite($site))->handle();

        // The previously known capability must not be wiped by a null response.
        $this->assertTrue($site->fresh()->connectorSupports('staged_restore'));
    }
}
