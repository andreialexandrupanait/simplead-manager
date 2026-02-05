<?php

namespace Tests\Feature\Livewire;

use App\Jobs\RunSecurityScan;
use App\Livewire\Sites\Detail\SiteSecurity;
use App\Models\SecurityIssue;
use App\Models\SecurityRecommendation;
use App\Models\SecurityScan;
use App\Models\User;
use App\Models\VulnerabilityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class SiteSecurityTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    private User $user;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->site = $this->createSite();
        $this->fakeWordPressApi();
    }

    public function test_component_renders(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->assertOk()
            ->assertSee('/ 100');
    }

    public function test_displays_latest_security_scan_score(): void
    {
        SecurityScan::factory()->create([
            'site_id' => $this->site->id,
            'score' => 85,
            'critical_count' => 1,
            'high_count' => 0,
            'medium_count' => 2,
            'low_count' => 0,
            'scanned_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->assertSee('85');
    }

    public function test_can_trigger_new_security_scan(): void
    {
        Bus::fake(RunSecurityScan::class);

        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->call('scanNow');

        Bus::assertDispatched(RunSecurityScan::class, function ($job) {
            return $job->site->id === $this->site->id;
        });
    }

    public function test_can_resolve_security_issue(): void
    {
        $scan = SecurityScan::factory()->create([
            'site_id' => $this->site->id,
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 1,
            'low_count' => 0,
        ]);

        $issue = SecurityIssue::factory()->create([
            'site_id' => $this->site->id,
            'security_scan_id' => $scan->id,
            'severity' => 'medium',
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->call('resolveIssue', $issue->id);

        $this->assertDatabaseHas('security_issues', [
            'id' => $issue->id,
            'is_fixed' => true,
        ]);
    }

    public function test_can_ignore_security_issue(): void
    {
        $scan = SecurityScan::factory()->create([
            'site_id' => $this->site->id,
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 1,
            'low_count' => 0,
        ]);

        $issue = SecurityIssue::factory()->create([
            'site_id' => $this->site->id,
            'security_scan_id' => $scan->id,
            'severity' => 'medium',
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->call('ignoreIssue', $issue->id);

        $this->assertDatabaseHas('security_issues', [
            'id' => $issue->id,
            'is_ignored' => true,
        ]);
    }

    public function test_displays_active_issues_list(): void
    {
        $scan = SecurityScan::factory()->create([
            'site_id' => $this->site->id,
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 1,
            'low_count' => 0,
        ]);

        SecurityIssue::factory()->create([
            'site_id' => $this->site->id,
            'security_scan_id' => $scan->id,
            'title' => 'Missing X-Frame-Options header',
            'severity' => 'medium',
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->assertSee('Missing X-Frame-Options header');
    }

    public function test_displays_vulnerability_alerts(): void
    {
        VulnerabilityAlert::factory()->create([
            'site_id' => $this->site->id,
            'title' => 'WooCommerce SQL Injection',
            'severity' => 'critical',
            'status' => 'active',
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->set('securityTab', 'vulnerabilities')
            ->assertSee('WooCommerce SQL Injection');
    }

    public function test_displays_security_recommendations(): void
    {
        SecurityRecommendation::factory()->create([
            'site_id' => $this->site->id,
            'key' => 'disable_file_editing',
            'category' => 'file_security',
            'title' => 'Disable file editor in wp-admin',
            'status' => 'failed',
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteSecurity::class, ['site' => $this->site])
            ->set('securityTab', 'recommendations')
            ->assertSee('Disable file editor in wp-admin');
    }
}
