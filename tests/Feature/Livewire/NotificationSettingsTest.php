<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\NotificationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
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
    public function admin_can_view_notification_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(NotificationSettings::class)
            ->assertOk();
    }

    #[Test]
    public function component_loads_default_preferences(): void
    {
        Livewire::actingAs($this->admin)
            ->test(NotificationSettings::class)
            ->assertSet('notifyDown', true)
            ->assertSet('notifyRecovery', true)
            ->assertSet('notifyDegraded', false);
    }

    #[Test]
    public function admin_can_save_notification_preferences(): void
    {
        Livewire::actingAs($this->admin)
            ->test(NotificationSettings::class)
            ->set('notifyDown', true)
            ->set('notifyRecovery', false)
            ->set('notifyDegraded', true)
            ->call('savePreferences')
            ->assertDispatched('notify');
    }

    #[Test]
    public function admin_can_configure_quiet_hours(): void
    {
        Livewire::actingAs($this->admin)
            ->test(NotificationSettings::class)
            ->set('quietHoursEnabled', true)
            ->set('quietHoursStart', '23:00')
            ->set('quietHoursEnd', '06:00')
            ->call('savePreferences')
            ->assertDispatched('notify');
    }
}
