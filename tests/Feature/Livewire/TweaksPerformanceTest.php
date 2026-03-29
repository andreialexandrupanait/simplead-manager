<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\Tweaks\TweaksPerformance;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TweaksPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
        $this->seedPerformanceSettings();
    }

    private function seedPerformanceSettings(): void
    {
        $keys = [
            'disable_generator_tag',
            'disable_wlw_manifest',
            'disable_rsd_link',
            'disable_shortlinks',
            'disable_emojis',
            'disable_dashicons',
            'disable_jquery_migrate',
            'disable_lazy_load',
            'disable_block_widgets',
            'heartbeat_control',
            'revisions_control',
            'image_upload_control',
        ];

        foreach ($keys as $key) {
            SecuritySetting::create([
                'site_id' => $this->site->id,
                'category' => 'performance',
                'setting_key' => $key,
                'is_enabled' => false,
                'setting_value' => null,
            ]);
        }
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_tweaks_performance_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TweaksPerformance::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── Initial state ────────────────────────────────────────────────

    #[Test]
    public function component_is_not_dirty_on_mount(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksPerformance::class, ['site' => $this->site]);

        $this->assertFalse($component->get('isDirty'));
    }

    #[Test]
    public function toggles_are_loaded_as_array_on_mount(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksPerformance::class, ['site' => $this->site]);

        $this->assertIsArray($component->get('toggles'));
    }

    // ─── toggleSetting() ──────────────────────────────────────────────

    #[Test]
    public function toggling_a_setting_marks_component_dirty(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksPerformance::class, ['site' => $this->site])
            ->call('toggleSetting', 'disable_emojis');

        $this->assertTrue($component->get('isDirty'));
    }

    #[Test]
    public function toggling_setting_flips_boolean_value(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksPerformance::class, ['site' => $this->site]);

        $initial = $component->get('toggles')['disable_emojis'] ?? false;

        $component->call('toggleSetting', 'disable_emojis');

        $this->assertEquals(! $initial, $component->get('toggles')['disable_emojis']);
    }

    // ─── enableRecommended() ──────────────────────────────────────────

    #[Test]
    public function enable_recommended_marks_all_recommended_toggles_true(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksPerformance::class, ['site' => $this->site])
            ->call('enableRecommended');

        $toggles = $component->get('toggles');

        foreach (TweaksPerformance::RECOMMENDED_TOGGLES as $key) {
            $this->assertTrue($toggles[$key] ?? false, "Expected toggle '{$key}' to be true after enableRecommended()");
        }
    }

    #[Test]
    public function enable_recommended_marks_component_dirty(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(TweaksPerformance::class, ['site' => $this->site])
            ->call('enableRecommended');

        $this->assertTrue($component->get('isDirty'));
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_tweaks_performance(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(TweaksPerformance::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
