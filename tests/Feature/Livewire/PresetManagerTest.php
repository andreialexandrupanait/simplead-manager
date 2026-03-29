<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Security\PresetManager;
use App\Models\SecurityPreset;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PresetManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_preset_manager(): void
    {
        SecurityPreset::create([
            'name' => 'Strict Security',
            'settings' => [],
            'is_default' => false,
            'version' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->assertOk();
    }

    // ─── savePreset() — create ────────────────────────────────────────

    #[Test]
    public function admin_can_create_security_preset(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->set('presetName', 'Hardened WordPress')
            ->set('presetDescription', 'A recommended hardened configuration.')
            ->set('isDefault', false)
            ->call('savePreset');

        $this->assertDatabaseHas('security_presets', [
            'name' => 'Hardened WordPress',
            'description' => 'A recommended hardened configuration.',
        ]);
    }

    #[Test]
    public function creating_preset_resets_form_state(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->set('presetName', 'Test Preset')
            ->set('presetDescription', 'A description.')
            ->call('savePreset');

        $component->assertSet('showForm', false);
        $component->assertSet('presetName', '');
        $component->assertSet('editingId', null);
    }

    // ─── savePreset() — update ────────────────────────────────────────

    #[Test]
    public function admin_can_update_existing_preset(): void
    {
        $preset = SecurityPreset::create([
            'name' => 'Original Name',
            'settings' => [],
            'is_default' => false,
            'version' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->call('editPreset', $preset->id)
            ->assertSet('editingId', $preset->id)
            ->assertSet('presetName', 'Original Name')
            ->set('presetName', 'Updated Name')
            ->set('presetDescription', 'Updated description')
            ->call('savePreset');

        $this->assertDatabaseHas('security_presets', [
            'id' => $preset->id,
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);
    }

    // ─── deletePreset() ───────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_preset(): void
    {
        $preset = SecurityPreset::create([
            'name' => 'To Delete',
            'settings' => [],
            'is_default' => false,
            'version' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->call('deletePreset', $preset->id);

        $this->assertDatabaseMissing('security_presets', ['id' => $preset->id]);
    }

    // ─── applyToSites() ───────────────────────────────────────────────

    #[Test]
    public function admin_can_apply_preset_to_sites(): void
    {
        Queue::fake();

        $preset = SecurityPreset::create([
            'name' => 'Standard',
            'settings' => [],
            'is_default' => false,
            'version' => 1,
        ]);

        $site = Site::factory()->for($this->admin)->create();

        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->call('startApply', $preset->id)
            ->assertSet('applyingPresetId', $preset->id)
            ->set('applySiteIds', [$site->id])
            ->call('applyToSites');

        // Pivot entry must be created
        $this->assertDatabaseHas('security_preset_site', [
            'security_preset_id' => $preset->id,
            'site_id' => $site->id,
        ]);
    }

    #[Test]
    public function apply_to_sites_does_nothing_when_no_sites_selected(): void
    {
        $preset = SecurityPreset::create([
            'name' => 'Standard',
            'settings' => [],
            'is_default' => false,
            'version' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->call('startApply', $preset->id)
            ->set('applySiteIds', [])
            ->call('applyToSites')
            ->assertSet('applyingPresetId', $preset->id); // not cancelled — call was a no-op
    }

    #[Test]
    public function cancel_apply_clears_state(): void
    {
        $preset = SecurityPreset::create([
            'name' => 'Standard',
            'settings' => [],
            'is_default' => false,
            'version' => 1,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->call('startApply', $preset->id)
            ->call('cancelApply')
            ->assertSet('applyingPresetId', null)
            ->assertSet('applySiteIds', []);
    }

    // ─── createFromSite() ─────────────────────────────────────────────

    #[Test]
    public function admin_can_create_preset_from_site_security_settings(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        SecuritySetting::create([
            'site_id' => $site->id,
            'category' => 'hardening',
            'setting_key' => 'disable_theme_editor',
            'setting_value' => [],
            'is_enabled' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->set('snapshotSiteId', $site->id)
            ->set('snapshotName', 'Site Snapshot Preset')
            ->call('createFromSite');

        $this->assertDatabaseHas('security_presets', [
            'name' => 'Site Snapshot Preset',
        ]);
    }

    // ─── Validation ───────────────────────────────────────────────────

    #[Test]
    public function save_preset_requires_name(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->set('presetName', '')
            ->call('savePreset')
            ->assertHasErrors(['presetName' => 'required']);
    }

    #[Test]
    public function create_from_site_requires_site_id_and_name(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PresetManager::class)
            ->set('snapshotSiteId', null)
            ->set('snapshotName', '')
            ->call('createFromSite')
            ->assertHasErrors(['snapshotSiteId', 'snapshotName']);
    }
}
