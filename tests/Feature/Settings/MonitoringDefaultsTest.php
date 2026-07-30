<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Uptime\ConfigureMonitor;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → General → Monitoring Defaults now reaches the monitor form.
 *
 * The three fields wrote `default_interval`, `default_timeout` and
 * `alert_after_failures` to app_settings and nothing ever read them back, so
 * every new monitor got the hard-coded 5/30/3 no matter what was configured.
 */
class MonitoringDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_a_new_monitor_starts_from_the_configured_defaults(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('default_interval', 15, 'monitoring', 'integer');
        $settings->set('default_timeout', 45, 'monitoring', 'integer');
        $settings->set('alert_after_failures', 2, 'monitoring', 'integer');

        $form = Livewire::test(ConfigureMonitor::class)
            ->call('openModal')
            ->instance()->form;

        $this->assertSame(15, $form->interval_minutes);
        $this->assertSame(45, $form->timeout);
        $this->assertSame(2, $form->alert_after_failures);
    }

    public function test_it_falls_back_to_the_built_in_values_when_nothing_is_configured(): void
    {
        $form = Livewire::test(ConfigureMonitor::class)
            ->call('openModal')
            ->instance()->form;

        $this->assertSame(5, $form->interval_minutes);
        $this->assertSame(30, $form->timeout);
        $this->assertSame(3, $form->alert_after_failures);
    }
}
