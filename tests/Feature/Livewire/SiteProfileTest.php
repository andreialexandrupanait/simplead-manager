<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteProfile;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\SiteRiskyPlugin;
use App\Models\User;
use App\Services\KeyUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §9's "Profil" — the four per-site settings that cannot be shared.
 *
 * Three of the four had no editor anywhere in the app: key URLs were overwritten
 * on every sync, smoke_canary_selector was read by the smoke check and written by
 * nothing (so it was NULL everywhere and always fell back to '<body'), and the
 * risk list supported a protected source='manual' row that no screen could create.
 */
class SiteProfileTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->site = Site::factory()->create([
            'is_connected' => true,
            'url' => 'https://example.test',
        ]);
    }

    public function test_the_screen_renders(): void
    {
        $this->get(route('sites.profile', $this->site))->assertOk();
    }

    /**
     * The decisive one: without the lock, the next sync erases a hand-picked set
     * within the hour, which is why an editor alone would have been useless.
     */
    public function test_pinned_key_urls_survive_a_later_derivation(): void
    {
        Livewire::test(SiteProfile::class, ['site' => $this->site])
            ->set('keyUrls', "https://example.test/\nhttps://example.test/contact/")
            ->call('saveKeyUrls');

        $this->site->refresh();
        $this->assertNotNull($this->site->key_urls_locked_at);
        $this->assertSame(
            ['https://example.test/', 'https://example.test/contact/'],
            $this->site->key_urls
        );

        // Exactly what a WP sync or a Search Console fetch does.
        app(KeyUrlService::class)->deriveAndStore($this->site->refresh());

        $this->assertSame(
            ['https://example.test/', 'https://example.test/contact/'],
            $this->site->fresh()->key_urls
        );
    }

    public function test_unlocking_hands_the_set_back_to_automatic(): void
    {
        app(KeyUrlService::class)->lock($this->site, ['https://example.test/pinned/']);

        Livewire::test(SiteProfile::class, ['site' => $this->site->refresh()])
            ->call('unlockKeyUrls');

        $this->site->refresh();
        $this->assertNull($this->site->key_urls_locked_at);
        $this->assertSame(['https://example.test/'], $this->site->key_urls);
    }

    /**
     * The recompute is quarterly (§7.5), not "on every sync" — it used to run
     * several times a day and rewrite the set each time.
     */
    public function test_the_recompute_is_quarterly(): void
    {
        $service = app(KeyUrlService::class);

        $service->deriveAndStore($this->site);
        $first = $this->site->fresh()->key_urls_derived_at;

        SearchConsoleCache::create([
            'site_id' => $this->site->id,
            'data_type' => 'pages',
            'date_range' => '28d',
            'start_date' => now()->subDays(28),
            'end_date' => now(),
            'data' => [['page' => 'https://example.test/popular/', 'clicks' => 900]],
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        // A sync an hour later must not recompute.
        $service->refreshIfDue($this->site->refresh());
        $this->assertEquals($first, $this->site->fresh()->key_urls_derived_at);
        $this->assertSame(['https://example.test/'], $this->site->fresh()->key_urls);

        // A quarter later it must.
        $this->site->update(['key_urls_derived_at' => now()->subDays(100)]);
        $service->refreshIfDue($this->site->refresh());

        $this->assertContains('https://example.test/popular/', $this->site->fresh()->key_urls);
    }

    public function test_the_canary_selector_can_finally_be_set(): void
    {
        Livewire::test(SiteProfile::class, ['site' => $this->site])
            ->set('canarySelector', 'footer.site-footer')
            ->set('formTestEmail', 'forms@simplead.ro')
            ->call('saveSmokeSettings');

        $this->site->refresh();
        $this->assertSame('footer.site-footer', $this->site->smoke_canary_selector);
        $this->assertSame('forms@simplead.ro', $this->site->form_test_email);
    }

    public function test_a_plugin_can_be_added_to_the_risk_list_by_hand(): void
    {
        Livewire::test(SiteProfile::class, ['site' => $this->site])
            ->set('newRiskySlug', 'elementor')
            ->set('newRiskyReason', 'Broke the checkout in March')
            ->call('addRiskyPlugin');

        $entry = SiteRiskyPlugin::where('site_id', $this->site->id)->firstOrFail();

        $this->assertSame('elementor', $entry->slug);
        // 'manual' is what protects the row from the automatic populator.
        $this->assertSame('manual', $entry->source);
        $this->assertTrue($entry->is_risky);
    }

    public function test_a_viewer_cannot_change_the_profile(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        $this->actingAs($viewer);

        Livewire::test(SiteProfile::class, ['site' => $site])
            ->set('canarySelector', 'footer')
            ->call('saveSmokeSettings')
            ->assertForbidden();
    }
}
