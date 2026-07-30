<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\UserRole;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Sites\ConnectSite;
use App\Models\Site;
use App\Models\User;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §10 — "Un singur ecran, nu un wizard": paste a URL, the app detects what
 * is there, proposes a profile, you confirm, and the first backup + reference
 * scan run on save.
 *
 * The old wizard was four gated steps that collected no credentials at all, so
 * a "created" site was not connected to anything.
 */
class ConnectSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function bindConnector(array $info, array $plugins): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getInfo')->willReturn($info);
        $api->method('getPlugins')->willReturn(['plugins' => $plugins]);

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    private function failingConnector(string $message): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getInfo')->willThrowException(new \RuntimeException($message));

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    public function test_detection_reports_what_the_connector_found(): void
    {
        $this->bindConnector(
            ['wp_version' => '6.9.4', 'php_version' => '8.3.32', 'plugin_version' => '2.19.2', 'is_multisite' => false],
            [
                ['slug' => 'woocommerce'],
                ['slug' => 'elementor-pro'],
                ['slug' => 'contact-form-7'],
                ['slug' => 'akismet'],
            ],
        );

        $component = Livewire::test(ConnectSite::class)
            ->set('url', 'https://detect-me.test')
            ->set('apiKey', 'key-1234567890')
            ->set('apiSecret', 'secret-1234567890')
            ->call('detect');

        $detected = $component->get('detected');

        $this->assertSame('ok', $component->get('detectionStatus'));
        $this->assertSame('6.9.4', $detected['wp_version']);
        $this->assertSame('8.3.32', $detected['php_version']);
        $this->assertTrue($detected['has_woocommerce']);
        $this->assertSame('contact-form-7', $detected['form_plugin']);
        $this->assertSame(4, $detected['plugin_count']);
    }

    public function test_the_proposed_risk_list_is_shown_before_anything_is_saved(): void
    {
        $this->bindConnector(
            ['wp_version' => '6.9.4'],
            [['slug' => 'woocommerce'], ['slug' => 'elementor-pro'], ['slug' => 'akismet']],
        );

        $component = Livewire::test(ConnectSite::class)
            ->set('url', 'https://preview.test')
            ->set('apiKey', 'key-1234567890')
            ->set('apiSecret', 'secret-1234567890')
            ->call('detect');

        $risky = $component->get('detected')['risky_plugins'];

        $this->assertContains('woocommerce', $risky);
        $this->assertContains('elementor-pro', $risky);
        $this->assertNotContains('akismet', $risky);

        // A preview writes nothing.
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('site_risky_plugins', 0);
    }

    public function test_an_unreachable_connector_is_explained_not_swallowed(): void
    {
        $this->failingConnector('HTTP request returned status code 404');

        $component = Livewire::test(ConnectSite::class)
            ->set('url', 'https://no-connector.test')
            ->set('apiKey', 'key-1234567890')
            ->set('apiSecret', 'secret-1234567890')
            ->call('detect');

        $this->assertSame('error', $component->get('detectionStatus'));
        $this->assertStringContainsString('connector', strtolower((string) $component->get('detectionMessage')));
        // A failed probe must not leave a half-created site behind.
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_bad_credentials_say_so(): void
    {
        $this->failingConnector('HTTP request returned status code 401');

        $component = Livewire::test(ConnectSite::class)
            ->set('url', 'https://bad-key.test')
            ->set('apiKey', 'key-1234567890')
            ->set('apiSecret', 'secret-1234567890')
            ->call('detect');

        $this->assertStringContainsString('credentials', strtolower((string) $component->get('detectionMessage')));
    }

    public function test_connecting_stores_credentials_and_starts_the_first_sync(): void
    {
        Queue::fake();
        $this->bindConnector(['wp_version' => '6.9.4'], [['slug' => 'akismet']]);

        Livewire::test(ConnectSite::class)
            ->set('url', 'https://connect-me.test')
            ->set('name', 'Connect Me')
            ->set('apiKey', 'key-1234567890')
            ->set('apiSecret', 'secret-1234567890')
            ->call('detect')
            ->call('connect');

        $site = Site::where('url', 'https://connect-me.test')->firstOrFail();

        $this->assertSame('Connect Me', $site->name);
        $this->assertSame('key-1234567890', $site->api_key);
        $this->assertSame('https://connect-me.test/wp-json/simplead/v1', $site->api_endpoint);

        // The first sync is what applies the presets, derives the key URLs, runs
        // the reference scan and takes the first backup (§10 step 6).
        Queue::assertPushed(SyncWordPressSite::class, 1);
    }

    public function test_a_site_cannot_be_connected_before_it_answers(): void
    {
        Queue::fake();

        Livewire::test(ConnectSite::class)
            ->set('url', 'https://unverified.test')
            ->set('name', 'Unverified')
            ->set('apiKey', 'key-1234567890')
            ->set('apiSecret', 'secret-1234567890')
            ->call('connect')
            ->assertHasErrors('apiKey');

        $this->assertDatabaseCount('sites', 0);
        Queue::assertNotPushed(SyncWordPressSite::class);
    }

    public function test_the_url_is_prefilled_as_the_name(): void
    {
        Livewire::test(ConnectSite::class)
            ->set('url', 'https://naming.test')
            ->assertSet('name', 'naming.test');
    }

    public function test_a_viewer_cannot_connect_a_site(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));
        $this->bindConnector(['wp_version' => '6.9.4'], []);

        Livewire::test(ConnectSite::class)
            ->set('url', 'https://viewer.test')
            ->set('name', 'Viewer')
            ->set('apiKey', 'key-1234567890')
            ->set('apiSecret', 'secret-1234567890')
            ->call('detect')
            ->call('connect')
            ->assertForbidden();
    }
}
