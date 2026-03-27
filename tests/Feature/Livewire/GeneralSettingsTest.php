<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\GeneralSettings;
use App\Models\Site;
use App\Models\SiteStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
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
    public function admin_can_view_general_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(GeneralSettings::class)
            ->assertOk();
    }

    // ─── save() ───────────────────────────────────────────────────────

    #[Test]
    public function admin_can_save_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(GeneralSettings::class)
            ->set('form.appName', 'My Custom Manager')
            ->set('form.defaultTimezone', 'Europe/Bucharest')
            ->set('form.dateFormat', 'Y-m-d')
            ->set('form.defaultInterval', 120)
            ->set('form.defaultTimeout', 60)
            ->set('form.alertAfterFailures', 5)
            ->call('save')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('app_settings', [
            'key' => 'app_name',
            'value' => 'My Custom Manager',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'default_timezone',
            'value' => 'Europe/Bucharest',
        ]);
    }

    // ─── openStatusForm / saveStatus (create) ─────────────────────────

    #[Test]
    public function admin_can_create_site_status(): void
    {
        Livewire::actingAs($this->admin)
            ->test(GeneralSettings::class)
            ->call('openStatusForm')
            ->assertDispatched('open-modal-status-form')
            ->set('statusForm.statusName', 'Under Maintenance')
            ->set('statusForm.statusColor', '#ff0000')
            ->set('statusForm.statusSortOrder', 1)
            ->call('saveStatus')
            ->assertDispatched('close-modal-status-form');

        $this->assertDatabaseHas('site_statuses', [
            'name' => 'Under Maintenance',
            'color' => '#ff0000',
            'sort_order' => 1,
        ]);
    }

    // ─── openStatusForm / saveStatus (update) ─────────────────────────

    #[Test]
    public function admin_can_update_site_status(): void
    {
        $status = SiteStatus::create([
            'name' => 'Old Name',
            'color' => '#cccccc',
            'sort_order' => 5,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GeneralSettings::class)
            ->call('openStatusForm', $status->id)
            ->assertDispatched('open-modal-status-form')
            ->set('statusForm.statusName', 'New Name')
            ->set('statusForm.statusColor', '#123456')
            ->call('saveStatus')
            ->assertDispatched('close-modal-status-form');

        $this->assertDatabaseHas('site_statuses', [
            'id' => $status->id,
            'name' => 'New Name',
            'color' => '#123456',
        ]);
    }

    // ─── deleteStatus ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_site_status_without_sites(): void
    {
        $status = SiteStatus::create([
            'name' => 'Deletable Status',
            'color' => '#aabbcc',
            'sort_order' => 10,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GeneralSettings::class)
            ->call('deleteStatus', $status->id);

        $this->assertDatabaseMissing('site_statuses', ['id' => $status->id]);
    }

    #[Test]
    public function admin_cannot_delete_site_status_with_sites(): void
    {
        $status = SiteStatus::create([
            'name' => 'Active Status',
            'color' => '#001122',
            'sort_order' => 2,
        ]);

        Site::factory()->for($this->admin)->create([
            'site_status_id' => $status->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GeneralSettings::class)
            ->call('deleteStatus', $status->id)
            ->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error');

        $this->assertDatabaseHas('site_statuses', ['id' => $status->id]);
    }

    // ─── Changelog ────────────────────────────────────────────────────

    #[Test]
    public function changelog_displays_from_config(): void
    {
        $changelog = config('connector.changelog');

        // The config must have at least one entry for this test to be meaningful
        $this->assertNotEmpty($changelog);

        // Render the component and assert no errors — the view receives changelog via config()
        Livewire::actingAs($this->admin)
            ->test(GeneralSettings::class)
            ->assertOk();
    }
}
