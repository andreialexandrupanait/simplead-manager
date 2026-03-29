<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\Tweaks\TweaksSiteControl;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TweaksSiteControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
        $this->seedSiteControlSettings();
    }

    private function seedSiteControlSettings(): void
    {
        $keys = [
            'disable_all_updates',
            'disable_comments',
            'disable_feeds',
            'disable_embeds',
            'redirect_404',
            'disable_gutenberg',
            'disable_author_archives',
        ];

        foreach ($keys as $key) {
            SecuritySetting::create([
                'site_id' => $this->site->id,
                'category' => 'site_control',
                'setting_key' => $key,
                'is_enabled' => false,
                'setting_value' => null,
            ]);
        }
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_tweaks_site_control_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TweaksSiteControl::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── Initial state ────────────────────────────────────────────────

    #[Test]
    public function component_is_not_dirty_on_mount(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksSiteControl::class, ['site' => $this->site]);

        $this->assertFalse($component->get('isDirty'));
    }

    #[Test]
    public function all_setting_keys_are_present_in_toggles(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksSiteControl::class, ['site' => $this->site]);

        $toggles = $component->get('toggles');

        $expectedKeys = [
            'disable_all_updates',
            'disable_comments',
            'disable_feeds',
            'disable_embeds',
            'redirect_404',
            'disable_gutenberg',
            'disable_author_archives',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $toggles, "Expected toggle key '{$key}' missing.");
        }
    }

    // ─── toggleSetting() ──────────────────────────────────────────────

    #[Test]
    public function toggling_a_setting_marks_component_dirty(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksSiteControl::class, ['site' => $this->site])
            ->call('toggleSetting', 'disable_comments');

        $this->assertTrue($component->get('isDirty'));
    }

    #[Test]
    public function toggling_setting_flips_boolean_value(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksSiteControl::class, ['site' => $this->site]);

        $initial = $component->get('toggles')['disable_comments'] ?? false;

        $component->call('toggleSetting', 'disable_comments');

        $this->assertEquals(! $initial, $component->get('toggles')['disable_comments']);
    }

    #[Test]
    public function toggling_unknown_key_does_not_add_it_to_toggles(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksSiteControl::class, ['site' => $this->site])
            ->call('toggleSetting', 'nonexistent_key');

        $this->assertArrayNotHasKey('nonexistent_key', $component->get('toggles'));
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_tweaks_site_control(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(TweaksSiteControl::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
