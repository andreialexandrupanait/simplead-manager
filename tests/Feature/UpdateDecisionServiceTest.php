<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\UpdateDecision;
use App\Enums\UpdateRoute;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteRiskyPlugin;
use App\Models\VulnerabilityAlert;
use App\Services\PluginRiskAssessmentService;
use App\Services\UpdateDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Faza 6 (SPEC §7.2/§7.3) — the update-decision engine. Every path is exercised
 * with the AI assessor mocked (no network, no Claude call), and the three
 * routing signals (vulnerability feed, per-site risk list, version bump) driven
 * from real DB rows so the pure decision logic is verified end to end.
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

    /**
     * Build the service with a mocked assessor returning a fixed assessment,
     * or throwing when $throws is set (fail-safe path).
     *
     * @param  array{score?: int, level?: string, reasons?: array<int, string>}  $assessment
     */
    private function service(array $assessment = ['score' => 10, 'level' => 'safe', 'reasons' => []], bool $throws = false): UpdateDecisionService
    {
        $assessor = Mockery::mock(PluginRiskAssessmentService::class);

        if ($throws) {
            $assessor->shouldReceive('assess')->andThrow(new \RuntimeException('AI unavailable'));
        } else {
            $assessor->shouldReceive('assess')->andReturn($assessment);
        }

        return new UpdateDecisionService($assessor);
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

    public function test_minor_safe_not_risky_routes_to_auto_minor(): void
    {
        $plugin = $this->plugin('1.2.0', '1.2.1');

        $decision = $this->service(['score' => 8, 'level' => 'safe', 'reasons' => []])->decide($plugin);

        $this->assertInstanceOf(UpdateDecision::class, $decision);
        $this->assertSame(UpdateRoute::AutoMinor, $decision->route);
        $this->assertSame(8, $decision->score());
    }

    public function test_major_bump_routes_to_await_approval(): void
    {
        $plugin = $this->plugin('1.9.0', '2.0.0');

        $decision = $this->service(['score' => 12, 'level' => 'safe', 'reasons' => []])->decide($plugin);

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

        $decision = $this->service(['score' => 5, 'level' => 'safe', 'reasons' => []])->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
    }

    public function test_unknown_assessment_routes_to_await_approval(): void
    {
        $plugin = $this->plugin('1.0.0', '1.0.1');

        $decision = $this->service(['score' => 50, 'level' => 'unknown', 'reasons' => ['no data']])->decide($plugin);

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

        // Even a perfectly "safe" minor bump must bypass approval when there's a
        // critical vuln with a reachable fix.
        $decision = $this->service(['score' => 5, 'level' => 'safe', 'reasons' => []])->decide($plugin);

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

        $decision = $this->service(['score' => 90, 'level' => 'risky', 'reasons' => []])->decide($plugin);

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

        $decision = $this->service(['score' => 20, 'level' => 'safe', 'reasons' => []])->decide($plugin);

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

        $decision = $this->service(['score' => 5, 'level' => 'safe', 'reasons' => []])->decide($plugin);

        $this->assertSame(UpdateRoute::AutoMinor, $decision->route);
    }

    public function test_assessment_exception_fails_safe_to_await_approval(): void
    {
        $plugin = $this->plugin('1.0.0', '1.0.1');

        $decision = $this->service(throws: true)->decide($plugin);

        $this->assertSame(UpdateRoute::AwaitApproval, $decision->route);
        $this->assertSame(50, $decision->score());
    }
}
