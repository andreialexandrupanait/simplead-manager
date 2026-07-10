<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteRedirects;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteRedirectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_add_a_normalised_redirect(): void
    {
        $this->fakeApi(); // so the push to the connector is hermetic

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'url' => 'https://acme.com']);

        Livewire::actingAs($manager)
            ->test(SiteRedirects::class, ['site' => $site])
            ->set('sourcePath', 'old-page/')
            ->set('targetUrl', 'https://acme.com/new-page')
            ->set('statusCode', 301)
            ->call('addRedirect')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('site_redirects', [
            'site_id' => $site->id,
            'source_path' => '/old-page',
            'target_url' => 'https://acme.com/new-page',
            'status_code' => 301,
        ]);
    }

    public function test_viewer_cannot_add_a_redirect(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(SiteRedirects::class, ['site' => $site])
            ->set('sourcePath', '/x')
            ->set('targetUrl', 'https://acme.com/y')
            ->call('addRedirect')
            ->assertForbidden();

        $this->assertDatabaseCount('site_redirects', 0);
    }
}
