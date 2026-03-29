<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SiteSearchConsole;
use App\Models\GoogleConnection;
use App\Models\SearchConsoleConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteSearchConsoleTest extends TestCase
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
    public function user_can_view_search_console_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteSearchConsole::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_when_no_google_connection_exists(): void
    {
        $this->assertFalse(GoogleConnection::where('is_active', true)->exists());

        Livewire::actingAs($this->admin)
            ->test(SiteSearchConsole::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── dateRange ────────────────────────────────────────────────────

    #[Test]
    public function date_range_defaults_to_28d(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteSearchConsole::class, ['site' => $this->site]);

        $this->assertEquals('28d', $component->get('dateRange'));
    }

    #[Test]
    public function user_can_change_date_range(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteSearchConsole::class, ['site' => $this->site])
            ->call('setDateRange', '90d');

        $this->assertEquals('90d', $component->get('dateRange'));
    }

    // ─── hasGoogleAccounts ────────────────────────────────────────────

    #[Test]
    public function has_google_accounts_is_false_when_none_exist(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteSearchConsole::class, ['site' => $this->site]);

        $this->assertFalse($component->instance()->hasGoogleAccounts);
    }

    #[Test]
    public function has_google_accounts_is_true_when_active_connection_exists(): void
    {
        GoogleConnection::factory()->create(['is_active' => true]);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteSearchConsole::class, ['site' => $this->site]);

        $this->assertTrue($component->instance()->hasGoogleAccounts);
    }

    // ─── disconnectSearchConsole() ────────────────────────────────────

    #[Test]
    public function user_can_disconnect_search_console(): void
    {
        $googleConn = GoogleConnection::factory()->create(['is_active' => true]);

        SearchConsoleConnection::create([
            'site_id' => $this->site->id,
            'google_connection_id' => $googleConn->id,
            'property_url' => 'https://example.com/',
            'property_type' => 'url',
            'permission_level' => 'siteOwner',
            'is_active' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteSearchConsole::class, ['site' => $this->site])
            ->call('disconnectSearchConsole');

        $this->assertDatabaseMissing('search_console_connections', [
            'site_id' => $this->site->id,
        ]);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_search_console(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SiteSearchConsole::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
