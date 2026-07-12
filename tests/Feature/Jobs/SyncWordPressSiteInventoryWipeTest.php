<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\SiteUser;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P1-50: an empty / malformed connector inventory response must never be treated
 * as "everything was deleted". Reconciliation (which prunes local rows the remote
 * no longer lists) may only run against a valid, populated inventory — otherwise
 * a transient blank response would wipe site_plugins/themes/users, including
 * stored plugin license keys.
 */
class SyncWordPressSiteInventoryWipeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake(); // any post-sync service that reaches out gets a benign 200
    }

    private function jsonResponse(array $body): Response
    {
        return new Response(new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /**
     * @param  array<string, mixed>  $plugins
     * @param  array<string, mixed>  $themes
     * @param  array<string, mixed>  $users
     */
    private function bindApi(array $plugins, array $themes, array $users): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getInfo')->willReturn(['wp_version' => '6.5']);
        $api->method('getPlugins')->willReturn($plugins);
        $api->method('getThemes')->willReturn($themes);
        $api->method('getUsers')->willReturn($users);
        $api->method('getDbCleanupStats')->willReturn([]);
        $api->method('request')->willReturn($this->jsonResponse([]));

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    private function seedInventory(Site $site): void
    {
        SitePlugin::create([
            'site_id' => $site->id,
            'file' => 'akismet/akismet.php',
            'slug' => 'akismet',
            'name' => 'Akismet',
            'license_key' => 'SECRET-LICENSE-KEY',
        ]);
        SiteTheme::create([
            'site_id' => $site->id,
            'slug' => 'twentytwentyfour',
            'name' => 'Twenty Twenty-Four',
        ]);
        SiteUser::create([
            'site_id' => $site->id,
            'wp_user_id' => 1,
            'username' => 'admin',
        ]);
    }

    public function test_empty_inventory_response_does_not_wipe_local_rows(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $this->seedInventory($site);

        // Successful HTTP, but the payload carries no items (missing keys).
        $this->bindApi(plugins: [], themes: [], users: []);

        (new SyncWordPressSite($site))->handle();

        $this->assertDatabaseHas('site_plugins', [
            'site_id' => $site->id,
            'file' => 'akismet/akismet.php',
        ]);
        // license_key is an encrypted column — assert the decrypted value survived.
        $plugin = $site->sitePlugins()->where('file', 'akismet/akismet.php')->firstOrFail();
        $this->assertSame('SECRET-LICENSE-KEY', $plugin->license_key);
        $this->assertDatabaseHas('site_themes', ['site_id' => $site->id, 'slug' => 'twentytwentyfour']);
        $this->assertDatabaseHas('site_users', ['site_id' => $site->id, 'wp_user_id' => 1]);
    }

    public function test_explicitly_empty_arrays_do_not_wipe_local_rows(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $this->seedInventory($site);

        // Keys present but empty — still not proof the site has zero of everything.
        $this->bindApi(
            plugins: ['plugins' => []],
            themes: ['themes' => []],
            users: ['users' => []],
        );

        (new SyncWordPressSite($site))->handle();

        $this->assertDatabaseCount('site_plugins', 1);
        $this->assertDatabaseCount('site_themes', 1);
        $this->assertDatabaseCount('site_users', 1);
    }

    public function test_populated_inventory_still_reconciles_and_prunes_stale_rows(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $this->seedInventory($site);

        // A real populated inventory that no longer lists the seeded items must
        // still prune them — the guard only protects against empty/blank payloads.
        $this->bindApi(
            plugins: ['plugins' => [['file' => 'jetpack/jetpack.php', 'slug' => 'jetpack', 'name' => 'Jetpack']]],
            themes: ['themes' => [['slug' => 'astra', 'name' => 'Astra']]],
            users: ['users' => [['id' => 42, 'login' => 'editor']]],
        );

        (new SyncWordPressSite($site))->handle();

        $this->assertDatabaseMissing('site_plugins', ['file' => 'akismet/akismet.php']);
        $this->assertDatabaseHas('site_plugins', ['site_id' => $site->id, 'file' => 'jetpack/jetpack.php']);
        $this->assertDatabaseMissing('site_themes', ['slug' => 'twentytwentyfour']);
        $this->assertDatabaseHas('site_themes', ['site_id' => $site->id, 'slug' => 'astra']);
        $this->assertDatabaseMissing('site_users', ['wp_user_id' => 1]);
        $this->assertDatabaseHas('site_users', ['site_id' => $site->id, 'wp_user_id' => 42]);
    }
}
