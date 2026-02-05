<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class SiteDetailPageSmokeTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    private User $user;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->site = $this->createSite();
    }

    public function test_site_overview_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.overview', $this->site))
            ->assertOk();
    }

    public function test_site_plugins_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.plugins', $this->site))
            ->assertOk();
    }

    public function test_site_updates_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.updates', $this->site))
            ->assertOk();
    }

    public function test_site_security_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.security', $this->site))
            ->assertOk();
    }

    public function test_site_core_integrity_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.core-integrity', $this->site))
            ->assertOk();
    }

    public function test_site_audit_log_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.audit-log', $this->site))
            ->assertOk();
    }

    public function test_site_firewall_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.firewall', $this->site))
            ->assertOk();
    }

    public function test_site_performance_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.performance', $this->site))
            ->assertOk();
    }

    public function test_site_backups_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.backups', $this->site))
            ->assertOk();
    }

    public function test_site_uptime_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.uptime', $this->site))
            ->assertOk();
    }

    public function test_site_links_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.links', $this->site))
            ->assertOk();
    }

    public function test_site_analytics_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.analytics', $this->site))
            ->assertOk();
    }

    public function test_site_search_console_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.search-console', $this->site))
            ->assertOk();
    }

    public function test_site_maintenance_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.maintenance', $this->site))
            ->assertOk();
    }

    public function test_site_cron_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.cron', $this->site))
            ->assertOk();
    }

    public function test_site_dns_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.dns', $this->site))
            ->assertOk();
    }

    public function test_site_cloudflare_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.cloudflare', $this->site))
            ->assertOk();
    }

    public function test_site_errors_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.errors', $this->site))
            ->assertOk();
    }

    public function test_site_database_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.database', $this->site))
            ->assertOk();
    }

    public function test_site_resources_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.resources', $this->site))
            ->assertOk();
    }

    public function test_site_seo_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.seo', $this->site))
            ->assertOk();
    }

    public function test_site_woocommerce_page_loads(): void
    {
        $wooSite = $this->createSite(['has_woocommerce' => true]);

        $this->actingAs($this->user)
            ->get(route('sites.woocommerce', $wooSite))
            ->assertOk();
    }

    public function test_site_reports_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.reports', $this->site))
            ->assertOk();
    }

    public function test_site_settings_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('sites.settings', $this->site))
            ->assertOk();
    }
}
