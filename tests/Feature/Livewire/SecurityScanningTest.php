<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CheckCoreFileIntegrity;
use App\Jobs\RunSecurityScan;
use App\Livewire\Sites\Detail\Security\SecurityScanning;
use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityScanningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_security_scanning_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityScanning::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_with_completed_scan(): void
    {
        SecurityScan::factory()->for($this->site)->clean()->create();

        Livewire::actingAs($this->admin)
            ->test(SecurityScanning::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── scanNow() ────────────────────────────────────────────────────

    #[Test]
    public function user_can_trigger_security_scan(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityScanning::class, ['site' => $this->site])
            ->call('scanNow');

        Queue::assertPushed(RunSecurityScan::class, function (RunSecurityScan $job) {
            return $job->site->id === $this->site->id;
        });
    }

    // ─── checkCoreIntegrityNow() ──────────────────────────────────────

    #[Test]
    public function user_can_trigger_core_integrity_check(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityScanning::class, ['site' => $this->site])
            ->call('checkCoreIntegrityNow');

        Queue::assertPushed(CheckCoreFileIntegrity::class, function (CheckCoreFileIntegrity $job) {
            return $job->site->id === $this->site->id;
        });
    }

    // ─── resolveIssue() / ignoreIssue() ──────────────────────────────

    #[Test]
    public function resolve_issue_only_affects_issues_belonging_to_the_site(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        $otherIssue = SecurityIssue::create([
            'site_id' => $otherSite->id,
            'category' => 'malware',
            'type' => 'suspicious_file',
            'severity' => 'high',
            'title' => 'Suspicious file',
            'description' => 'Found suspicious file',
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        // Should silently skip — the issue belongs to a different site
        Livewire::actingAs($this->admin)
            ->test(SecurityScanning::class, ['site' => $this->site])
            ->call('resolveIssue', $otherIssue->id)
            ->assertOk();

        $this->assertFalse($otherIssue->fresh()->is_fixed);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_security_scanning(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityScanning::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
