<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\CreateSiteWizard;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateSiteWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_create_site_wizard(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSiteWizard::class)
            ->assertOk()
            ->assertSet('step', 1);
    }

    // ─── createSite() ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_create_site_with_valid_url(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSiteWizard::class)
            ->set('form.url', 'https://example-new-site.com')
            ->set('form.name', 'Example New Site')
            ->call('createSite');

        $this->assertDatabaseHas('sites', [
            'url' => 'https://example-new-site.com',
            'name' => 'Example New Site',
            'user_id' => $this->admin->id,
        ]);
    }

    #[Test]
    public function created_site_gets_a_health_state_record(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSiteWizard::class)
            ->set('form.url', 'https://health-state-check.com')
            ->set('form.name', 'Health State Check')
            ->call('createSite');

        $site = Site::where('url', 'https://health-state-check.com')->firstOrFail();

        $this->assertDatabaseHas('site_health_states', [
            'site_id' => $site->id,
            'circuit_state' => 'closed',
        ]);
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function create_site_validates_url(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSiteWizard::class)
            ->set('form.url', 'not-a-valid-url')
            ->set('form.name', 'Some Site')
            ->call('createSite')
            ->assertHasErrors(['form.url']);
    }

    #[Test]
    public function create_site_requires_name(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSiteWizard::class)
            ->set('form.url', 'https://example-named.com')
            ->set('form.name', '')
            ->call('createSite')
            ->assertHasErrors(['form.name']);
    }

    #[Test]
    public function url_must_be_unique(): void
    {
        Site::factory()->for($this->admin)->create(['url' => 'https://already-exists.com']);

        Livewire::actingAs($this->admin)
            ->test(CreateSiteWizard::class)
            ->set('form.url', 'https://already-exists.com')
            ->set('form.name', 'Duplicate')
            ->call('createSite')
            ->assertHasErrors(['form.url']);
    }

    // ─── Step navigation ──────────────────────────────────────────────

    #[Test]
    public function url_is_auto_filled_as_name_from_hostname(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(CreateSiteWizard::class)
            ->set('form.url', 'https://auto-name-example.com');

        $this->assertEquals('auto-name-example.com', $component->get('form.name'));
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_create_sites(): void
    {
        $viewer = User::factory()->viewer()->create();

        // Viewers cannot manage sites — the canManageSites() gate returns false.
        // The wizard component itself does not enforce authorization in mount(),
        // but the SitePolicy::create() gate gates creation.
        $this->assertFalse($viewer->canManageSites());
        $this->assertFalse($viewer->can('create', \App\Models\Site::class));
    }
}
