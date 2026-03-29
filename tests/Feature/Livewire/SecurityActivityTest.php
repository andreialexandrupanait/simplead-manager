<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\Security\SecurityActivity;
use App\Models\SecurityActivityLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityActivityTest extends TestCase
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
    public function user_can_view_security_activity_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_with_activity_logs(): void
    {
        SecurityActivityLog::create([
            'site_id' => $this->site->id,
            'event_type' => 'login_failed',
            'event_category' => 'auth',
            'ip_address' => '1.2.3.4',
            'username' => 'hacker',
            'occurred_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── filterEventType ──────────────────────────────────────────────

    #[Test]
    public function user_can_filter_by_event_type(): void
    {
        SecurityActivityLog::create([
            'site_id' => $this->site->id,
            'event_type' => 'login_failed',
            'event_category' => 'auth',
            'ip_address' => '1.2.3.4',
            'username' => 'user1',
            'occurred_at' => now(),
        ]);

        SecurityActivityLog::create([
            'site_id' => $this->site->id,
            'event_type' => 'plugin_activated',
            'event_category' => 'plugin',
            'ip_address' => '5.6.7.8',
            'username' => 'admin',
            'occurred_at' => now(),
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site])
            ->set('filterEventType', 'login_failed');

        $component->assertOk();
        $this->assertEquals('login_failed', $component->get('filterEventType'));
    }

    // ─── filterUsername ───────────────────────────────────────────────

    #[Test]
    public function user_can_filter_by_username(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site])
            ->set('filterUsername', 'hacker');

        $this->assertEquals('hacker', $component->get('filterUsername'));
        $component->assertOk();
    }

    // ─── filterDays ───────────────────────────────────────────────────

    #[Test]
    public function filter_days_defaults_to_seven(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site]);

        $this->assertEquals(7, $component->get('filterDays'));
    }

    #[Test]
    public function filter_days_is_clamped_to_valid_range(): void
    {
        // Setting filterDays triggers updatedFilterDays() which clamps to max 365
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site])
            ->set('filterDays', 500);

        $this->assertEquals(365, $component->get('filterDays'));
    }

    #[Test]
    public function filter_days_minimum_is_one(): void
    {
        // Setting filterDays triggers updatedFilterDays() which clamps to min 1
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site])
            ->set('filterDays', 0);

        $this->assertEquals(1, $component->get('filterDays'));
    }

    // ─── filterIp ─────────────────────────────────────────────────────

    #[Test]
    public function user_can_filter_by_ip_address(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityActivity::class, ['site' => $this->site])
            ->set('filterIp', '192.168.1.1');

        $this->assertEquals('192.168.1.1', $component->get('filterIp'));
        $component->assertOk();
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_security_activity(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityActivity::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
