<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\UserRole;
use App\Jobs\ApplySitePresetJob;
use App\Livewire\Sites\SitesList;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use App\Services\SitePresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §13 — the curated "Standard SimpleAD" preset package.
 */
class SitePresetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_package_applies_the_auto_group_and_marks_the_essential_setting(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $result = app(SitePresetService::class)->applyStandardPackage($site, push: false);

        // 1 site_control (disable_all_updates) + 12 performance = 13 auto settings, no Woo.
        $this->assertSame(13, $result['applied']);
        $this->assertFalse($result['woo']);
        $this->assertDatabaseHas('security_settings', [
            'site_id' => $site->id,
            'category' => 'site_control',
            'setting_key' => 'disable_all_updates',
            'is_enabled' => true,
        ]);
        $this->assertSame(13, SecuritySetting::where('site_id', $site->id)->count());
    }

    public function test_woo_group_is_applied_only_when_woocommerce_is_active(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        SitePlugin::factory()->for($site)->create(['slug' => 'woocommerce', 'is_active' => true, 'name' => 'WooCommerce']);

        $result = app(SitePresetService::class)->applyStandardPackage($site, push: false);

        $this->assertTrue($result['woo']);
        $this->assertSame(14, $result['applied']); // 13 auto + 1 woo
        $this->assertDatabaseHas('security_settings', [
            'site_id' => $site->id,
            'category' => 'performance',
            'setting_key' => 'optimize_woocommerce',
            'is_enabled' => true,
        ]);
    }

    public function test_bulk_apply_presets_fans_out_a_job_per_connected_site(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $connected = Site::factory()->create(['is_connected' => true]);
        $offline = Site::factory()->create(['is_connected' => false]);

        Livewire::test(SitesList::class)
            ->set('selectedSites', [$connected->id, $offline->id])
            ->call('bulkApplyPresets');

        Queue::assertPushed(ApplySitePresetJob::class, 1);
        Queue::assertPushed(fn (ApplySitePresetJob $j) => $j->site->id === $connected->id);
    }
}
