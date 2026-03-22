<?php

namespace Tests\Feature\Controllers;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->admin()->create();
    }

    #[Test]
    public function dashboard_loads_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertStatus(200);
    }

    #[Test]
    public function site_overview_loads(): void
    {
        $site = Site::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->get(route('sites.overview', $site));

        $response->assertStatus(200);
    }

    #[Test]
    public function site_create_page_loads(): void
    {
        $response = $this->actingAs($this->user)->get(route('sites.create'));

        $response->assertStatus(200);
    }

    #[Test]
    public function clients_list_loads(): void
    {
        $response = $this->actingAs($this->user)->get(route('clients.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function client_create_page_loads(): void
    {
        $response = $this->actingAs($this->user)->get(route('clients.create'));

        $response->assertStatus(200);
    }

    #[Test]
    public function backups_overview_loads(): void
    {
        $response = $this->actingAs($this->user)->get(route('backups.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function uptime_overview_loads(): void
    {
        $response = $this->actingAs($this->user)->get(route('uptime.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function performance_overview_loads(): void
    {
        $response = $this->actingAs($this->user)->get(route('performance.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function reports_overview_loads(): void
    {
        $response = $this->actingAs($this->user)->get(route('reports.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function settings_pages_load_for_admin(): void
    {
        $response = $this->actingAs($this->user)->get(route('settings.general'));
        $response->assertStatus(200);

        $response = $this->actingAs($this->user)->get(route('settings.notifications'));
        $response->assertStatus(200);

        $response = $this->actingAs($this->user)->get(route('settings.profile'));
        $response->assertStatus(200);
    }

    #[Test]
    public function site_detail_pages_load(): void
    {
        $site = Site::factory()->for($this->user)->create();

        $pages = [
            'sites.plugins',
            'sites.security',
            'sites.performance',
            'sites.backups',
            'sites.uptime',
            'sites.settings',
        ];

        foreach ($pages as $route) {
            $response = $this->actingAs($this->user)->get(route($route, $site));
            $response->assertStatus(200, "Route {$route} failed");
        }
    }
}
