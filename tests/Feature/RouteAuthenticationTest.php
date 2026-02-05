<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    // ── Global routes ──────────────────────────────────────────────

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_sites_create_requires_authentication(): void
    {
        $this->get('/sites/create')->assertRedirect('/login');
    }

    public function test_backups_requires_authentication(): void
    {
        $this->get('/backups')->assertRedirect('/login');
    }

    public function test_performance_requires_authentication(): void
    {
        $this->get('/performance')->assertRedirect('/login');
    }

    public function test_uptime_requires_authentication(): void
    {
        $this->get('/uptime')->assertRedirect('/login');
    }

    public function test_updates_requires_authentication(): void
    {
        $this->get('/updates')->assertRedirect('/login');
    }

    public function test_activity_requires_authentication(): void
    {
        $this->get('/activity')->assertRedirect('/login');
    }

    public function test_errors_requires_authentication(): void
    {
        $this->get('/errors')->assertRedirect('/login');
    }

    public function test_clients_requires_authentication(): void
    {
        $this->get('/clients')->assertRedirect('/login');
    }

    public function test_reports_requires_authentication(): void
    {
        $this->get('/reports')->assertRedirect('/login');
    }

    public function test_status_pages_requires_authentication(): void
    {
        $this->get('/status-pages')->assertRedirect('/login');
    }

    public function test_status_pages_create_requires_authentication(): void
    {
        $this->get('/status-pages/create')->assertRedirect('/login');
    }

    public function test_settings_general_requires_authentication(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_settings_notifications_requires_authentication(): void
    {
        $this->get('/settings/notifications')->assertRedirect('/login');
    }

    public function test_settings_profile_requires_authentication(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
    }

    public function test_settings_integrations_requires_authentication(): void
    {
        $this->get('/settings/integrations')->assertRedirect('/login');
    }

    public function test_settings_report_templates_requires_authentication(): void
    {
        $this->get('/settings/report-templates')->assertRedirect('/login');
    }

    // ── Site detail routes ─────────────────────────────────────────

    public function test_site_overview_requires_authentication(): void
    {
        $this->get('/sites/1')->assertRedirect('/login');
    }

    public function test_site_plugins_requires_authentication(): void
    {
        $this->get('/sites/1/plugins')->assertRedirect('/login');
    }

    public function test_site_updates_requires_authentication(): void
    {
        $this->get('/sites/1/updates')->assertRedirect('/login');
    }

    public function test_site_security_requires_authentication(): void
    {
        $this->get('/sites/1/security')->assertRedirect('/login');
    }

    public function test_site_core_integrity_requires_authentication(): void
    {
        $this->get('/sites/1/core-integrity')->assertRedirect('/login');
    }

    public function test_site_audit_log_requires_authentication(): void
    {
        $this->get('/sites/1/audit-log')->assertRedirect('/login');
    }

    public function test_site_firewall_requires_authentication(): void
    {
        $this->get('/sites/1/firewall')->assertRedirect('/login');
    }

    public function test_site_performance_requires_authentication(): void
    {
        $this->get('/sites/1/performance')->assertRedirect('/login');
    }

    public function test_site_backups_requires_authentication(): void
    {
        $this->get('/sites/1/backups')->assertRedirect('/login');
    }

    public function test_site_uptime_requires_authentication(): void
    {
        $this->get('/sites/1/uptime')->assertRedirect('/login');
    }

    public function test_site_links_requires_authentication(): void
    {
        $this->get('/sites/1/links')->assertRedirect('/login');
    }

    public function test_site_analytics_requires_authentication(): void
    {
        $this->get('/sites/1/analytics')->assertRedirect('/login');
    }

    public function test_site_search_console_requires_authentication(): void
    {
        $this->get('/sites/1/search-console')->assertRedirect('/login');
    }

    public function test_site_maintenance_requires_authentication(): void
    {
        $this->get('/sites/1/maintenance')->assertRedirect('/login');
    }

    public function test_site_cron_requires_authentication(): void
    {
        $this->get('/sites/1/cron')->assertRedirect('/login');
    }

    public function test_site_dns_requires_authentication(): void
    {
        $this->get('/sites/1/dns')->assertRedirect('/login');
    }

    public function test_site_cloudflare_requires_authentication(): void
    {
        $this->get('/sites/1/cloudflare')->assertRedirect('/login');
    }

    public function test_site_errors_requires_authentication(): void
    {
        $this->get('/sites/1/errors')->assertRedirect('/login');
    }

    public function test_site_database_requires_authentication(): void
    {
        $this->get('/sites/1/database')->assertRedirect('/login');
    }

    public function test_site_resources_requires_authentication(): void
    {
        $this->get('/sites/1/resources')->assertRedirect('/login');
    }

    public function test_site_seo_requires_authentication(): void
    {
        $this->get('/sites/1/seo')->assertRedirect('/login');
    }

    public function test_site_woocommerce_requires_authentication(): void
    {
        $this->get('/sites/1/woocommerce')->assertRedirect('/login');
    }

    public function test_site_reports_requires_authentication(): void
    {
        $this->get('/sites/1/reports')->assertRedirect('/login');
    }

    public function test_site_settings_requires_authentication(): void
    {
        $this->get('/sites/1/settings')->assertRedirect('/login');
    }
}
