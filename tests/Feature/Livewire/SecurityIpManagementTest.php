<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\PushSecuritySettings;
use App\Livewire\Sites\Detail\Security\SecurityIpManagement;
use App\Models\SecurityIpList;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityIpManagementTest extends TestCase
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
    public function user_can_view_ip_management_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── addIp() — whitelist ──────────────────────────────────────────

    #[Test]
    public function user_can_add_ip_to_whitelist(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->set('subTab', 'whitelist')
            ->set('newIp', '192.168.1.100')
            ->set('newReason', 'Office IP')
            ->call('addIp');

        $this->assertDatabaseHas('security_ip_lists', [
            'site_id' => $this->site->id,
            'ip_address' => '192.168.1.100',
            'list_type' => 'whitelist',
            'reason' => 'Office IP',
        ]);

        Queue::assertPushed(PushSecuritySettings::class);
    }

    // ─── addIp() — blocklist ──────────────────────────────────────────

    #[Test]
    public function user_can_add_ip_to_blocklist(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->set('subTab', 'blocklist')
            ->set('newIp', '10.0.0.55')
            ->set('newReason', 'Suspicious activity')
            ->call('addIp');

        $this->assertDatabaseHas('security_ip_lists', [
            'site_id' => $this->site->id,
            'ip_address' => '10.0.0.55',
            'list_type' => 'blocklist',
        ]);
    }

    #[Test]
    public function user_can_add_cidr_range(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->set('subTab', 'whitelist')
            ->set('newIp', '192.168.1.0/24')
            ->call('addIp');

        $this->assertDatabaseHas('security_ip_lists', [
            'site_id' => $this->site->id,
            'ip_address' => '192.168.1.0/24',
            'list_type' => 'whitelist',
        ]);
    }

    // ─── removeIp() ───────────────────────────────────────────────────

    #[Test]
    public function user_can_remove_ip_from_list(): void
    {
        Queue::fake();

        $entry = SecurityIpList::create([
            'site_id' => $this->site->id,
            'ip_address' => '1.2.3.4',
            'list_type' => 'whitelist',
        ]);

        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->call('removeIp', $entry->id);

        $this->assertDatabaseMissing('security_ip_lists', ['id' => $entry->id]);
        Queue::assertPushed(PushSecuritySettings::class);
    }

    #[Test]
    public function user_cannot_remove_ip_belonging_to_another_site(): void
    {
        Queue::fake();

        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        $entry = SecurityIpList::create([
            'site_id' => $otherSite->id,
            'ip_address' => '5.5.5.5',
            'list_type' => 'whitelist',
        ]);

        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->call('removeIp', $entry->id);

        // The entry must still exist — site ownership check prevents deletion
        $this->assertDatabaseHas('security_ip_lists', ['id' => $entry->id]);
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function add_ip_requires_non_empty_ip_field(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->set('newIp', '')
            ->call('addIp')
            ->assertHasErrors(['newIp' => 'required']);
    }

    #[Test]
    public function add_ip_rejects_invalid_ip_format(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->set('newIp', 'not-an-ip')
            ->call('addIp')
            ->assertHasErrors(['newIp']);
    }

    #[Test]
    public function add_ip_rejects_invalid_cidr_prefix(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityIpManagement::class, ['site' => $this->site])
            ->set('newIp', '192.168.1.0/99')
            ->call('addIp')
            ->assertHasErrors(['newIp']);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_ip_management(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityIpManagement::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
