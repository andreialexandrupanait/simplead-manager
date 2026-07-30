<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SitePresets;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §13 — the curated ten. Until now the only tweak surface was the
 * connector's full ~55-switch catalogue across four tabs; the spec's central
 * §13 decision (surface ten, keep the rest in code) had no screen.
 */
class SitePresetsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->site = Site::factory()->create(['is_connected' => true]);

        // Saving a preset pushes it to the connector in-band; no test here is
        // about the network.
        Http::fake(['*' => Http::response(['success' => true], 200)]);
    }

    public function test_the_screen_shows_exactly_the_package_settings(): void
    {
        $package = (array) config('site_presets.standard_simplead');

        $expected = 0;
        foreach (['auto', 'woo'] as $bucket) {
            foreach ((array) ($package[$bucket] ?? []) as $settings) {
                $expected += count($settings);
            }
        }

        $groups = Livewire::test(SitePresets::class, ['site' => $this->site])->instance()->getGroupsProperty();

        $shown = array_sum(array_map('count', $groups));

        $this->assertSame($expected, $shown, 'The screen must show the package, no more and no less.');
        $this->assertLessThan(20, $shown, 'This is the curated surface, not the ~55-switch catalogue.');
    }

    public function test_the_essential_setting_is_marked_and_cannot_be_turned_off(): void
    {
        $component = Livewire::test(SitePresets::class, ['site' => $this->site]);

        // Turn it on first (a fresh site has nothing stored).
        $component->call('toggleSetting', 'disable_all_updates');
        $this->assertTrue($component->get('toggles')['disable_all_updates']);

        // Now it refuses to go back off — without it WordPress updates itself.
        $component->call('toggleSetting', 'disable_all_updates');
        $this->assertTrue(
            $component->get('toggles')['disable_all_updates'],
            'Turning off the essential setting would let the site self-update.'
        );

        $essential = collect($component->instance()->getGroupsProperty())
            ->flatten(1)
            ->firstWhere('key', 'disable_all_updates');
        $this->assertTrue($essential['essential']);
    }

    public function test_saving_writes_the_settings_under_their_own_categories(): void
    {
        Livewire::test(SitePresets::class, ['site' => $this->site])
            ->call('toggleSetting', 'disable_emojis')
            ->call('toggleSetting', 'disable_all_updates')
            ->call('save');

        // Head cleanup lives under 'performance', update control under 'site_control' —
        // the screen groups by decision, the storage still respects the connector's
        // categories.
        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'performance',
            'setting_key' => 'disable_emojis',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'category' => 'site_control',
            'setting_key' => 'disable_all_updates',
            'is_enabled' => true,
        ]);
    }

    public function test_stored_state_is_reflected_when_the_screen_opens(): void
    {
        SecuritySetting::create([
            'site_id' => $this->site->id,
            'category' => 'performance',
            'setting_key' => 'heartbeat_control',
            'setting_value' => true,
            'is_enabled' => true,
        ]);

        $toggles = Livewire::test(SitePresets::class, ['site' => $this->site])->get('toggles');

        $this->assertTrue($toggles['heartbeat_control']);
        $this->assertFalse($toggles['disable_emojis'], 'Untouched settings read as off, not as on.');
    }

    public function test_applying_the_package_enables_the_essential_setting(): void
    {
        Livewire::test(SitePresets::class, ['site' => $this->site])->call('applyStandardPackage');

        $this->assertDatabaseHas('security_settings', [
            'site_id' => $this->site->id,
            'setting_key' => 'disable_all_updates',
            'is_enabled' => true,
        ]);
    }

    public function test_the_full_catalogue_is_still_reachable(): void
    {
        // §13 says the rest "dispar doar din interfață" — they stay in the code and
        // one link away, not deleted.
        Livewire::test(SitePresets::class, ['site' => $this->site])
            ->assertSee(route('sites.tweaks.performance', $this->site), escape: false);
    }
}
