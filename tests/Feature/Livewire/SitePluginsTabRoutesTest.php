<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SitePlugins;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plugins, themes, WordPress core and licences were tabs inside a single screen,
 * so the sidebar could only ever link to the first of them — every click landed
 * you on Plugins and you had to switch tab by hand. Each now has its own route
 * onto the same component.
 */
class SitePluginsTabRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        // Assets are built inside the Docker image, so there is no Vite manifest
        // in a test checkout — a full-page GET would 500 on that alone.
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->site = Site::factory()->create(['is_connected' => true]);
    }

    public static function tabRoutes(): array
    {
        return [
            'plugins' => ['sites.plugins', 'plugins'],
            'themes' => ['sites.themes', 'themes'],
            'wordpress core' => ['sites.core', 'wordpress'],
            'licenses' => ['sites.licenses', 'licenses'],
        ];
    }

    /** @dataProvider tabRoutes */
    public function test_each_route_lands_on_its_own_tab(string $routeName, string $expectedTab): void
    {
        $response = $this->get(route($routeName, $this->site));

        $response->assertOk();

        // The tab is derived from the route name, so the rendered screen must
        // already be on it — no click needed.
        $response->assertSee($expectedTab === 'wordpress' ? 'WordPress' : ucfirst($expectedTab), escape: false);
    }

    public function test_the_embedded_overview_card_is_unaffected(): void
    {
        // The site overview embeds this component without a tab; it must still
        // open on plugins.
        $component = Livewire::test(SitePlugins::class, ['site' => $this->site, 'embedded' => true]);

        $this->assertSame('plugins', $component->get('tab'));
        $this->assertTrue($component->get('embedded'));
    }
}
