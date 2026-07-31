<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteOverview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The second half of "save now, connect later".
 *
 * Registering a site without the connector is only useful if pairing is the
 * next thing you see. Landing on a site page with no visible next step is how
 * the original dead end felt: the credentials dialog exists, but it sits behind
 * a settings icon that gives no hint it is where you finish the job.
 */
class ConnectLaterFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_arriving_with_connect_opens_the_credentials_dialog(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create(['user_id' => $admin->id, 'is_connected' => false]);

        Livewire::actingAs($admin)
            ->withQueryParams(['connect' => 1])
            ->test(SiteOverview::class, ['site' => $site])
            ->assertDispatched('open-modal-connect-plugin');
    }

    public function test_the_endpoint_is_prefilled_from_the_site_url(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create([
            'user_id' => $admin->id,
            'is_connected' => false,
            'url' => 'https://pairing.test',
            'api_endpoint' => null,
        ]);

        Livewire::actingAs($admin)
            ->withQueryParams(['connect' => 1])
            ->test(SiteOverview::class, ['site' => $site])
            ->assertSet('apiEndpoint', 'https://pairing.test/wp-json/simplead/v1');
    }

    public function test_an_already_connected_site_is_left_alone(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create(['user_id' => $admin->id, 'is_connected' => true]);

        Livewire::actingAs($admin)
            ->withQueryParams(['connect' => 1])
            ->test(SiteOverview::class, ['site' => $site])
            ->assertNotDispatched('open-modal-connect-plugin');
    }

    public function test_without_the_flag_nothing_pops_open(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create(['user_id' => $admin->id, 'is_connected' => false]);

        Livewire::actingAs($admin)
            ->test(SiteOverview::class, ['site' => $site])
            ->assertNotDispatched('open-modal-connect-plugin');
    }

    /**
     * openConnectModal() authorizes writes, so a Viewer reaching the page with
     * ?connect=1 must not be aborted out of a page they are allowed to read.
     */
    public function test_a_viewer_can_still_open_the_page(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id, 'is_connected' => false]);

        Livewire::actingAs($viewer)
            ->withQueryParams(['connect' => 1])
            ->test(SiteOverview::class, ['site' => $site])
            ->assertOk()
            ->assertNotDispatched('open-modal-connect-plugin');
    }
}
