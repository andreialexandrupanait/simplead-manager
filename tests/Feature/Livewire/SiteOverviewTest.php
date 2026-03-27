<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CreateBackup;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Sites\Detail\SiteOverview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->user)->create();
    }

    #[Test]
    public function user_can_view_site_overview(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteOverview::class, ['site' => $this->site])
            ->assertSuccessful();
    }

    #[Test]
    public function user_can_trigger_backup(): void
    {
        Queue::fake();

        Livewire::actingAs($this->user)
            ->test(SiteOverview::class, ['site' => $this->site])
            ->call('runBackup')
            ->assertDispatched('notify');

        Queue::assertPushed(CreateBackup::class);
    }

    #[Test]
    public function user_can_trigger_sync(): void
    {
        Queue::fake();

        Livewire::actingAs($this->user)
            ->test(SiteOverview::class, ['site' => $this->site])
            ->call('syncNow');

        Queue::assertPushed(SyncWordPressSite::class);
    }

    #[Test]
    public function user_can_save_credentials(): void
    {
        Queue::fake();

        Livewire::actingAs($this->user)
            ->test(SiteOverview::class, ['site' => $this->site])
            ->set('apiKey', 'test-api-key-12345')
            ->set('apiSecret', 'test-api-secret-12345')
            ->set('apiEndpoint', 'https://example.com/wp-json/simplead/v1')
            ->call('saveCredentials');

        $this->site->refresh();
        $this->assertNotNull($this->site->api_key);
        $this->assertNotNull($this->site->api_secret);
        $this->assertEquals('https://example.com/wp-json/simplead/v1', $this->site->api_endpoint);

        Queue::assertPushed(SyncWordPressSite::class);
    }

    #[Test]
    public function save_credentials_validates_input(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteOverview::class, ['site' => $this->site])
            ->set('apiKey', 'short')
            ->set('apiSecret', 'short')
            ->set('apiEndpoint', 'not-a-url')
            ->call('saveCredentials')
            ->assertHasErrors(['apiKey', 'apiSecret', 'apiEndpoint']);
    }

    #[Test]
    public function user_can_disconnect_site(): void
    {
        $this->site->update([
            'api_key' => 'some-key',
            'api_secret' => 'some-secret',
            'api_endpoint' => 'https://example.com/wp-json/simplead/v1',
            'is_connected' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteOverview::class, ['site' => $this->site])
            ->call('disconnectSite');

        $this->site->refresh();
        $this->assertNull($this->site->api_key);
        $this->assertNull($this->site->api_secret);
        $this->assertNull($this->site->api_endpoint);
        $this->assertFalse($this->site->is_connected);
    }

    #[Test]
    public function viewer_cannot_access_other_users_site(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherUser = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherUser)->create();

        Livewire::actingAs($viewer)
            ->test(SiteOverview::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
