<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\Security\SecurityActivity;
use App\Models\SecurityActivityLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-45: the connector does not (yet) emit failed_login events, so the failed-login
 * stats panel rendered permanent, misleading zeros. It must be hidden behind an
 * explicit "not available" notice until real failed_login data exists.
 *
 * The connector-side failed_login logging is DEFERRED to a connector release.
 */
class SecurityActivityFailedLoginPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** @return array{0: Site, 1: User} */
    private function siteWithManager(): array
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        return [$site, $manager];
    }

    public function test_panel_is_hidden_when_no_failed_login_data_exists(): void
    {
        [$site, $manager] = $this->siteWithManager();

        Livewire::actingAs($manager)
            ->test(SecurityActivity::class, ['site' => $site])
            ->assertDontSee('Unique IPs')
            ->assertSee('requires a newer version of the SimpleAd connector plugin');
    }

    public function test_panel_is_shown_once_failed_login_data_exists(): void
    {
        [$site, $manager] = $this->siteWithManager();

        SecurityActivityLog::create([
            'site_id' => $site->id,
            'event_type' => 'failed_login',
            'event_category' => 'user',
            'username' => 'admin',
            'ip_address' => '203.0.113.7',
            'occurred_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);

        Livewire::actingAs($manager)
            ->test(SecurityActivity::class, ['site' => $site])
            ->assertSee('Unique IPs')
            ->assertDontSee('requires a newer version of the SimpleAd connector plugin');
    }
}
