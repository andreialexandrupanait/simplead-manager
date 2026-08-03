<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\UpdateDecision;
use App\Enums\UpdateRoute;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteRiskyPlugin;
use App\Models\VulnerabilityAlert;
use App\Services\UpdateDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza 6 (SPEC §7.2/§7.3) — the update-decision engine. Every path is exercised
 * with the routing signals (vulnerability feed, per-site risk list, version
 * bump, recovery preconditions) driven from real DB rows so the pure decision
 * logic is verified end to end.
 */
class UpdateDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    private function service(): UpdateDecisionService
    {
        return new UpdateDecisionService;
    }

    private function plugin(string $version, string $updateVersion, string $slug = 'contact-form-7'): SitePlugin
    {
        return SitePlugin::factory()->for($this->site)->create([
            'slug' => $slug,
            'version' => $version,
            'has_update' => true,
            'update_version' => $updateVersion,
        ]);
    }

    public function test_minor_not_risky_routes_to_auto_minor(): void
    {
        $plugin = $this->plugin('1.2.0', '1.2.1');

        $decision = $this->service()->decide($plugin);

        $this->assertInstanceOf(UpdateDecision::class, $decision);
        $this->assertSame(UpdateRoute::AutoMinor, $decision->route);
    }

    public function test_major_bump_routes_to_await_approval(): void
    {
        $plugin = $this->plugin('1.9.0', '2.0.0');

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
    }

    public function test_minor_but_on_risk_list_routes_to_await_approval(): void
    {
        $plugin = $this->plugin('1.0.0', '1.0.1');

        SiteRiskyPlugin::create([
            'site_id' => $this->site->id,
            'slug' => $plugin->slug,
            'source' => 'manual',
            'reason' => 'Broke checkout last time',
            'is_risky' => true,
        ]);

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
    }

    public function test_active_critical_vulnerability_routes_to_critical_bypass(): void
    {
        $plugin = $this->plugin('1.0.0', '1.0.1');

        VulnerabilityAlert::factory()->for($this->site)->create([
            'software_slug' => $plugin->slug,
            'severity' => 'critical',
            'status' => 'active',
            'fixed_in_version' => '1.0.1',
        ]);

        // Even a perfectly safe-looking minor bump must bypass approval when
        // there's a critical vuln with a reachable fix.
        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::CriticalBypass, $decision->route);
    }

    public function test_critical_vulnerability_takes_precedence_over_major_bump(): void
    {
        $plugin = $this->plugin('1.0.0', '2.0.0');

        VulnerabilityAlert::factory()->for($this->site)->create([
            'software_slug' => $plugin->slug,
            'severity' => 'critical',
            'status' => 'active',
            'fixed_in_version' => '2.0.0',
        ]);

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::CriticalBypass, $decision->route);
    }

    public function test_critical_vuln_without_reachable_fix_does_not_bypass(): void
    {
        // Fix is in 3.0.0 but the update only reaches 2.0.0 — the update does not
        // resolve the vuln, so it must NOT be force-bypassed. It's still a major
        // bump, so it awaits approval.
        $plugin = $this->plugin('1.0.0', '2.0.0');

        VulnerabilityAlert::factory()->for($this->site)->create([
            'software_slug' => $plugin->slug,
            'severity' => 'critical',
            'status' => 'active',
            'fixed_in_version' => '3.0.0',
        ]);

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
    }

    public function test_non_critical_vulnerability_does_not_bypass(): void
    {
        $plugin = $this->plugin('1.0.0', '1.0.1');

        VulnerabilityAlert::factory()->for($this->site)->create([
            'software_slug' => $plugin->slug,
            'severity' => 'high',
            'status' => 'active',
            'fixed_in_version' => '1.0.1',
        ]);

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AutoMinor, $decision->route);
    }

    /**
     * SPEC §7.2 Rule 1 also requires a recovery path before anything auto-applies:
     * a backup newer than 24h and no open incident on the site.
     */
    public function test_a_stale_backup_holds_an_otherwise_automatic_update(): void
    {
        $this->site->forceFill(['last_backup_at' => now()->subDays(3)])->saveQuietly();
        $plugin = $this->plugin('1.2.0', '1.2.1');

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
        $this->assertTrue(
            collect($decision->reasons)->contains(fn (string $r): bool => str_contains($r, '24h')),
            'The operator must be told WHY it is being held.'
        );
    }

    public function test_no_backup_at_all_holds_an_otherwise_automatic_update(): void
    {
        $this->site->forceFill(['last_backup_at' => null])->saveQuietly();
        $plugin = $this->plugin('1.2.0', '1.2.1');

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
    }

    public function test_an_open_incident_holds_an_otherwise_automatic_update(): void
    {
        $monitor = \App\Models\UptimeMonitor::factory()->for($this->site)->create();
        \App\Models\UptimeIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'resolved_at' => null,
        ]);

        $plugin = $this->plugin('1.2.0', '1.2.1');

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
    }

    public function test_a_resolved_incident_does_not_hold_an_update(): void
    {
        $monitor = \App\Models\UptimeMonitor::factory()->for($this->site)->create();
        \App\Models\UptimeIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'resolved_at' => now()->subHour(),
        ]);

        $plugin = $this->plugin('1.2.0', '1.2.1');

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::AutoMinor, $decision->route);
    }

    /**
     * A critical vulnerability is the one thing allowed to skip the gate — the
     * preconditions must not be able to veto it (SPEC §7.2 Rule 3).
     */
    public function test_a_critical_vulnerability_bypasses_even_without_a_recent_backup(): void
    {
        $this->site->forceFill(['last_backup_at' => null])->saveQuietly();
        $plugin = $this->plugin('1.0.0', '1.0.1');

        VulnerabilityAlert::factory()->for($this->site)->create([
            'software_slug' => $plugin->slug,
            'severity' => 'critical',
            'status' => 'active',
            'fixed_in_version' => '1.0.1',
        ]);

        $decision = $this->service()->decide($plugin);

        $this->assertSame(UpdateRoute::CriticalBypass, $decision->route);
    }
}
