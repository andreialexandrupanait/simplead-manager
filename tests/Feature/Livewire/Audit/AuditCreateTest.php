<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Audit;

use App\Enums\AuditStatus;
use App\Enums\UserRole;
use App\Livewire\Audit\AuditCreate;
use App\Models\Prospect;
use App\Models\Site;
use App\Models\User;
use App\Services\Security\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the SSRF guard off the network in tests (subclass no-op).
        app()->instance(SsrfGuard::class, new class extends SsrfGuard
        {
            public function assertPublicUrl(string $url): void {}
        });
    }

    public function test_it_creates_an_audit_for_a_new_prospect(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);

        Livewire::actingAs($user)
            ->test(AuditCreate::class)
            ->set('targetType', 'prospect')
            ->set('prospectMode', 'new')
            ->set('name', 'Acme SRL')
            ->set('prospectUrl', 'https://acme.example')
            ->set('profile', 'B2B_SERVICII')
            ->call('save')
            ->assertRedirect();

        $this->assertDatabaseHas('prospects', ['name' => 'Acme SRL', 'url' => 'https://acme.example']);
        $this->assertDatabaseHas('audits', [
            'url' => 'https://acme.example',
            'status' => AuditStatus::Configurat->value,
            'created_by' => $user->id,
        ]);
    }

    public function test_it_creates_an_audit_for_a_managed_site(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['url' => 'https://managed.example']);

        Livewire::actingAs($user)
            ->test(AuditCreate::class)
            ->set('targetType', 'site')
            ->set('siteId', $site->id)
            ->call('save')
            ->assertRedirect();

        $this->assertDatabaseHas('audits', ['site_id' => $site->id, 'url' => 'https://managed.example']);
    }

    public function test_it_creates_an_audit_for_an_existing_prospect(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);
        $prospect = Prospect::factory()->create(['url' => 'https://existing.example']);

        Livewire::actingAs($user)
            ->test(AuditCreate::class)
            ->set('targetType', 'prospect')
            ->set('prospectMode', 'existing')
            ->set('prospectId', $prospect->id)
            ->call('save')
            ->assertRedirect();

        $this->assertDatabaseHas('audits', ['prospect_id' => $prospect->id, 'url' => 'https://existing.example']);
    }

    public function test_it_validates_new_prospect_fields(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);

        Livewire::actingAs($user)
            ->test(AuditCreate::class)
            ->set('targetType', 'prospect')
            ->set('prospectMode', 'new')
            ->set('name', '')
            ->set('prospectUrl', 'not-a-url')
            ->call('save')
            ->assertHasErrors(['name', 'prospectUrl']);

        $this->assertDatabaseCount('audits', 0);
    }

    public function test_viewers_cannot_open_the_create_page(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)->test(AuditCreate::class)->assertForbidden();
    }
}
