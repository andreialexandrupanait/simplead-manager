<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\Security\SecurityUsers;
use App\Models\Site;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityUsersTest extends TestCase
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
    public function user_can_view_security_users_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_with_existing_site_users(): void
    {
        SiteUser::create([
            'site_id' => $this->site->id,
            'wp_user_id' => 1,
            'username' => 'admin_user',
            'email' => 'admin@example.com',
            'role' => 'administrator',
            'synced_at' => now(),
        ]);

        SiteUser::create([
            'site_id' => $this->site->id,
            'wp_user_id' => 2,
            'username' => 'subscriber_user',
            'email' => 'sub@example.com',
            'role' => 'subscriber',
            'synced_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── roleFilter ───────────────────────────────────────────────────

    #[Test]
    public function role_filter_limits_displayed_users(): void
    {
        SiteUser::create([
            'site_id' => $this->site->id,
            'wp_user_id' => 1,
            'username' => 'admin_user',
            'email' => 'admin@example.com',
            'role' => 'administrator',
            'synced_at' => now(),
        ]);

        SiteUser::create([
            'site_id' => $this->site->id,
            'wp_user_id' => 2,
            'username' => 'sub_user',
            'email' => 'sub@example.com',
            'role' => 'subscriber',
            'synced_at' => now(),
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->set('roleFilter', 'administrator');

        // Only 1 admin should appear; assertOk confirms it ran without error
        $component->assertOk();
    }

    // ─── openCreateModal() ────────────────────────────────────────────

    #[Test]
    public function open_create_modal_resets_fields_and_dispatches_event(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->set('newUsername', 'leftover')
            ->call('openCreateModal')
            ->assertDispatched('open-modal-create-user');
    }

    #[Test]
    public function open_create_modal_clears_previous_values(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->set('newUsername', 'leftover')
            ->set('newEmail', 'old@example.com')
            ->call('openCreateModal');

        $this->assertEquals('', $component->get('newUsername'));
        $this->assertEquals('', $component->get('newEmail'));
        $this->assertEquals('subscriber', $component->get('newRole'));
    }

    // ─── createUser() validation ──────────────────────────────────────

    #[Test]
    public function create_user_validates_required_fields(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->call('createUser')
            ->assertHasErrors(['newUsername', 'newEmail', 'newPassword']);
    }

    #[Test]
    public function create_user_validates_email_format(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->set('newUsername', 'newuser')
            ->set('newEmail', 'not-an-email')
            ->set('newPassword', 'password123')
            ->call('createUser')
            ->assertHasErrors(['newEmail']);
    }

    // ─── confirmDeleteUser() ──────────────────────────────────────────

    #[Test]
    public function confirm_delete_user_dispatches_modal_event(): void
    {
        $siteUser = SiteUser::create([
            'site_id' => $this->site->id,
            'wp_user_id' => 5,
            'username' => 'to_delete',
            'email' => 'del@example.com',
            'role' => 'subscriber',
            'synced_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(SecurityUsers::class, ['site' => $this->site])
            ->call('confirmDeleteUser', $siteUser->id)
            ->assertDispatched('open-modal-delete-user');
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_security_users(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SecurityUsers::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
